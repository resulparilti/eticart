@extends('layouts.app')

@section('title', 'İşlem Geçmişi')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">İşlem Geçmişi</h1>
            <p class="eticart-muted mb-0">Son {{ $retentionDays ?? 7 }} günün kayıtları tutulur; daha eskiler otomatik silinir.</p>
        </div>
    </div>

    <div class="eticart-card p-3 mb-3">
        <form method="GET" action="{{ route('sync-history.index') }}" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Ara</label>
                <input type="search" name="q" value="{{ $search }}" class="form-control" placeholder="Başlık veya mesaj">
            </div>
            <div class="col-md-3">
                <label class="form-label">Durum</label>
                <select name="status" class="form-select">
                    <option value="">Tümü</option>
                    @foreach (['queued' => 'Bekliyor', 'running' => 'Çalışıyor', 'completed' => 'Tamam', 'partial' => 'Kısmi', 'failed' => 'Hata'] as $value => $label)
                        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Tür</label>
                <select name="type" class="form-select">
                    <option value="">Tümü</option>
                    @foreach ($types as $t)
                        <option value="{{ $t }}" @selected($type === $t)>{{ $t }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-primary w-100" type="submit">Filtrele</button>
            </div>
        </form>
    </div>

    @if ($activities->isEmpty())
        <x-empty-state title="Kayıt yok" message="Henüz işlem geçmişi oluşmadı." icon="bi-clock-history" />
    @else
        <form method="POST" action="{{ route('sync-history.bulk-destroy') }}" id="syncHistoryBulkForm"
              onsubmit="return confirm('Seçili bitmiş kayıtlar silinsin mi?');">
            @csrf
            <div class="d-flex flex-wrap gap-2 mb-2">
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-trash me-1"></i> Seçilenleri sil
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="syncHistorySelectPage">Sayfadakileri seç</button>
            </div>

            <x-table :headers="['', 'Başlık', 'Tür', 'Durum', 'İlerleme', 'Mesaj', 'Başlangıç', 'Bitiş', '']">
                @foreach ($activities as $activity)
                    <tr>
                        <td>
                            @if (! $activity->isActive())
                                <input type="checkbox" class="form-check-input sync-history-id" name="ids[]" value="{{ $activity->id }}">
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('sync-history.show', $activity->uuid) }}" class="fw-semibold text-decoration-none">
                                {{ $activity->title }}
                            </a>
                            @if ($activity->dismissed_at)
                                <div class="small eticart-muted">İzleyiciden kapatıldı</div>
                            @endif
                        </td>
                        <td><code class="small">{{ $activity->type }}</code></td>
                        <td>
                            <x-badge :type="$activity->statusBadgeType()">{{ $activity->statusLabel() }}</x-badge>
                        </td>
                        <td>
                            @if ($activity->progress_total)
                                {{ $activity->progress_current }}/{{ $activity->progress_total }}
                                @if ($activity->progressPercent() !== null)
                                    ({{ $activity->progressPercent() }}%)
                                @endif
                            @else
                                {{ $activity->progress_current ?: '—' }}
                            @endif
                        </td>
                        <td class="small">{{ \Illuminate\Support\Str::limit($activity->message, 60) }}</td>
                        <td class="small">{{ optional($activity->started_at)->format('d.m.Y H:i') ?: '—' }}</td>
                        <td class="small">{{ optional($activity->finished_at)->format('d.m.Y H:i') ?: '—' }}</td>
                        <td>
                            <a href="{{ route('sync-history.show', $activity->uuid) }}" class="btn btn-sm btn-outline-primary">Detay</a>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </form>

        <form method="POST" action="{{ route('sync-history.destroy-filtered') }}" class="mt-2"
              onsubmit="return confirm('Filtrelere uyan tüm bitmiş kayıtlar silinsin mi? Bu işlem geri alınamaz.');">
            @csrf
            <input type="hidden" name="mode" value="filtered">
            <input type="hidden" name="status" value="{{ $status }}">
            <input type="hidden" name="type" value="{{ $type }}">
            <input type="hidden" name="q" value="{{ $search }}">
            <button type="submit" class="btn btn-outline-danger btn-sm">Filtrelenen bitmiş kayıtları sil</button>
        </form>

        <div class="mt-3">{{ $activities->links() }}</div>
    @endif
@endsection

@push('scripts')
<script>
document.getElementById('syncHistorySelectPage')?.addEventListener('click', () => {
    document.querySelectorAll('.sync-history-id').forEach((el) => { el.checked = true; });
});
</script>
@endpush
