@extends('layouts.app')

@section('title', 'Sistem Logları')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Sistem Logları</h1>
            <p class="eticart-muted mb-0">laravel.log, cron.log ve başarısız senkron özeti. Log dosyaları haftalık otomatik temizlenir.</p>
        </div>
        <a href="{{ route('reports.index') }}" class="btn btn-outline-secondary btn-sm">Geri</a>
    </div>

    <div class="eticart-card p-3 mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h2 class="h6 mb-0">Son Başarısız Senkronlar</h2>
            @if ($report['failed_syncs']->isNotEmpty())
                <form id="failedSyncsPurgeForm" method="POST" action="{{ route('reports.system-logs.purge-failed') }}">
                    @csrf
                    <button type="button" class="btn btn-outline-danger btn-sm" id="failedSyncsPurgeBtn">
                        <i class="bi bi-trash me-1"></i> Tüm kayıtları sil
                    </button>
                </form>
            @endif
        </div>
        @if ($report['failed_syncs']->isEmpty())
            <p class="eticart-muted mb-0">Kayıt yok.</p>
        @else
            <ul class="list-unstyled mb-0">
                @foreach ($report['failed_syncs'] as $log)
                    <li class="border-bottom py-2">
                        <div class="d-flex justify-content-between gap-2">
                            <span class="fw-semibold">{{ $log->syncJob?->job_type ?? 'job' }}</span>
                            <span class="small eticart-muted">{{ optional($log->created_at)->format('d.m H:i') }}</span>
                        </div>
                        <div class="small text-danger">{{ \Illuminate\Support\Str::limit($log->error ?? $log->message ?? 'Hata', 120) }}</div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="accordion" id="systemLogsAccordion">
        <div class="accordion-item eticart-card mb-3 border-0">
            <h2 class="accordion-header" id="headingLaravel">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseLaravel" aria-expanded="true" aria-controls="collapseLaravel">
                    laravel.log (son kayıtlar)
                    <span class="ms-2 small eticart-muted">{{ $report['log_exists'] ? count($report['lines']).' chunk' : 'dosya yok' }}</span>
                </button>
            </h2>
            <div id="collapseLaravel" class="accordion-collapse collapse show" aria-labelledby="headingLaravel" data-bs-parent="#systemLogsAccordion">
                <div class="accordion-body">
                    @unless ($report['log_exists'])
                        <p class="eticart-muted mb-0">Log dosyası henüz oluşmamış.</p>
                    @else
                        <div class="system-log-scroll border rounded p-2 bg-body-tertiary">
                            @forelse ($report['lines'] as $line)
                                <details class="mb-2">
                                    <summary class="d-flex align-items-center gap-2">
                                        <x-badge type="{{ $line['level'] === 'error' ? 'danger' : ($line['level'] === 'warning' ? 'warning' : 'secondary') }}">
                                            {{ $line['level'] }}
                                        </x-badge>
                                        <span class="small">{{ $line['preview'] }}</span>
                                    </summary>
                                    <pre class="small mt-2 p-2 bg-body rounded mb-0" style="white-space: pre-wrap;">{{ $line['body'] }}</pre>
                                </details>
                            @empty
                                <p class="eticart-muted mb-0">Log satırı yok.</p>
                            @endforelse
                        </div>
                    @endunless
                </div>
            </div>
        </div>

        <div class="accordion-item eticart-card mb-3 border-0">
            <h2 class="accordion-header" id="headingCron">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseCron" aria-expanded="false" aria-controls="collapseCron">
                    cron.log (schedule / queue)
                    <span class="ms-2 small eticart-muted">{{ $report['cron_exists'] ? 'son satırlar' : 'dosya yok' }}</span>
                </button>
            </h2>
            <div id="collapseCron" class="accordion-collapse collapse" aria-labelledby="headingCron" data-bs-parent="#systemLogsAccordion">
                <div class="accordion-body">
                    @unless ($report['cron_exists'])
                        <p class="eticart-muted mb-0">cron.log henüz oluşmamış. cPanel cron’u <code>storage/logs/cron.log</code> dosyasına yazacak şekilde ayarlanmalı.</p>
                    @else
                        <div class="system-log-scroll border rounded p-2 bg-body-tertiary">
                            <pre class="small mb-0" style="white-space: pre-wrap;">{{ $report['cron_content'] !== '' ? $report['cron_content'] : '(boş)' }}</pre>
                        </div>
                    @endunless
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
<style>
    .system-log-scroll {
        max-height: 420px;
        overflow: auto;
    }
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.getElementById('failedSyncsPurgeBtn')?.addEventListener('click', async () => {
    const result = await Swal.fire({
        title: 'Emin misiniz?',
        text: 'Başarısız senkron kayıtlarının tümü kalıcı olarak silinecek.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Evet, tümünü sil',
        cancelButtonText: 'Vazgeç',
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
    });

    if (result.isConfirmed) {
        document.getElementById('failedSyncsPurgeForm')?.submit();
    }
});
</script>
@endpush
