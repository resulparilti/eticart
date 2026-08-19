@extends('layouts.app')

@section('title', 'Bildirimler')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Bildirimler</h1>
            <p class="eticart-muted mb-0">Otomatik taramalarda algılanan sistem hareketleri.</p>
        </div>
        <form method="POST" action="{{ route('alerts.read-all') }}">
            @csrf
            <button type="submit" class="btn btn-outline-secondary" @disabled($unreadCount === 0)>
                Tümünü okundu işaretle
            </button>
        </form>
    </div>

    <div class="eticart-card p-3 mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Tür</label>
                <select name="type" class="form-select">
                    <option value="">Tümü</option>
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="unread" value="1" id="onlyUnread" @checked(($filters['unread'] ?? '') === '1')>
                    <label class="form-check-label" for="onlyUnread">Sadece okunmamış</label>
                </div>
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-primary w-100" type="submit">Filtrele</button>
            </div>
        </form>
    </div>

    @if ($notifications->isEmpty())
        <x-empty-state title="Bildirim yok" message="Otomatik taramalar yeni hareket bulunca burada listelenir." icon="bi-bell" />
    @else
        <form method="POST" action="{{ route('alerts.bulk-destroy') }}" id="alertsBulkForm"
              onsubmit="return confirm('Seçili bildirimler silinsin mi?');">
            @csrf
            <div class="d-flex flex-wrap gap-2 mb-2">
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-trash me-1"></i> Seçilenleri sil
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="alertsSelectPage">Sayfadakileri seç</button>
            </div>

            <div class="eticart-card">
                <div class="list-group list-group-flush">
                    @foreach ($notifications as $item)
                        <div class="list-group-item py-3 {{ $item->isUnread() ? 'eticart-alert-unread' : '' }}">
                            <div class="d-flex gap-3 align-items-start">
                                <input type="checkbox" class="form-check-input mt-1 alert-id" name="ids[]" value="{{ $item->id }}" form="alertsBulkForm">
                                <a href="{{ route('alerts.read', $item) }}" class="text-decoration-none text-body flex-grow-1 min-w-0">
                                    <div class="d-flex gap-3">
                                        <i class="bi {{ $item->icon() }} fs-5 text-secondary-brand mt-1"></i>
                                        <div class="flex-grow-1">
                                            <div class="d-flex justify-content-between gap-2">
                                                <strong>{{ $item->title }}</strong>
                                                <span class="small eticart-muted text-nowrap">{{ optional($item->created_at)->format('d.m.Y H:i') }}</span>
                                            </div>
                                            <div class="small eticart-muted">{{ $item->typeLabel() }}@if ($item->message) · {{ $item->message }}@endif</div>
                                        </div>
                                        @if ($item->isUnread())
                                            <span class="badge text-bg-primary align-self-center">Yeni</span>
                                        @endif
                                    </div>
                                </a>
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Sil"
                                        form="alert-destroy-{{ $item->id }}"
                                        onclick="return confirm('Bu bildirim silinsin mi?');">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </form>

        @foreach ($notifications as $item)
            <form id="alert-destroy-{{ $item->id }}" method="POST" action="{{ route('alerts.destroy', $item) }}" class="d-none">
                @csrf
                @method('DELETE')
            </form>
        @endforeach

        <div class="mt-3">{{ $notifications->links() }}</div>
    @endif
@endsection

@push('scripts')
<script>
document.getElementById('alertsSelectPage')?.addEventListener('click', () => {
    document.querySelectorAll('.alert-id').forEach((el) => { el.checked = true; });
});
</script>
@endpush
