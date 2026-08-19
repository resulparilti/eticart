@extends('layouts.app')

@section('title', 'Mesaj bilgilendirmeleri')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Mesaj bilgilendirmeleri</h1>
            <p class="eticart-muted mb-0">Müşteriye gönderilen mail ve SMS kayıtları.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('messages.send') }}" class="btn btn-primary">Mesaj gönder</a>
            <a href="{{ route('notifications.templates') }}" class="btn btn-outline-secondary">Şablonlar</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="eticart-card p-3">
                <form method="GET" action="{{ route('notifications.index') }}" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Ara</label>
                        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Alıcı / konu">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Tip</label>
                        <select name="type" class="form-select">
                            <option value="">Tümü</option>
                            <option value="mail" @selected(($filters['type'] ?? '') === 'mail')>Mail</option>
                            <option value="sms" @selected(($filters['type'] ?? '') === 'sms')>SMS</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Durum</label>
                        <select name="status" class="form-select">
                            <option value="">Tümü</option>
                            @foreach (['pending' => 'Beklemede', 'sent' => 'SMTP teslim', 'failed' => 'Başarısız'] as $status => $statusLabel)
                                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $statusLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Başlangıç</label>
                        <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Bitiş</label>
                        <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="form-control">
                    </div>
                    <div class="col-md-1">
                        <button class="btn btn-outline-primary w-100" type="submit">Filtre</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="eticart-card p-3 h-100">
                <div class="eticart-muted small">SMS Provider</div>
                <div class="fw-semibold">{{ $smsBalance['provider'] ?? '-' }} ({{ $smsBalance['mode'] ?? '-' }})</div>
                <div class="small eticart-muted mt-1">{{ $smsBalance['message'] ?? ($smsBalance['balance'] ?? '') }}</div>
            </div>
        </div>
    </div>

    @if ($notifications->isEmpty())
        <x-empty-state title="Bildirim yok" message="Gönderilen mail/SMS kayıtları burada listelenir." icon="bi-bell" />
    @else
        <form method="POST" action="{{ route('notifications.bulk-destroy') }}" id="notificationsBulkForm"
              onsubmit="return confirm('Seçili bilgilendirme kayıtları silinsin mi?');">
            @csrf
            <div class="d-flex flex-wrap gap-2 mb-2">
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-trash me-1"></i> Seçilenleri sil
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="notificationsSelectPage">Sayfadakileri seç</button>
            </div>

            <x-table :headers="['', 'Tip', 'Alıcı', 'Konu / Mesaj', 'Durum', 'Tarih', 'İşlem']">
                @foreach ($notifications as $item)
                    <tr>
                        <td>
                            <input type="checkbox" class="form-check-input notification-id" name="ids[]" value="{{ $item->id }}">
                        </td>
                        <td><x-badge type="{{ $item->type === 'mail' ? 'info' : 'secondary' }}">{{ $item->type }}</x-badge></td>
                        <td>{{ $item->recipient }}</td>
                        <td>
                            <div class="fw-semibold">{{ $item->subject ?: '-' }}</div>
                            @if ($item->type === 'mail' && ! in_array($item->body, ['shipment-invoice', 'invoice-notice', 'cargo-notice'], true))
                                <small class="eticart-muted">{{ \Illuminate\Support\Str::limit($item->body, 80) }}</small>
                            @endif
                            @php $report = $item->mailReport(); @endphp
                            @if ($item->status === 'failed')
                                <div class="text-danger small">{{ $item->reportMessage() }}</div>
                            @else
                                <div class="small eticart-muted">
                                    {{ $item->reportMessage() }}
                                    @if (! empty($report['from'])) · From: {{ $report['from'] }} @endif
                                    @if (! empty($report['host'])) · SMTP: {{ $report['host'] }} @endif
                                    @if (! empty($report['attachment'])) · Ek: {{ $report['attachment'] }} @endif
                                </div>
                            @endif
                            @if (filled($report['warning'] ?? null))
                                <div class="text-warning small">{{ $report['warning'] }}</div>
                            @endif
                        </td>
                        <td>
                            <x-badge type="{{ $item->status === 'sent' ? 'success' : ($item->status === 'failed' ? 'danger' : 'warning') }}">
                                {{ $item->statusLabel() }}
                            </x-badge>
                        </td>
                        <td>{{ optional($item->sent_at ?? $item->created_at)->format('d.m.Y H:i') }}</td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <button class="btn btn-sm btn-outline-primary" type="submit"
                                        form="notification-resend-{{ $item->id }}">Yeniden Gönder</button>
                                <button class="btn btn-sm btn-outline-danger" type="submit" title="Sil"
                                        form="notification-destroy-{{ $item->id }}"
                                        onclick="return confirm('Bu kayıt silinsin mi?');">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-table>
        </form>

        @foreach ($notifications as $item)
            <form id="notification-resend-{{ $item->id }}" method="POST" action="{{ route('notifications.resend', $item) }}" class="d-none">
                @csrf
            </form>
            <form id="notification-destroy-{{ $item->id }}" method="POST" action="{{ route('notifications.destroy', $item) }}" class="d-none">
                @csrf
                @method('DELETE')
            </form>
        @endforeach

        <div class="mt-3">
            {{ $notifications->links() }}
        </div>
    @endif
@endsection

@push('scripts')
<script>
document.getElementById('notificationsSelectPage')?.addEventListener('click', () => {
    document.querySelectorAll('.notification-id').forEach((el) => { el.checked = true; });
});
</script>
@endpush
