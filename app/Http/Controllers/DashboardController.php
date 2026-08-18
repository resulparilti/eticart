<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Shipment;
use App\Models\ShopifyOrder;
use App\Models\ShopifyProduct;
use App\Models\SyncJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Display the admin dashboard.
     */
    public function index(Request $request): View
    {
        $stats = [
            'orders' => ShopifyOrder::query()->count(),
            'products' => ShopifyProduct::query()->count(),
            'revenue' => (float) ShopifyOrder::query()->sum('total_price'),
            'shipments' => Shipment::query()->count(),
        ];

        $recentOrders = ShopifyOrder::query()
            ->latest()
            ->limit(5)
            ->get();

        $syncStatus = SyncJob::query()
            ->orderBy('job_type')
            ->get()
            ->map(function (SyncJob $job) {
                return [
                    'name' => match ($job->job_type) {
                        'order_sync' => 'Shopify Sipariş',
                        'product_sync' => 'UyumSoft Ürün',
                        'stock_sync' => 'Stok Sync',
                        'cargo_tracking' => 'Kargo Tracking',
                        default => $job->job_type,
                    },
                    'status' => $job->status,
                    'last_run' => optional($job->last_run)?->format('d.m.Y H:i'),
                ];
            })
            ->all();

        if ($syncStatus === []) {
            $syncStatus = [
                ['name' => 'Shopify Sipariş', 'status' => 'pending', 'last_run' => null],
                ['name' => 'UyumSoft Ürün', 'status' => 'pending', 'last_run' => null],
                ['name' => 'Stok Sync', 'status' => 'pending', 'last_run' => null],
                ['name' => 'Kargo Tracking', 'status' => 'pending', 'last_run' => null],
            ];
        }

        $systemHealth = [
            'database' => $this->databaseHealthy(),
            'queue' => in_array(config('queue.default'), ['database', 'sync', 'redis'], true),
            'cache' => true,
            'mail' => config('mail.default') === 'log' || filled(config('mail.mailers.smtp.host')),
        ];

        return view('dashboard', [
            'stats' => $stats,
            'recentOrders' => $recentOrders,
            'syncStatus' => $syncStatus,
            'systemHealth' => $systemHealth,
            'breadcrumbs' => [
                ['label' => 'Dashboard'],
            ],
        ]);
    }

    /**
     * Check database connectivity.
     */
    private function databaseHealthy(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
