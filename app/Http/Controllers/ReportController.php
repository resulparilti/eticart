<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SyncJobLog;
use App\Services\LogRetentionService;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    /**
     * Reports hub.
     */
    public function index(): View
    {
        return view('reports.index', [
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Raporlar'],
            ],
        ]);
    }

    /**
     * Sales report page.
     */
    public function sales(Request $request): View
    {
        [$from, $to] = $this->dates($request);
        $report = $this->reports->getSalesReport($from, $to);
        $products = $this->reports->getProductReport($from, $to);

        return view('reports.sales', [
            'report' => $report,
            'products' => $products,
            'dateFrom' => $from,
            'dateTo' => $to,
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Raporlar', 'url' => route('reports.index')],
                ['label' => 'Satış'],
            ],
        ]);
    }

    /**
     * Sync logs report.
     */
    public function syncLogs(Request $request): View
    {
        [$from, $to] = $this->dates($request);
        $report = $this->reports->getSyncReport(
            $from,
            $to,
            $request->string('type')->toString() ?: null,
            $request->string('status')->toString() ?: null
        );

        return view('reports.sync-logs', [
            'report' => $report,
            'dateFrom' => $from,
            'dateTo' => $to,
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Raporlar', 'url' => route('reports.index')],
                ['label' => 'Senkron Logları'],
            ],
        ]);
    }

    /**
     * System / error logs.
     */
    public function systemLogs(): View
    {
        $report = $this->reports->getSystemLogs();

        return view('reports.system-logs', [
            'report' => $report,
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Raporlar', 'url' => route('reports.index')],
                ['label' => 'Sistem Logları'],
            ],
        ]);
    }

    /**
     * Senkron job loglarını toplu sil.
     */
    public function destroySyncLogs(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
            'delete_all_filtered' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'type' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ]);

        $query = SyncJobLog::query()->with('syncJob');

        if ($request->boolean('delete_all_filtered')) {
            [$from, $to] = $this->dates($request);
            $report = $this->reports->filteredSyncLogsQuery(
                $from,
                $to,
                $request->string('type')->toString() ?: null,
                $request->string('status')->toString() ?: null
            );
            $count = (clone $report)->count();
            $report->delete();
        } else {
            $ids = $validated['ids'] ?? [];
            if ($ids === []) {
                return back()->with('error', 'Silinecek kayıt seçin.');
            }
            $count = SyncJobLog::query()->whereIn('id', $ids)->delete();
        }

        return redirect()
            ->route('reports.sync-logs', $request->only(['from', 'to', 'type', 'status']))
            ->with('success', "{$count} senkron log kaydı silindi.");
    }

    /**
     * Senkron loglarındaki tüm kayıtları sil.
     */
    public function purgeAllSyncLogs(LogRetentionService $retention): RedirectResponse
    {
        try {
            $count = $retention->purgeAllSyncJobLogs();
        } catch (\Throwable $e) {
            return redirect()
                ->route('reports.sync-logs')
                ->with('error', 'Kayıtlar silinemedi: '.$e->getMessage());
        }

        return redirect()
            ->route('reports.sync-logs')
            ->with('success', $count > 0
                ? "{$count} senkron log kaydı silindi."
                : 'Silinecek senkron log kaydı yoktu.');
    }

    /**
     * Sistem loglarındaki başarısız senkron kayıtlarını sil.
     */
    public function purgeFailedSyncLogs(LogRetentionService $retention): RedirectResponse
    {
        try {
            $count = $retention->purgeFailedSyncJobLogs();
        } catch (\Throwable $e) {
            return redirect()
                ->route('reports.system-logs')
                ->with('error', 'Kayıtlar silinemedi: '.$e->getMessage());
        }

        return redirect()
            ->route('reports.system-logs')
            ->with('success', $count > 0
                ? "{$count} başarısız senkron kaydı silindi."
                : 'Silinecek başarısız senkron kaydı yoktu.');
    }

    /**
     * Shipment report.
     */
    public function shipments(Request $request): View
    {
        [$from, $to] = $this->dates($request);
        $report = $this->reports->getShipmentReport($from, $to);

        return view('reports.shipments', [
            'report' => $report,
            'dateFrom' => $from,
            'dateTo' => $to,
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Raporlar', 'url' => route('reports.index')],
                ['label' => 'Kargo'],
            ],
        ]);
    }

    /**
     * Export sales as CSV.
     */
    public function exportSalesCsv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->dates($request);
        $report = $this->reports->getSalesReport($from, $to);

        $filename = "sales-{$from}-{$to}.csv";

        return ResponseFacade::streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Tarih', 'Sipariş', 'Ciro']);
            foreach ($report['daily'] as $row) {
                fputcsv($out, [$row['date'], $row['orders'], number_format($row['revenue'], 2, '.', '')]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Export sales as PDF.
     */
    public function exportSalesPdf(Request $request): Response
    {
        [$from, $to] = $this->dates($request);
        $report = $this->reports->getSalesReport($from, $to);

        $pdf = Pdf::loadView('reports.pdf.sales', [
            'report' => $report,
            'dateFrom' => $from,
            'dateTo' => $to,
        ]);

        return $pdf->download("sales-{$from}-{$to}.pdf");
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function dates(Request $request): array
    {
        $from = $request->input('from', now()->subDays(29)->toDateString());
        $to = $request->input('to', now()->toDateString());

        return [(string) $from, (string) $to];
    }
}
