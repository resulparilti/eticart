<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Shipment;
use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderItem;
use App\Models\SyncJobLog;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\File;

class ReportService
{
    /**
     * Sales report between dates.
     *
     * @return array<string, mixed>
     */
    public function getSalesReport(string $dateFrom, string $dateTo): array
    {
        [$from, $to] = $this->dateRange($dateFrom, $dateTo);

        $orders = ShopifyOrder::query()
            ->whereBetween('shopify_created_at', [$from, $to])
            ->orderBy('shopify_created_at')
            ->get();

        $daily = $this->emptyDailySeries($from, $to);

        foreach ($orders as $order) {
            $key = optional($order->shopify_created_at)?->format('Y-m-d')
                ?? optional($order->created_at)?->format('Y-m-d');

            if ($key === null || ! isset($daily[$key])) {
                continue;
            }

            $daily[$key]['orders']++;
            $daily[$key]['revenue'] += (float) $order->total_price;
        }

        $rows = array_values($daily);

        return [
            'from' => $from,
            'to' => $to,
            'summary' => [
                'orders' => $orders->count(),
                'revenue' => round((float) $orders->sum('total_price'), 2),
                'avg_order' => $orders->count() > 0
                    ? round((float) $orders->avg('total_price'), 2)
                    : 0.0,
            ],
            'daily' => $rows,
            'chart' => [
                'labels' => array_column($rows, 'date'),
                'orders' => array_column($rows, 'orders'),
                'revenue' => array_map(static fn ($v) => round((float) $v, 2), array_column($rows, 'revenue')),
            ],
            'orders' => $orders,
        ];
    }

    /**
     * Product sales report.
     *
     * @return array<string, mixed>
     */
    public function getProductReport(string $dateFrom, string $dateTo): array
    {
        [$from, $to] = $this->dateRange($dateFrom, $dateTo);

        $items = ShopifyOrderItem::query()
            ->selectRaw('sku, product_title, variant_title, SUM(quantity) as total_qty, SUM(quantity * price) as total_revenue')
            ->whereHas('order', function ($query) use ($from, $to) {
                $query->whereBetween('shopify_created_at', [$from, $to]);
            })
            ->groupBy('sku', 'product_title', 'variant_title')
            ->orderByDesc('total_qty')
            ->limit(100)
            ->get();

        return [
            'from' => $from,
            'to' => $to,
            'items' => $items,
            'summary' => [
                'skus' => $items->count(),
                'quantity' => (int) $items->sum('total_qty'),
                'revenue' => round((float) $items->sum('total_revenue'), 2),
            ],
        ];
    }

