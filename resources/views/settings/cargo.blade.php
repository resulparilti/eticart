@extends('layouts.app')

@section('title', 'Kargo Ayarları')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Kargo Ayarları</h1>
            <p class="eticart-muted mb-0">Firma API bilgileri. Aktif firma en üstte ve açık görünür. Boş bırakılan gizli alanlar değişmez.</p>
        </div>
        <a href="{{ route('settings.index') }}" class="btn btn-outline-secondary">Geri</a>
    </div>

    <form id="cargo-settings-form" method="POST" action="{{ route('settings.cargo.update') }}">
        @csrf
    </form>

    <div class="accordion eticart-card overflow-hidden" id="cargoCompaniesAccordion">
        @foreach ($companies as $index => $company)
            @include('settings.partials.cargo-company-panel', [
                'company' => $company,
                'index' => $index,
            ])
        @endforeach
    </div>

    <button type="submit" form="cargo-settings-form" class="btn btn-primary mt-3">Kaydet</button>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const shipmentEndpoint = @json(route('settings.cargo.test-yurtici-shipment'));
    const queryEndpoint = @json(route('settings.cargo.query-yurtici-shipment'));
    const labelEndpoint = @json(route('settings.cargo.yurtici-label'));
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    const buildLabelUrl = (result, companyId) => {
        const receiver = result.receiver ?? {};
        const pkg = result.package ?? {};
        const params = new URLSearchParams({
            company_id: String(companyId ?? ''),
            cargo_key: String(result.cargo_key ?? result.barcode_value ?? ''),
            invoice_key: String(result.invoice_key ?? ''),
            job_id: String(result.job_id ?? ''),
            receiver_name: String(receiver.name ?? ''),
            receiver_address: String(receiver.address ?? ''),
            receiver_city: String(receiver.city ?? ''),
            receiver_town: String(receiver.town ?? ''),
            receiver_phone: String(receiver.phone ?? ''),
            desi: String(pkg.desi ?? ''),
            weight: String(pkg.kg ?? ''),
            cargo_count: String(pkg.cargo_count ?? '1'),
        });

        return `${labelEndpoint}?${params.toString()}`;
    };

    const renderBarcodePreview = (resultPanel, result, companyId) => {
        const preview = resultPanel.querySelector('.yurtici-label-preview');
        const canvas = resultPanel.querySelector('.yurtici-barcode-canvas');
        const textEl = resultPanel.querySelector('.yurtici-barcode-text');
        const printBtn = resultPanel.querySelector('.yurtici-print-label-btn');
        const cargoKey = result.cargo_key ?? result.barcode_value ?? null;

        if (! preview || ! cargoKey) {
            preview?.classList.add('d-none');
            return;
        }

        preview.classList.remove('d-none');
        if (textEl) textEl.textContent = cargoKey;
        if (printBtn) printBtn.href = buildLabelUrl(result, companyId);

        if (canvas && window.JsBarcode) {
            try {
                JsBarcode(canvas, cargoKey, {
                    format: 'CODE128',
                    displayValue: false,
                    width: 2,
                    height: 64,
                    margin: 8,
                    background: '#ffffff',
                    lineColor: '#000000',
                });
            } catch (e) {
                console.error(e);
            }
        }
    };

    const renderResult = (resultPanel, payload, statusEl, companyId = null) => {
        const result = payload.result ?? {};
        const summary = resultPanel.querySelector('.yurtici-test-summary');
        const parsedEl = resultPanel.querySelector('.yurtici-test-parsed');
        const xmlEl = resultPanel.querySelector('.yurtici-test-xml');
        const requestEl = resultPanel.querySelector('.yurtici-test-request');

        resultPanel.classList.remove('d-none');

        const trackingReady = !!(result.tracking_ready ?? result.query?.tracking_ready);
        const trackingNumber = result.tracking_number ?? result.query?.tracking_number ?? null;
        const cargoKey = result.cargo_key ?? result.query?.cargo_key ?? null;
        const docId = result.doc_id ?? result.query?.doc_id ?? null;
        const trackingUrl = result.tracking_url ?? result.query?.tracking_url ?? null;
        const resolvedCompanyId = companyId
            ?? resultPanel.closest('.accordion-body')?.querySelector('.yurtici-shipment-test-form input[name="company_id"]')?.value
            ?? resultPanel.closest('.accordion-body')?.querySelector('.yurtici-query-test-form input[name="company_id"]')?.value;

        if (summary) {
            let alertClass = payload.success ? 'alert-success' : 'alert-danger';
            let html = `<div class="alert ${alertClass} small mb-2">${payload.message ?? 'Yanıt alındı.'}</div>`;

            html += '<div class="eticart-card p-3 small">';
            html += `<div><strong>Yazdırılacak barkod (cargoKey):</strong> ${cargoKey ? `<code>${cargoKey}</code>` : '-'}</div>`;
            html += `<div class="mt-1"><strong>Format:</strong> CODE128</div>`;
            html += `<div class="mt-1"><strong>docId (halka açık takip):</strong> ${docId ? `<code>${docId}</code>` : '<span class="text-muted">henüz yok</span>'}</div>`;

            if (trackingReady && trackingNumber) {
                html += `<div class="mt-2 text-success"><strong>Takip numarası:</strong> <code>${trackingNumber}</code></div>`;
                if (trackingUrl) {
                    html += `<div class="mt-1"><a href="${trackingUrl}" target="_blank" rel="noopener noreferrer">Yurtiçi takip sayfasını aç</a></div>`;
                }
            } else if (payload.success) {
                html += `<div class="mt-2 text-warning"><strong>Pakete cargoKey barkodunu basıp Yurtiçi'ye verin.</strong> Halka açık takip no genelde şube okutmasından sonra oluşur.</div>`;
            }
            html += '</div>';

            summary.innerHTML = html;
        }

        if (payload.success && (result.cargo_key || result.barcode_value)) {
            renderBarcodePreview(resultPanel, result, resolvedCompanyId);
        } else if (! result.cargo_key) {
            resultPanel.querySelector('.yurtici-label-preview')?.classList.add('d-none');
        } else {
            // Query-only: still allow printing barcode for known cargoKey
            renderBarcodePreview(resultPanel, {
                ...result,
                barcode_value: result.cargo_key,
            }, resolvedCompanyId);
        }

        if (parsedEl) {
            parsedEl.textContent = JSON.stringify(result, null, 2);
        }

        if (xmlEl) {
            const xml = result.raw_xml ?? result.query_raw_xml ?? result.query?.raw_xml ?? '';
            xmlEl.textContent = xml !== '' ? xml : 'Ham XML yanıtı dönmedi.';
        }

        if (requestEl) {
            requestEl.textContent = JSON.stringify(result.request_payload ?? {}, null, 2);
        }

        if (statusEl) {
            statusEl.textContent = trackingReady ? 'Takip numarası alındı' : (payload.success ? 'Barkod hazır (cargoKey)' : 'Hata');
        }
    };

    document.querySelectorAll('.yurtici-shipment-test-form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const resultPanel = document.querySelector(form.dataset.resultTarget);
            const statusEl = form.querySelector('.yurtici-test-status');
            const submitBtn = form.querySelector('[type="submit"]');
            if (! resultPanel) return;

            submitBtn.disabled = true;
            if (statusEl) statusEl.textContent = 'Gönderiliyor ve takip sorgulanıyor...';

            try {
                const response = await fetch(shipmentEndpoint, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: new FormData(form),
                });
                const payload = await response.json();
                const companyId = form.querySelector('input[name="company_id"]')?.value;
                renderResult(resultPanel, payload, statusEl, companyId);

                const cargoKey = payload.result?.cargo_key;
                if (cargoKey) {
                    const queryInput = resultPanel.parentElement?.querySelector('.yurtici-query-test-form input[name="key"]');
                    if (queryInput) queryInput.value = cargoKey;
                }
            } catch (error) {
                if (statusEl) statusEl.textContent = 'İstek başarısız';
                resultPanel.classList.remove('d-none');
                const summary = resultPanel.querySelector('.yurtici-test-summary');
                if (summary) {
                    summary.innerHTML = `<div class="alert alert-danger small mb-0">${error.message}</div>`;
                }
            } finally {
                submitBtn.disabled = false;
            }
        });
    });

    document.querySelectorAll('.yurtici-query-test-form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const resultPanel = document.querySelector(form.dataset.resultTarget);
            const statusEl = form.querySelector('.yurtici-query-status');
            const submitBtn = form.querySelector('[type="submit"]');
            if (! resultPanel) return;

            submitBtn.disabled = true;
            if (statusEl) statusEl.textContent = 'Sorgulanıyor...';

            try {
                const response = await fetch(queryEndpoint, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: new FormData(form),
                });
                const payload = await response.json();
                const companyId = form.querySelector('input[name="company_id"]')?.value;
                // Keep last shipment receiver data if present on print button URL context via previous result
                if (! payload.result?.receiver && payload.result?.cargo_key) {
                    payload.result.barcode_value = payload.result.cargo_key;
                }
                renderResult(resultPanel, payload, statusEl, companyId);
            } catch (error) {
                if (statusEl) statusEl.textContent = 'Sorgu başarısız';
                resultPanel.classList.remove('d-none');
                const summary = resultPanel.querySelector('.yurtici-test-summary');
                if (summary) {
                    summary.innerHTML = `<div class="alert alert-danger small mb-0">${error.message}</div>`;
                }
            } finally {
                submitBtn.disabled = false;
            }
        });
    });
});
</script>
@endpush
