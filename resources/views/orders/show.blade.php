@extends('layouts.app')

@section('title', 'Sipariş '.$order->order_number)

@section('content')
    @php
        $cargoShipment = $order->latestCargoShipment();
        $canSendShipmentMail = $cargoShipment && $order->hasInvoice() && filled($order->customer_email);
        $latestMail = $shipmentMails->first();
    @endphp
    <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">{{ $order->order_number }}</h1>
            <p class="eticart-muted mb-0">Shopify ID: {{ $order->shopify_order_id }}</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <form method="POST" action="{{ route('orders.sync-one', $order) }}">
                @csrf
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-arrow-repeat me-1"></i> Senkronize et
                </button>
            </form>
            @if ($cargoShipment)
                <a href="{{ route('orders.print-label', $order) }}" class="btn btn-outline-secondary" target="_blank" rel="noopener">
                    <i class="bi bi-upc-scan me-1"></i> Barkod Yazdır
                </a>
            @endif
            <a href="{{ route('orders.index') }}" class="btn btn-outline-secondary">Geri</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-4">
            <div class="eticart-card p-3 h-100">
                <h2 class="h5 mb-3">Müşteri</h2>
                <dl class="row mb-0 small">
                    <dt class="col-4 eticart-muted">Ad</dt>
                    <dd class="col-8">{{ $order->customer_name }}</dd>
                    <dt class="col-4 eticart-muted">E-posta</dt>
                    <dd class="col-8">{{ $order->customer_email ?: '-' }}</dd>
                    <dt class="col-4 eticart-muted">Telefon</dt>
                    <dd class="col-8">{{ $order->customer_phone ?: '-' }}</dd>
                    <dt class="col-4 eticart-muted">Adres</dt>
                    <dd class="col-8">{{ $order->shipping_address ?: '-' }}</dd>
                    <dt class="col-4 eticart-muted">İlçe / İl</dt>
                    <dd class="col-8">
                        @php $locality = $order->resolveShippingLocality(); @endphp
                        {{ trim(($locality['town'] ?? '').($locality['city'] !== '' ? ', '.$locality['city'] : '')) ?: ($order->shipping_city ?: '-') }}
                    </dd>
                </dl>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="eticart-card p-3 h-100">
                <h2 class="h5 mb-3">Ödeme & Durum</h2>
                <form method="POST" action="{{ route('orders.update-status', $order) }}">
                    @csrf
                    @method('PATCH')
                    <div class="mb-2">
                        <label class="form-label">Ödeme durumu</label>
                        <select name="payment_status" class="form-select">
                            <option value="">Seçiniz</option>
                            @foreach (\App\Support\StatusLabels::paymentMap() as $value => $label)
                                <option value="{{ $value }}" @selected(old('payment_status', $order->payment_status) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Sipariş durumu</label>
                        <select name="fulfillment_status" class="form-select" required>
                            @foreach (\App\Support\StatusLabels::fulfillmentMap() as $value => $label)
                                @continue($value === 'null')
                                <option value="{{ $value }}" @selected(old('fulfillment_status', $order->fulfillment_status) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notlar</label>
                        <textarea name="notes" rows="3" class="form-control">{{ old('notes', $order->notes) }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Durumu Kaydet</button>
                </form>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="eticart-card p-3 h-100">
                <h2 class="h5 mb-3">Özet</h2>
                <div class="d-flex justify-content-between mb-2">
                    <span class="eticart-muted">Toplam</span>
                    <strong>₺{{ number_format((float) $order->total_price, 2) }} {{ $order->currency }}</strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="eticart-muted">Shopify tarihi</span>
                    <span>{{ optional($order->shopify_created_at)->format('d.m.Y H:i') ?: '-' }}</span>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="eticart-muted">Son sync</span>
                    <span>{{ optional($order->synced_at)->format('d.m.Y H:i') ?: '-' }}</span>
                </div>
                <div class="d-flex justify-content-between mt-2">
                    <span class="eticart-muted">Shopify yazımı</span>
                    <span>{{ optional($order->shopify_pushed_at)->format('d.m.Y H:i') ?: ($order->shopify_needs_push ? 'Bekliyor' : '-') }}</span>
                </div>
                <div class="d-flex justify-content-between mt-2">
                    <span class="eticart-muted">Ödeme</span>
                    <x-status-badge group="payment" :value="$order->payment_status" />
                </div>
                <div class="d-flex justify-content-between mt-2">
                    <span class="eticart-muted">Sipariş durumu</span>
                    <x-status-badge group="fulfillment" :value="$order->fulfillment_status" />
                </div>
            </div>
        </div>
    </div>

    <div class="eticart-card p-3 mb-3">
        <h2 class="h5 mb-3">Ürünler</h2>
        @if ($order->items->isEmpty())
            <x-empty-state title="Kalem yok" message="Bu siparişte ürün kalemi bulunamadı." icon="bi-box" />
        @else
            <x-table :headers="['Ürün', 'Varyant', 'SKU', 'Adet', 'Fiyat']">
                @foreach ($order->items as $item)
                    <tr>
                        <td>{{ $item->product_title }}</td>
                        <td>{{ $item->variant_title ?: '-' }}</td>
                        <td>{{ $item->sku ?: '-' }}</td>
                        <td>{{ $item->quantity }}</td>
                        <td>₺{{ number_format((float) $item->price, 2) }}</td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6">
            <div class="eticart-card p-3 h-100">
                <h2 class="h5 mb-1">Fatura belgesi</h2>
                <p class="eticart-muted small mb-3">Belge saklanır. Fatura linki yalnızca yükleme sırasında notlara yazılır; silince notlardan ve Shopify’dan da kalkar.</p>

                @if ($order->hasInvoice())
                    <div class="mb-3">
                        <label class="form-label">Güvenli fatura bağlantısı</label>
                        <div class="input-group">
                            <input type="text" class="form-control" readonly value="{{ $order->invoiceUrl() }}">
                            <a href="{{ $order->invoiceUrl() }}" target="_blank" class="btn btn-outline-primary">İndir</a>
                        </div>
                        <div class="form-text">
                            {{ $order->invoice_original_name }}
                            · {{ optional($order->invoice_uploaded_at)->format('d.m.Y H:i') }}
                        </div>
                    </div>
                    <form method="POST" action="{{ route('orders.invoice.destroy', $order) }}" class="d-inline"
                          onsubmit="return confirm('Fatura belgesi silinsin mi?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger btn-sm">Faturayı sil</button>
                    </form>
                @else
                    <p class="eticart-muted">Henüz fatura yüklenmedi.</p>
                @endif

                <form method="POST" action="{{ route('orders.invoice.upload', $order) }}" enctype="multipart/form-data" class="mt-3">
                    @csrf
                    <div class="row g-2 align-items-end">
                        <div class="col-8">
                            <label class="form-label">{{ $order->hasInvoice() ? 'Yeni fatura yükle' : 'Fatura yükle' }}</label>
                            <input type="file" name="invoice" class="form-control @error('invoice') is-invalid @enderror" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
                            @error('invoice')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-4">
                            <button type="submit" class="btn btn-secondary w-100">Yükle</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="eticart-card p-3 h-100">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h2 class="h5 mb-1">Kargo + fatura bildirimi</h2>
                        <p class="eticart-muted small mb-0">Müşteriye giden e-postanın son durumu ve geçmişi.</p>
                    </div>
                    @if ($latestMail?->status === 'sent')
                        <x-badge type="success">SMTP teslim</x-badge>
                    @elseif ($latestMail?->status === 'failed')
                        <x-badge type="danger">Gönderilemedi</x-badge>
                    @elseif ($latestMail)
                        <x-badge type="warning">Beklemede</x-badge>
                    @else
                        <x-badge type="secondary">Henüz gönderilmedi</x-badge>
                    @endif
                </div>

                @if ($latestMail)
                    @php $mailReport = $latestMail->mailReport(); @endphp
                    <dl class="row small mb-3">
                        <dt class="col-4 eticart-muted">Alıcı</dt>
                        <dd class="col-8">{{ $latestMail->recipient }}</dd>
                        <dt class="col-4 eticart-muted">Son deneme</dt>
                        <dd class="col-8">{{ optional($latestMail->sent_at ?? $latestMail->created_at)->format('d.m.Y H:i') }}</dd>
                        <dt class="col-4 eticart-muted">From</dt>
                        <dd class="col-8">{{ $mailReport['from'] ?? '-' }}</dd>
                        <dt class="col-4 eticart-muted">SMTP</dt>
                        <dd class="col-8">{{ ($mailReport['mailer'] ?? '-') }} / {{ $mailReport['host'] ?? '-' }}</dd>
                        <dt class="col-4 eticart-muted">Ek</dt>
                        <dd class="col-8">{{ $mailReport['attachment'] ?? '-' }}</dd>
                        <dt class="col-4 eticart-muted">Sonuç</dt>
                        <dd class="col-8 {{ $latestMail->status === 'failed' ? 'text-danger' : '' }}">
                            {{ $latestMail->reportMessage() }}
                        </dd>
                        @if (filled($mailReport['warning'] ?? null))
                            <dt class="col-4 eticart-muted">Uyarı</dt>
                            <dd class="col-8 text-warning">{{ $mailReport['warning'] }}</dd>
                        @endif
                    </dl>
                    <p class="small eticart-muted mb-3">
                        SMTP teslim, mailin gelen kutusuna düştüğü anlamına gelmez.
                        Tüm kayıtlar için
                        <a href="{{ route('notifications.index', ['type' => 'mail', 'q' => $order->customer_email]) }}">Bilgilendirmeler</a>.
                    </p>
                @else
                    <p class="eticart-muted">Bu sipariş için henüz kargo + fatura maili gönderilmedi.</p>
                @endif

                @if ($shipmentMails->isNotEmpty())
                    <div class="table-responsive mb-3">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr class="eticart-muted small">
                                    <th>Tarih</th>
                                    <th>Durum</th>
                                    <th>Alıcı</th>
                                    <th>Sonuç</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($shipmentMails as $mail)
                                    <tr>
                                        <td class="small">{{ optional($mail->sent_at ?? $mail->created_at)->format('d.m.Y H:i') }}</td>
                                        <td>
                                            @if ($mail->status === 'sent')
                                                <x-badge type="success">{{ $mail->statusLabel() }}</x-badge>
                                            @elseif ($mail->status === 'failed')
                                                <x-badge type="danger">{{ $mail->statusLabel() }}</x-badge>
                                            @else
                                                <x-badge type="warning">{{ $mail->statusLabel() }}</x-badge>
                                            @endif
                                        </td>
                                        <td class="small">{{ $mail->recipient }}</td>
                                        <td class="small {{ $mail->status === 'failed' ? 'text-danger' : 'eticart-muted' }}">{{ \Illuminate\Support\Str::limit($mail->reportMessage(), 80) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <form method="POST" action="{{ route('orders.send-shipment-mail', $order) }}"
                      onsubmit="return confirm('Bu mail {{ $order->customer_email }} adresine gidecek. Gönderilsin mi?');">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm" @disabled(! $canSendShipmentMail || ($mailWaitSeconds ?? 0) > 0)>
                        <i class="bi bi-envelope me-1"></i>
                        {{ $latestMail ? 'Tekrar gönder' : 'Kargo ve fatura maili gönder' }}
                    </button>
                </form>
                @if (($mailWaitSeconds ?? 0) > 0)
                    <div class="form-text mt-2">
                        Peş peşe gönderim yapmayınız. Yaklaşık {{ (int) ceil($mailWaitSeconds / 60) }} dakika sonra tekrar gönderebilirsiniz.
                    </div>
                @elseif (! $canSendShipmentMail)
                    <div class="form-text mt-2">Göndermek için kargo, fatura ve müşteri e-postası gerekir.</div>
                @else
                    <div class="form-text mt-2">Aynı faturayı farklı adreslere peş peşe göndermeyin; mailler arasında birkaç dakika bekleyin.</div>
                @endif
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="eticart-card p-3 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h2 class="h5 mb-0">Kargo Kayıtları</h2>
                </div>
                @if ($order->shipments->isEmpty())
                    <p class="eticart-muted mb-0">Henüz kargo kaydı yok.</p>
                @else
                    <x-table :headers="['Firma', 'Takip No', 'Durum', 'Alıcı', '']">
                        @foreach ($order->shipments as $shipment)
                            <tr>
                                <td>
                                    <x-cargo-logo :shipment="$shipment" />
                                    <div class="small">{{ $shipment->cargoCompany->name ?? '-' }}</div>
                                </td>
                                <td>
                                    {{ $shipment->tracking_number ?: '-' }}
                                    @if ($shipment->cargoKey())
                                        <div class="small eticart-muted">cargoKey: {{ $shipment->cargoKey() }}</div>
                                    @endif
                                    @if ($shipment->cargo_job_id)
                                        <div class="small eticart-muted">jobId: {{ $shipment->cargo_job_id }}</div>
                                    @endif
                                </td>
                                <td><x-status-badge group="shipment" :value="$shipment->status" /></td>
                                <td>{{ $shipment->receiver_name }}</td>
                                <td class="text-nowrap">
                                    <a href="{{ route('shipments.show', $shipment) }}" class="btn btn-sm btn-outline-primary">Detay</a>
                                    @if (filled($shipment->tracking_number) && $shipment->status !== \App\Models\Shipment::STATUS_CANCELLED)
                                        <a href="{{ route('shipments.print-label', $shipment) }}" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">Barkod</a>
                                    @endif
                                    @if ($shipment->canCancel())
                                        <form method="POST" action="{{ route('orders.shipments.cancel', [$order, $shipment]) }}" class="d-inline"
                                              onsubmit="return confirm('Kargo API üzerinden iptal edilsin mi? Şubeye teslim edilmişse iptal edilemez.');">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-danger">İptal</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </x-table>
                @endif
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="eticart-card p-3 h-100">
                <h2 class="h5 mb-3">Kargo Servisine Gönder</h2>
                @if ($cargoShipment)
                    <p class="mb-0">Bu sipariş zaten kargoya verildi.</p>
                    <div class="small eticart-muted mt-1 mb-2">Takip no: {{ $cargoShipment->tracking_number }}</div>
                    <a href="{{ route('orders.print-label', $order) }}" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">
                        <i class="bi bi-upc-scan me-1"></i> Barkod Yazdır
                    </a>
                @else
                    <form method="POST" action="{{ route('orders.assign-cargo', $order) }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label">Kargo firması</label>
                            <select name="cargo_company_id" id="assignCargoCompany" class="form-select" required>
                                @forelse ($cargoCompanies as $company)
                                    <option value="{{ $company->id }}"
                                            data-provider="{{ $company->provider_type }}"
                                            @selected($company->is_default)>
                                        {{ $company->name }}{{ $company->is_default ? ' (varsayılan)' : '' }}
                                    </option>
                                @empty
                                    <option value="">Tanımlı kargo firması yok</option>
                                @endforelse
                            </select>
                            @if ($cargoCompanies->isEmpty())
                                <div class="form-text">API bilgisi tanımlı aktif kargo firması yok. Ayarlar → Kargo.</div>
                            @endif
                        </div>
                        <div class="mb-3" id="assignPaymentWrap">
                            <label class="form-label">Ödeme tipi</label>
                            <select name="payment_type" class="form-select">
                                <option value="sender">Gönderici ödemeli</option>
                                <option value="receiver">Alıcı ödemeli</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Ağırlık (kg)</label>
                            <input type="number" step="0.01" min="0" name="weight" class="form-control" value="1">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Not</label>
                            <textarea name="notes" rows="2" class="form-control"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary" @disabled($cargoCompanies->isEmpty())>
                            <i class="bi bi-truck me-1"></i> Kargo Servisine Gönder
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const select = document.getElementById('assignCargoCompany');
    const paymentWrap = document.getElementById('assignPaymentWrap');
    const toggle = () => {
        const provider = select?.selectedOptions?.[0]?.getAttribute('data-provider') || '';
        if (paymentWrap) paymentWrap.classList.toggle('d-none', provider !== 'yurtici');
    };
    select?.addEventListener('change', toggle);
    toggle();
});
</script>
@endpush