    /**
     * Sync job logs report.
     *
     * @return array<string, mixed>
     */
    public function getSyncReport(string $dateFrom, string $dateTo, ?string $type = null, ?string $status = null): array
    {
        [$from, $to] = $this->dateRange($dateFrom, $dateTo);

        $query = $this->filteredSyncLogsQuery($dateFrom, $dateTo, $type, $status)->latest();
        $logs = (clone $query)->paginate(30)->withQueryString();

        $totals = SyncJobLog::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
                SUM(CASE WHEN status != 'success' THEN 1 ELSE 0 END) as failed_count,
                COALESCE(SUM(synced_count), 0) as synced_total,
                COALESCE(SUM(error_count), 0) as error_total
            ")
            ->first();

        return [
            'from' => $from,
            'to' => $to,
            'logs' => $logs,
            'summary' => [
                'total' => (int) ($totals->total ?? 0),
                'success' => (int) ($totals->success_count ?? 0),
                'failed' => (int) ($totals->failed_count ?? 0),
                'synced' => (int) ($totals->synced_total ?? 0),
                'errors' => (int) ($totals->error_total ?? 0),
            ],
            'filters' => [
                'type' => $type,
                'status' => $status,
            ],
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\SyncJobLog>
     */
    public function filteredSyncLogsQuery(string $dateFrom, string $dateTo, ?string $type = null, ?string $status = null)
    {
        [$from, $to] = $this->dateRange($dateFrom, $dateTo);

        $query = SyncJobLog::query()
            ->with('syncJob')
            ->whereBetween('created_at', [$from, $to]);

        if ($type) {
            $query->whereHas('syncJob', fn ($q) => $q->where('job_type', $type));
        }

        if ($status) {
            $query->where('status', $status);
        }

        return $query;
    }

    /**
     * Shipment report.
     *
     * @return array<string, mixed>
     */
    public function getShipmentReport(string $dateFrom, string $dateTo): array
    {
        [$from, $to] = $this->dateRange($dateFrom, $dateTo);

        $shipments = Shipment::query()
            ->with('cargoCompany')
            ->whereBetween('created_at', [$from, $to])
            ->latest()
            ->get();

        $byStatus = $shipments->groupBy('status')->map->count();
        $byCompany = $shipments
            ->groupBy(fn (Shipment $s) => $s->cargoCompany?->name ?? 'Bilinmiyor')
            ->map->count();

        return [
            'from' => $from,
            'to' => $to,
            'shipments' => $shipments,
            'summary' => [
                'total' => $shipments->count(),
                'delivered' => (int) ($byStatus[Shipment::STATUS_DELIVERED] ?? 0),
                'shipped' => (int) ($byStatus[Shipment::STATUS_SHIPPED] ?? 0),
                'pending' => (int) ($byStatus[Shipment::STATUS_PENDING] ?? 0),
                'cargo_cost' => round((float) $shipments->sum('cargo_cost'), 2),
            ],
            'by_status' => $byStatus,
            'by_company' => $byCompany,
        ];
    }

    /**
     * Recent system / error log lines.
     *
     * @return array<string, mixed>
     */
    public function getSystemLogs(int $limit = 100): array
    {
        $laravelPath = storage_path('logs/laravel.log');
        $cronPath = storage_path('logs/cron.log');
        $lines = [];

        if (File::exists($laravelPath)) {
            $content = File::get($laravelPath);
            $chunks = preg_split('/(?=\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\])/', $content) ?: [];
            $chunks = array_values(array_filter(array_map('trim', $chunks)));
            $recent = array_slice($chunks, -$limit);

            foreach (array_reverse($recent) as $chunk) {
                $level = 'info';
                if (stripos($chunk, '.ERROR:') !== false || stripos($chunk, 'local.ERROR') !== false) {
                    $level = 'error';
                } elseif (stripos($chunk, '.WARNING:') !== false) {
                    $level = 'warning';
                }

                $lines[] = [
                    'level' => $level,
                    'preview' => mb_substr(preg_replace('/\s+/', ' ', $chunk) ?? $chunk, 0, 240),
                    'body' => $chunk,
                ];
            }
        }

        $cronContent = '';
        $cronExists = File::exists($cronPath);
        if ($cronExists) {
            $raw = File::get($cronPath);
            // Son ~80 KB / son satırlar
            if (strlen($raw) > 80000) {
                $raw = substr($raw, -80000);
            }
            $cronLines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
            $cronContent = implode("\n", array_slice($cronLines, -400));
        }

        $failedSyncs = SyncJobLog::query()
            ->with('syncJob')
            ->where(function ($q) {
                $q->where('status', '!=', 'success')
                    ->orWhere('error_count', '>', 0);
            })
            ->latest()
            ->limit(20)
            ->get();

        return [
            'log_path' => $laravelPath,
            'log_exists' => File::exists($laravelPath),
            'lines' => $lines,
            'cron_path' => $cronPath,
            'cron_exists' => $cronExists,
            'cron_content' => $cronContent,
            'failed_syncs' => $failedSyncs,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dateRange(string $dateFrom, string $dateTo): array
    {
        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->endOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    /**
     * @return array<string, array{date: string, orders: int, revenue: float}>
     */
    private function emptyDailySeries(Carbon $from, Carbon $to): array
    {
        $series = [];

        foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $day) {
            $key = $day->format('Y-m-d');
            $series[$key] = [
                'date' => $key,
                'orders' => 0,
                'revenue' => 0.0,
            ];
        }

        return $series;
    }
}
