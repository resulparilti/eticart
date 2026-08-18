@extends('layouts.app')

@section('title', 'Kargo')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Kargo Gönderileri</h1>
            <p class="eticart-muted mb-0">Kargo kayıtlarını yönetin ve takip edin.</p>
        </div>
        <form method="POST" action="{{ route('shipments.sync-tracking') }}">
            @csrf
            <button type="submit" class="btn btn-outline-primary">
                <i class="bi bi-arrow-repeat me-1"></i> Takip Güncelle
            </button>
        </form>
    </div>

    <div class="eticart-card p-3 mb-3">
        <form method="GET" action="{{ route('shipments.index') }}" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Ara</label>
                <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Sipariş, takip no, alıcı">
            </div>
            <div class="col-md-2">
                <label class="form-label">Durum</label>
                <select name="status" class="form-select">
                    <option value="">Tümü</option>
                    @foreach (\App\Support\StatusLabels::shipmentMap() as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Firma</label>
                <select name="cargo_company_id" class="form-select">
                    <option value="">Tümü</option>
                    @foreach ($companies as $company)
                        <option value="{{ $company->id }}" @selected((string) ($filters['cargo_company_id'] ?? '') === (string) $company->id)>
                            {{ $company->name }}
                        </option>
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

    @if ($shipments->isEmpty())
        <x-empty-state
            title="Kargo kaydı yok"
            message="Sipariş detayından kargo oluşturabilirsiniz."
            icon="bi-truck"
        />
    @else
        <x-table :headers="['Sipariş #', 'Alıcı', 'Firma', 'Takip No', 'Durum', 'Tarih', 'İşlem']">
            @foreach ($shipments as $shipment)
                <tr>
                    <td class="fw-semibold">{{ $shipment->order_number }}</td>
                    <td>{{ $shipment->receiver_name }}</td>
                    <td>
                        <x-cargo-logo :shipment="$shipment" />
                        <div class="small">{{ $shipment->cargoCompany->name ?? '-' }}</div>
                    </td>
                    <td>
                        @if ($shipment->tracking_url)
                            <a href="{{ $shipment->tracking_url }}" target="_blank" rel="noopener">{{ $shipment->tracking_number }}</a>
                        @else
                            {{ $shipment->tracking_number ?: '-' }}
                        @endif
                    </td>
                    <td><x-status-badge group="shipment" :value="$shipment->status" /></td>
                    <td>{{ optional($shipment->created_at)->format('d.m.Y H:i') }}</td>
                    <td class="text-nowrap">
                        <a href="{{ route('shipments.show', $shipment) }}" class="btn btn-sm btn-outline-primary">Görüntüle</a>
                        <a href="{{ route('shipments.print-label', $shipment) }}" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">Barkod</a>
                        @if ($shipment->canCancel())
                            <form method="POST" action="{{ route('shipments.cancel', $shipment) }}" class="d-inline"
                                  onsubmit="return confirm('Kargo API üzerinden iptal edilsin mi? Şubeye teslim edilmişse iptal edilemez.');">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-danger">İptal</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-table>

        <div class="mt-3">
            {{ $shipments->links() }}
        </div>
    @endif
@endsection
