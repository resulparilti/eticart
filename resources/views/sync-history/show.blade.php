@extends('layouts.app')

@section('title', $activity->title)

@section('content')
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">{{ $activity->title }}</h1>
            <p class="eticart-muted mb-0">
                <code>{{ $activity->type }}</code>
                · <x-badge :type="$activity->statusBadgeType()">{{ $activity->statusLabel() }}</x-badge>
            </p>
        </div>
        <div class="d-flex gap-2">
            @if ($activity->isActive())
                <form method="POST" action="{{ route('sync-activities.cancel', $activity->uuid) }}"
                      onsubmit="return confirm('Bu işlem iptal edilsin mi?');">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger">İptal et</button>
                </form>
            @endif
            <a href="{{ route('sync-history.index') }}" class="btn btn-outline-secondary">Geri</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="eticart-card p-3 h-100">
                <h2 class="h6 mb-3">Özet</h2>
                <dl class="row mb-0 small">
                    <dt class="col-4 eticart-muted">Mesaj</dt>
                    <dd class="col-8">{{ $activity->message ?: '—' }}</dd>
                    <dt class="col-4 eticart-muted">İlerleme</dt>
                    <dd class="col-8">
                        @if ($activity->progress_total)
                            {{ $activity->progress_current }}/{{ $activity->progress_total }}
                            @if ($activity->progressPercent() !== null)
                                ({{ $activity->progressPercent() }}%)
                            @endif
                        @else
                            {{ $activity->progress_current }}
                        @endif
                    </dd>
                    <dt class="col-4 eticart-muted">Başlangıç</dt>
                    <dd class="col-8">{{ optional($activity->started_at)->format('d.m.Y H:i:s') ?: '—' }}</dd>
                    <dt class="col-4 eticart-muted">Bitiş</dt>
                    <dd class="col-8">{{ optional($activity->finished_at)->format('d.m.Y H:i:s') ?: '—' }}</dd>
                    <dt class="col-4 eticart-muted">İzleyici</dt>
                    <dd class="col-8">{{ $activity->dismissed_at ? 'Kapatıldı ('.$activity->dismissed_at->format('d.m.Y H:i').')' : 'Aktif listede' }}</dd>
                </dl>
            </div>
        </div>
        <div class="col-md-6">
            <div class="eticart-card p-3 h-100">
                <h2 class="h6 mb-3">Meta</h2>
                @if (! empty($activity->meta))
                    <pre class="small mb-0" style="white-space: pre-wrap;">{{ json_encode($activity->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                @else
                    <p class="eticart-muted mb-0 small">Ek meta yok.</p>
                @endif
            </div>
        </div>
    </div>

    <div class="eticart-card p-3">
        <h2 class="h6 mb-3">Loglar ({{ $logs->count() }})</h2>
        @if ($logs->isEmpty())
            <p class="eticart-muted mb-0">Log yok.</p>
        @else
            <div class="eticart-sync-log-stream rounded" style="max-height: 480px;">
                @foreach ($logs as $log)
                    <div class="eticart-sync-log is-{{ $log->level }}">
                        <span class="eticart-sync-log__time">{{ optional($log->created_at)->format('H:i:s') }}</span>
                        <span class="eticart-sync-log__level">{{ $log->level }}</span>
                        <span class="eticart-sync-log__msg">{{ $log->message }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endsection
