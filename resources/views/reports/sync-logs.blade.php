@extends('layouts.app')

@section('title', 'Senkron Logları')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Senkron Logları</h1>
            <p class="eticart-muted mb-0">Job çalıştırma geçmişi ve hatalar. 1 haftadan eski kayıtlar girişte otomatik silinir.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <form id="syncLogsPurgeAllForm" method="POST" action="{{ route('reports.sync-logs.purge-all') }}" class="d-inline">
                @csrf
                <button type="button" class="btn btn-outline-danger btn-sm" id="syncLogsPurgeAllBtn">
                    <i class="bi bi-trash me-1"></i> Tüm kayıtları sil
                </button>
            </form>
            <a href="{{ route('reports.index') }}" class="btn btn-outline-secondary btn-sm">Geri</a>
        </div>
    </div>

    <div class="eticart-card p-3 mb-3">
        <form method="GET" action="{{ route('reports.sync-logs') }}" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Başlangıç</label>
                <input type="date" name="from" value="{{ $dateFrom }}" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Bitiş</label>
                <input type="date" name="to" value="{{ $dateTo }}" class="form-control" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Tip</label>
                <select name="type" class="form-select">
                    <option value="">Tümü</option>
                    @foreach (['order_sync' => 'Sipariş', 'uyumsoft_order_sync' => 'UyumSoft sipariş', 'product_sync' => 'Ürün', 'stock_sync' => 'Stok', 'cargo_tracking' => 'Kargo'] as $value => $label)
                        <option value="{{ $value }}" @selected(($report['filters']['type'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Durum</label>
                <select name="status" class="form-select">
                    <option value="">Tümü</option>
                    <option value="success" @selected(($report['filters']['status'] ?? '') === 'success')>Success</option>
                    <option value="partial" @selected(($report['filters']['status'] ?? '') === 'partial')>Partial</option>
                    <option value="failed" @selected(($report['filters']['status'] ?? '') === 'failed')>Failed</option>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100" type="submit">Filtrele</button>
            </div>
        </form>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md">
            <div class="eticart-stat-card">
                <div class="eticart-muted small">Toplam</div>
                <div class="fs-4 fw-semibold">{{ number_format($report['summary']['total']) }}</div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="eticart-stat-card">
                <div class="eticart-muted small">Başarılı</div>
                <div class="fs-4 fw-semibold text-success">{{ number_format($report['summary']['success']) }}</div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="eticart-stat-card">
                <div class="eticart-muted small">Başarısız</div>
                <div class="fs-4 fw-semibold text-danger">{{ number_format($report['summary']['failed']) }}</div>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="eticart-stat-card">
                <div class="eticart-muted small">Senkron</div>
                <div class="fs-4 fw-semibold">{{ number_format($report['summary']['synced']) }}</div>
            </div>
        </div>
    </div>

    @if ($report['logs']->isEmpty())
        <x-empty-state title="Log yok" message="Seçilen filtrelerde senkron kaydı bulunamadı." icon="bi-arrow-repeat" />
    @else
        <form method="POST" action="{{ route('reports.sync-logs.destroy') }}" id="syncLogsBulkForm"
              onsubmit="return confirm('Seçili / filtrelenen senkron logları silinsin mi?');">
            @csrf
            <input type="hidden" name="from" value="{{ $dateFrom }}">
            <input type="hidden" name="to" value="{{ $dateTo }}">
            <input type="hidden" name="type" value="{{ $report['filters']['type'] ?? '' }}">
            <input type="hidden" name="status" value="{{ $report['filters']['status'] ?? '' }}">

            <div class="d-flex flex-wrap gap-2 mb-2">
                <button type="submit" class="btn btn-outline-danger btn-sm" name="delete_all_filtered" value="0">
                    <i class="bi bi-trash me-1"></i> Seçilenleri sil
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="syncLogsSelectPage">Sayfadakileri seç</button>
                <button type="submit" class="btn btn-outline-danger btn-sm"
                        name="delete_all_filtered" value="1"
                        onclick="return confirm('Filtrelere uyan TÜM senkron logları silinsin mi?');">
                    Filtrelenenleri sil
                </button>
            </div>

            <x-table :headers="['', 'Zaman', 'Tip', 'Durum', 'Mesaj', 'Senkron', 'Hata', 'Süre']">
                @foreach ($report['logs'] as $log)
                    <tr>
                        <td>
                            <input type="checkbox" class="form-check-input sync-log-id" name="ids[]" value="{{ $log->id }}">
                        </td>
                        <td class="text-nowrap">{{ optional($log->created_at)->format('d.m.Y H:i') }}</td>
                        <td>{{ $log->syncJob?->job_type ?? '-' }}</td>
                        <td>
                            <x-badge type="{{ $log->status === 'success' ? 'success' : ($log->status === 'partial' ? 'warning' : 'danger') }}">
                                {{ $log->status }}
                            </x-badge>
                        </td>
                        <td>{{ \Illuminate\Support\Str::limit($log->message ?? $log->error ?? '-', 60) }}</td>
                        <td>{{ $log->synced_count }}</td>
                        <td>{{ $log->error_count }}</td>
                        <td>{{ $log->duration ? $log->duration.'s' : '-' }}</td>
                    </tr>
                @endforeach
            </x-table>
        </form>
        <div class="mt-3">{{ $report['logs']->links() }}</div>
    @endif
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.getElementById('syncLogsSelectPage')?.addEventListener('click', () => {
    document.querySelectorAll('.sync-log-id').forEach((el) => { el.checked = true; });
});

document.getElementById('syncLogsPurgeAllBtn')?.addEventListener('click', async () => {
    const result = await Swal.fire({
        title: 'Emin misiniz?',
        text: 'Senkron loglarındaki tüm kayıtlar kalıcı olarak silinecek.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Evet, tümünü sil',
        cancelButtonText: 'Vazgeç',
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
    });

    if (result.isConfirmed) {
        document.getElementById('syncLogsPurgeAllForm')?.submit();
    }
});
</script>
@endpush
