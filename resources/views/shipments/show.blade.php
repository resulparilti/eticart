@extends('layouts.app')

@section('title', 'Kargo Detayı')

@section('content')
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">{{ $shipment->tracking_number ?: ('Kargo #'.$shipment->id) }}</h1>
            <p class="eticart-muted mb-0">Sipariş: {{ $shipment->order_number }}</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @if ($shipment->canCancel())
                <form method="POST" action="{{ route('shipments.cancel', $shipment) }}"
                      onsubmit="return confirm('Kargo API üzerinden iptal edilsin mi? Şubeye teslim edilmişse iptal edilemez.');">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger">Kargoyu iptal et</button>
                </form>
            @endif
            <a href="{{ route('shipments.print-label', $shipment) }}" class="btn btn-outline-secondary" target="_blank" rel="noopener">Barkod Yazdır</a>
            <a href="{{ route('shipments.print-invoice', $shipment) }}" class="btn btn-outline-secondary" target="_blank">Fatura Yazdır</a>
            <a href="{{ route('shipments.index') }}" class="btn btn-outline-primary">Liste</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="eticart-card p-3 h-100">
                <h2 class="h5 mb-3">Gönderi Bilgisi</h2>
                <dl class="row mb-0">
                    <dt class="col-4 eticart-muted">Firma</dt>
                    <dd class="col-8">
                        <x-cargo-logo :shipment="$shipment" />
                        <span>{{ $shipment->cargoCompany->name ?? '-' }}</span>
                    </dd>
                    <dt class="col-4 eticart-muted">Durum</dt>
                    <dd class="col-8"><x-status-badge group="shipment" :value="$shipment->status" /></dd>
                    <dt class="col-4 eticart-muted">Takip No</dt>
                    <dd class="col-8">
                        @if ($shipment->tracking_url)
                            <a href="{{ $shipment->tracking_url }}" target="_blank" rel="noopener">{{ $shipment->tracking_number }}</a>
                        @else
                            {{ $shipment->tracking_number ?: '-' }}
                        @endif
                    </dd>
                    @if ($isYurtici)
                        <dt class="col-4 eticart-muted">cargoKey</dt>
                        <dd class="col-8"><code>{{ $shipment->cargoKey() ?: '-' }}</code></dd>
                        <dt class="col-4 eticart-muted">jobId</dt>
                        <dd class="col-8"><code>{{ $shipment->cargo_job_id ?: ($createMeta['job_id'] ?? '-') }}</code></dd>
                    @endif
                    <dt class="col-4 eticart-muted">Ağırlık</dt>
                    <dd class="col-8">{{ $shipment->weight ? $shipment->weight.' kg' : '-' }}</dd>
                    <dt class="col-4 eticart-muted">Tutar</dt>
                    <dd class="col-8">₺{{ number_format((float) $shipment->amount, 2) }}</dd>
                </dl>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="eticart-card p-3 h-100">
                <h2 class="h5 mb-3">Alıcı</h2>
                <dl class="row mb-0">
                    <dt class="col-4 eticart-muted">Ad</dt>
                    <dd class="col-8">{{ $shipment->receiver_name }}</dd>
                    <dt class="col-4 eticart-muted">Telefon</dt>
                    <dd class="col-8">{{ $shipment->receiver_phone ?: '-' }}</dd>
                    <dt class="col-4 eticart-muted">Adres</dt>
                    <dd class="col-8">{{ $shipment->receiver_address ?: '-' }}</dd>
                    <dt class="col-4 eticart-muted">Şehir</dt>
                    <dd class="col-8">{{ $shipment->receiver_city ?: '-' }}</dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="eticart-card p-3">
                <h2 class="h5 mb-3">Durum Güncelle</h2>
                <form method="POST" action="{{ route('shipments.update-status', $shipment) }}">
                    @csrf
                    @method('PATCH')
                    <div class="mb-2">
                        <label class="form-label">Durum</label>
                        <select name="status" class="form-select" required>
                            @foreach (\App\Support\StatusLabels::shipmentMap() as $value => $label)
                                <option value="{{ $value }}" @selected($shipment->status === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Takip no</label>
                        <input type="text" name="tracking_number" class="form-control" value="{{ old('tracking_number', $shipment->tracking_number) }}">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Takip URL</label>
                        <input type="url" name="tracking_url" class="form-control" value="{{ old('tracking_url', $shipment->tracking_url) }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Not</label>
                        <textarea name="notes" class="form-control" rows="2">{{ old('notes', $shipment->notes) }}</textarea>
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit">Kaydet</button>
                </form>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="eticart-card p-3">
                <h2 class="h5 mb-3">Dosyalar</h2>
                <form method="POST" action="{{ route('shipments.generate-label', $shipment) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-outline-secondary btn-sm mb-2" type="submit">Etiket Yenile</button>
                </form>
                <form method="POST" action="{{ route('shipments.generate-invoice', $shipment) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-outline-secondary btn-sm mb-2" type="submit">Fatura Yenile</button>
                </form>
                <div class="small eticart-muted mt-2">
                    <div>Label: {{ $shipment->label_path ?: '-' }}</div>
                    <div>Invoice: {{ $shipment->invoice_path ?: '-' }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="eticart-card p-3 mt-3">
        <h2 class="h5 mb-1">Kargo hareketleri</h2>
        <p class="small eticart-muted mb-3">Yurtiçi API’den gelen hareketler tarih ile saklanır; değişiklik yoksa yeni satır eklenmez.</p>
        @if (($trackingEvents ?? collect())->isEmpty())
            <p class="eticart-muted mb-0 small">Henüz hareket kaydı yok. Cron veya “Kargo durumunu sorgula” sonrası dolacaktır.</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Tarih</th>
                            <th>Kod</th>
                            <th>Durum</th>
                            <th>Açıklama</th>
                            <th>Lokasyon</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($trackingEvents as $event)
                            <tr>
                                <td class="text-nowrap small">{{ optional($event->occurred_at)->format('d.m.Y H:i') ?: '—' }}</td>
                                <td><code class="small">{{ $event->event_code ?: '—' }}</code></td>
                                <td class="small">{{ $event->status ?: ($event->title ?: '—') }}</td>
                                <td class="small">{{ $event->description ?: '—' }}</td>
                                <td class="small">{{ $event->location ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if ($isYurtici)
        <div class="eticart-card p-3 mt-3">
            <h2 class="h5 mb-1">Yurtiçi Kargo üzerinden kontrol et</h2>
            <p class="small eticart-muted mb-3">
                Sorgulama <code>queryShipment</code> + <code>cargoKey</code> (<code>keyType=0</code>) ile yapılır.
                <strong>NOP</strong> = kayıt Yurtiçi sistemine ulaştı, şubede henüz barkodlanmadı.
            </p>
            <div class="d-flex flex-wrap gap-2 mb-3">
                <button type="button" class="btn btn-outline-primary btn-sm" id="yurticiVerifyBtn"
                        data-url="{{ route('shipments.yurtici-verify', $shipment) }}">
                    Yurtiçi üzerinde kayıtlı mı?
                </button>
                <button type="button" class="btn btn-primary btn-sm" id="yurticiStatusBtn"
                        data-url="{{ route('shipments.yurtici-status', $shipment) }}">
                    Kargo durumunu sorgula
                </button>
            </div>
            <div id="yurticiQueryResult" class="border rounded p-3 bg-light {{ $lastQuery ? '' : 'd-none' }}">
                @if ($lastQuery)
                    <div class="small eticart-muted mb-2">Son sorgu: {{ \Illuminate\Support\Carbon::parse($lastQuery['at'] ?? now())->format('d.m.Y H:i') }}</div>
                    <dl class="row mb-0 small" id="yurticiQueryDl">
                        <dt class="col-sm-3">Kayıt</dt>
                        <dd class="col-sm-9" data-field="registered">{{ !empty($lastQuery['registered']) ? 'Yurtiçi sisteminde var' : 'Bulunamadı' }}</dd>
                        <dt class="col-sm-3">outFlag</dt>
                        <dd class="col-sm-9" data-field="out_flag"><code>{{ $lastQuery['out_flag'] ?? '-' }}</code></dd>
                        <dt class="col-sm-3">jobId</dt>
                        <dd class="col-sm-9" data-field="job_id"><code>{{ $lastQuery['job_id'] ?? $shipment->cargo_job_id ?? '-' }}</code></dd>
                        <dt class="col-sm-3">cargoKey</dt>
                        <dd class="col-sm-9" data-field="cargo_key"><code>{{ $lastQuery['cargo_key'] ?? $shipment->cargoKey() }}</code></dd>
                        <dt class="col-sm-3">operationStatus</dt>
                        <dd class="col-sm-9" data-field="operation_status">{{ $lastQuery['operation_status_label'] ?? ($lastQuery['operation_status'] ?? '-') }}</dd>
                    </dl>
                @endif
            </div>
        </div>
    @endif
@endsection

@if ($isYurtici)
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    const renderResult = (payload) => {
        const box = document.getElementById('yurticiQueryResult');
        if (! box) return;
        const r = payload.result || {};
        box.classList.remove('d-none');
        box.innerHTML = `
            <div class="small eticart-muted mb-2">${payload.message || ''}</div>
            <dl class="row mb-0 small">
                <dt class="col-sm-3">Kayıt</dt>
                <dd class="col-sm-9">${r.registered ? 'Yurtiçi sisteminde var' : 'Bulunamadı'}</dd>
                <dt class="col-sm-3">outFlag</dt>
                <dd class="col-sm-9"><code>${r.out_flag ?? '-'}</code></dd>
                <dt class="col-sm-3">jobId</dt>
                <dd class="col-sm-9"><code>${r.job_id ?? '-'}</code></dd>
                <dt class="col-sm-3">cargoKey</dt>
                <dd class="col-sm-9"><code>${r.cargo_key ?? '-'}</code></dd>
                <dt class="col-sm-3">operationStatus</dt>
                <dd class="col-sm-9">${r.operation_status_label || r.operation_status || '-'}</dd>
            </dl>
        `;
    };

    const query = async (url, title) => {
        Swal.fire({
            title,
            text: 'Yurtiçi Kargo sorgulanıyor, lütfen bekleyiniz…',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
        });

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
            });
            const payload = await response.json();
            renderResult(payload);
            Swal.fire({
                icon: payload.success ? 'success' : 'warning',
                title: payload.success ? 'Sorgu tamamlandı' : 'Kayıt bulunamadı',
                text: payload.message || '',
            });
        } catch (error) {
            Swal.fire({ icon: 'error', title: 'Sorgu başarısız', text: error.message || 'Beklenmeyen hata' });
        }
    };

    document.getElementById('yurticiVerifyBtn')?.addEventListener('click', () => {
        query(document.getElementById('yurticiVerifyBtn').dataset.url, 'Yurtiçi kaydı kontrol ediliyor');
    });
    document.getElementById('yurticiStatusBtn')?.addEventListener('click', () => {
        query(document.getElementById('yurticiStatusBtn').dataset.url, 'Kargo durumu sorgulanıyor');
    });
});
</script>
@endpush
@endif
