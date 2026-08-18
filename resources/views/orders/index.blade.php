@extends('layouts.app')

@section('title', 'Siparişler')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1">Siparişler</h1>
            <p class="eticart-muted mb-0">Shopify siparişlerini görüntüleyin, kargoya gönderin ve barkod yazdırın.</p>
        </div>
        <div class="d-flex gap-2">
            @if (! $isConfigured)
                <span class="badge text-bg-warning align-self-center">Shopify ayarları eksik</span>
            @endif
            <form method="POST" action="{{ route('orders.sync') }}">
                @csrf
                <input type="hidden" name="limit" value="50">
                <input type="hidden" name="status" value="any">
                <button type="submit" class="btn btn-primary" @disabled(! $isConfigured)>
                    <i class="bi bi-arrow-repeat me-1"></i> Senkronize Et
                </button>
            </form>
        </div>
    </div>

    @php
        $missingCustomer = \App\Models\ShopifyOrder::query()
            ->where(function ($q) {
                $q->whereNull('customer_email')
                    ->orWhere('customer_name', 'Misafir')
                    ->orWhereNull('shipping_address');
            })
            ->count();
    @endphp
    @if ($missingCustomer > 0)
        <div class="alert alert-warning">
            <strong>{{ $missingCustomer }}</strong> siparişte müşteri bilgisi eksik görünüyor.
            Bu genellikle Shopify <em>Protected Customer Data</em> kısıtından kaynaklanır — uygulama API’si ad/e-posta/telefon/adresi sansürler.
            <ul class="mb-0 mt-2 small">
                <li>Custom app API izinleri: <code>read_orders</code>, <code>read_customers</code></li>
                <li>Partner/Dev Dashboard’da Protected Customer Data → Level 2 (name, email, phone, address) erişimi</li>
                <li>Admin’den oluşturulan app + Basic plan Level 2’yi kısıtlayabilir; Partner custom app veya üst plan gerekebilir</li>
                <li>İzin açıldıktan sonra uygulamayı yeniden yükleyip token’ı yenileyin, sonra tekrar <strong>Senkronize Et</strong></li>
            </ul>
        </div>
    @endif

    <div class="eticart-card p-3 mb-3">
        <form method="GET" action="{{ route('orders.index') }}" class="row g-2 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label">Müşteri / Sipariş</label>
                <input type="text" name="customer" value="{{ $filters['customer'] ?? '' }}" class="form-control" placeholder="Ad, e-posta veya #">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Sipariş durumu</label>
                <select name="status" class="form-select">
                    <option value="">Tümü</option>
                    @foreach (\App\Support\StatusLabels::fulfillmentMap() as $value => $label)
                        @continue($value === 'null')
                        <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Ödeme</label>
                <select name="payment_status" class="form-select">
                    <option value="">Tümü</option>
                    @foreach (\App\Support\StatusLabels::paymentMap() as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['payment_status'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Başlangıç</label>
                <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="form-control">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Bitiş</label>
                <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="form-control">
            </div>
            <div class="col-12 col-md-1">
                <button type="submit" class="btn btn-outline-primary w-100">Filtrele</button>
            </div>
        </form>
    </div>

    @if ($orders->isEmpty())
        <x-empty-state
            title="Sipariş bulunamadı"
            message="Shopify senkronizasyonu sonrası siparişler burada listelenir."
            icon="bi-bag"
        />
    @else
        <div id="ordersBulkBar" class="eticart-card p-3 mb-3 d-none">
            <div class="d-flex flex-wrap align-items-end gap-2 justify-content-between">
                <div class="d-flex flex-wrap align-items-end gap-2">
                    <div>
                        <div class="small eticart-muted mb-1">Seçili: <strong id="ordersSelectedCount">0</strong></div>
                        <label class="form-label mb-1">Kargo firması</label>
                        <select id="bulkCargoCompany" class="form-select form-select-sm" style="min-width:220px;">
                            @forelse ($cargoCompanies as $company)
                                <option value="{{ $company->id }}"
                                        data-provider="{{ $company->provider_type }}"
                                        @selected($company->is_default)>
                                    {{ $company->name }}{{ $company->is_default ? ' (varsayılan)' : '' }}
                                </option>
                            @empty
                                <option value="">Aktif kargo firması yok</option>
                            @endforelse
                        </select>
                    </div>
                    <div>
                        <label class="form-label mb-1">Ödeme tipi</label>
                        <select id="bulkPaymentType" class="form-select form-select-sm">
                            <option value="sender">Gönderici ödemeli</option>
                            <option value="receiver">Alıcı ödemeli</option>
                        </select>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" id="bulkSendCargoBtn" class="btn btn-primary btn-sm" @disabled($cargoCompanies->isEmpty())>
                        <i class="bi bi-truck me-1"></i> Kargo Servisine Gönder
                    </button>
                    <button type="button" id="bulkPrintLabelsBtn" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-upc-scan me-1"></i> Barkod Yazdır
                    </button>
                </div>
            </div>
        </div>

        <x-table :headers="['', 'Sipariş #', 'Kargo', 'Müşteri', 'Tutar', 'Ödeme', 'Sipariş durumu', 'Tarih', 'İşlem']">
            <tr class="table-light">
                <td>
                    <input type="checkbox" class="form-check-input" id="ordersSelectAll" title="Tümünü seç">
                </td>
                <td colspan="8" class="small eticart-muted">Bu sayfadaki siparişleri seç / kaldır</td>
            </tr>
            @foreach ($orders as $order)
                @php
                    $cargoShipment = $order->latestCargoShipment();
                @endphp
                <tr data-order-id="{{ $order->id }}">
                    <td>
                        <input type="checkbox"
                               class="form-check-input order-select-checkbox"
                               value="{{ $order->id }}"
                               data-has-cargo="{{ $cargoShipment ? '1' : '0' }}"
                               data-order-number="{{ $order->order_number }}">
                    </td>
                    <td class="fw-semibold">{{ $order->order_number }}</td>
                    <td>
                        <x-cargo-logo :shipment="$cargoShipment" />
                        @if ($cargoShipment?->tracking_number)
                            <div class="small eticart-muted">{{ $cargoShipment->tracking_number }}</div>
                        @endif
                    </td>
                    <td>
                        <div>{{ $order->customer_name }}</div>
                        <small class="eticart-muted">{{ $order->customer_email }}</small>
                    </td>
                    <td>₺{{ number_format((float) $order->total_price, 2) }}</td>
                    <td><x-status-badge group="payment" :value="$order->payment_status" /></td>
                    <td><x-status-badge group="fulfillment" :value="$order->fulfillment_status" /></td>
                    <td>{{ optional($order->shopify_created_at ?? $order->created_at)->format('d.m.Y H:i') }}</td>
                    <td class="text-nowrap">
                        <a href="{{ route('orders.show', $order) }}" class="btn btn-sm btn-outline-primary">
                            Görüntüle
                        </a>
                        @if ($cargoShipment)
                            <a href="{{ route('orders.print-label', $order) }}" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">
                                <i class="bi bi-upc-scan"></i> Barkod Yazdır
                            </a>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-table>

        <div class="mt-3">
            {{ $orders->links() }}
        </div>
    @endif
@endsection

@push('styles')
<style>
    .cargo-provider-logo {
        display: inline-block;
        max-width: 110px;
        height: 24px;
        object-fit: contain;
        vertical-align: middle;
        border-radius: 4px;
    }
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const selectAll = document.getElementById('ordersSelectAll');
    const checkboxes = () => Array.from(document.querySelectorAll('.order-select-checkbox'));
    const bulkBar = document.getElementById('ordersBulkBar');
    const selectedCountEl = document.getElementById('ordersSelectedCount');
    const sendBtn = document.getElementById('bulkSendCargoBtn');
    const printBtn = document.getElementById('bulkPrintLabelsBtn');
    const companySelect = document.getElementById('bulkCargoCompany');
    const paymentSelect = document.getElementById('bulkPaymentType');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    const selectedIds = () => checkboxes().filter((el) => el.checked).map((el) => Number(el.value));

    const refreshSelectionUi = () => {
        const selected = selectedIds();
        if (selectedCountEl) selectedCountEl.textContent = String(selected.length);
        if (bulkBar) bulkBar.classList.toggle('d-none', selected.length === 0);
        if (selectAll) {
            const all = checkboxes();
            selectAll.checked = all.length > 0 && all.every((el) => el.checked);
            selectAll.indeterminate = selected.length > 0 && selected.length < all.length;
        }
    };

    selectAll?.addEventListener('change', () => {
        checkboxes().forEach((el) => { el.checked = selectAll.checked; });
        refreshSelectionUi();
    });

    checkboxes().forEach((el) => el.addEventListener('change', refreshSelectionUi));

    const resultHtml = (payload) => {
        const results = payload.results || {};
        const section = (title, items, tone) => {
            if (! items || items.length === 0) return '';
            const rows = items.map((item) => {
                const tracking = item.tracking_number ? ` · <code>${item.tracking_number}</code>` : '';
                return `<li class="text-start"><strong>${item.order_number}</strong>${tracking}<br><span class="small">${item.message || ''}</span></li>`;
            }).join('');
            return `<div class="text-start mb-2"><div class="fw-semibold text-${tone}">${title} (${items.length})</div><ul class="mb-0 ps-3">${rows}</ul></div>`;
        };

        return `
            <div class="small text-muted mb-2">${payload.message || ''}</div>
            ${section('Başarılı', results.success, 'success')}
            ${section('Atlandı', results.skipped, 'warning')}
            ${section('Hatalı', results.failed, 'danger')}
        `;
    };

    sendBtn?.addEventListener('click', async () => {
        const ids = selectedIds();
        const companyId = companySelect?.value;
        if (! ids.length) return;
        if (! companyId) {
            Swal.fire({ icon: 'warning', title: 'Kargo firması seçin' });
            return;
        }

        const companyName = companySelect.options[companySelect.selectedIndex]?.text || 'Kargo';
        const confirm = await Swal.fire({
            icon: 'question',
            title: 'Kargo servisine gönderilsin mi?',
            html: `<div class="text-start small"><strong>${ids.length}</strong> sipariş <strong>${companyName}</strong> firmasına gönderilecek.</div>`,
            showCancelButton: true,
            confirmButtonText: 'Gönder',
            cancelButtonText: 'Vazgeç',
        });

        if (! confirm.isConfirmed) return;

        Swal.fire({
            title: 'Gönderiliyor...',
            html: 'Siparişler kargo API’sine iletiliyor.',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
        });

        try {
            const response = await fetch(@json(route('orders.bulk-send-cargo')), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    order_ids: ids,
                    cargo_company_id: Number(companyId),
                    payment_type: paymentSelect?.value || 'sender',
                }),
            });

            const payload = await response.json();
            const icon = (payload.summary?.failed ?? 0) === 0
                ? ((payload.summary?.success ?? 0) > 0 ? 'success' : 'info')
                : ((payload.summary?.success ?? 0) > 0 ? 'warning' : 'error');

            await Swal.fire({
                icon,
                title: 'Kargo gönderim sonucu',
                html: resultHtml(payload),
                width: 640,
                confirmButtonText: 'Tamam',
            });

            window.location.reload();
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'İstek başarısız',
                text: error.message || 'Beklenmeyen bir hata oluştu.',
            });
        }
    });

    printBtn?.addEventListener('click', async () => {
        const ids = selectedIds();
        if (! ids.length) return;

        const withCargo = checkboxes().filter((el) => el.checked && el.dataset.hasCargo === '1');
        if (withCargo.length === 0) {
            Swal.fire({
                icon: 'info',
                title: 'Yazdırılacak barkod yok',
                text: 'Seçili siparişlerde henüz kargo kaydı yok. Önce kargo servisine gönderin.',
            });
            return;
        }

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = @json(route('orders.bulk-print-labels'));
        form.target = '_blank';

        const token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = csrf;
        form.appendChild(token);

        ids.forEach((id) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'order_ids[]';
            input.value = String(id);
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
        form.remove();
    });

    refreshSelectionUi();
});
</script>
@endpush
