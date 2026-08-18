<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Toplu Kargo Fişleri ({{ count($labels) }})</title>
    <style>
        :root { --ink:#111; --muted:#555; --line:#222; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 16px;
            font-family: Arial, Helvetica, sans-serif;
            color: var(--ink);
            background: #f3f3f3;
        }
        .toolbar {
            max-width: 105mm;
            margin: 0 auto 16px;
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: space-between;
        }
        .toolbar button, .toolbar a {
            border: 1px solid #ccc;
            background: #fff;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            color: #111;
            font-size: 13px;
        }
        .label {
            width: 105mm;
            min-height: 148mm;
            margin: 0 auto 16px;
            background: #fff;
            border: 2px solid var(--line);
            padding: 8mm;
            page-break-after: always;
        }
        .label:last-child { page-break-after: auto; }
        .brand {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid var(--line);
            padding-bottom: 6px;
            margin-bottom: 10px;
        }
        .brand strong { font-size: 15px; }
        .brand small { display: block; color: var(--muted); margin-top: 2px; font-size: 11px; }
        .badge {
            border: 1px solid var(--line);
            padding: 4px 6px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .section-title {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--muted);
            margin: 10px 0 4px;
        }
        .receiver-name { font-size: 18px; font-weight: bold; line-height: 1.2; }
        .meta { font-size: 12px; line-height: 1.45; }
        .barcode-wrap {
            margin-top: 14px;
            border: 1px dashed var(--line);
            padding: 10px 8px 6px;
            text-align: center;
        }
        .barcode-wrap canvas { max-width: 100%; height: auto !important; }
        .cargo-key {
            margin-top: 6px;
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 1px;
            font-family: "Courier New", monospace;
        }
        .hint { margin-top: 8px; font-size: 10px; color: var(--muted); }
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 12px;
            margin-top: 8px;
        }
        .grid .item strong {
            display: block;
            font-size: 10px;
            color: var(--muted);
            font-weight: normal;
            text-transform: uppercase;
        }
        .grid .item span { font-size: 13px; font-weight: bold; }
        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none !important; }
            .label {
                border: 2px solid #000;
                margin: 0;
                page-break-inside: avoid;
            }
            @page { size: 105mm 148mm; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <div class="small">{{ count($labels) }} kargo fişi</div>
        <div>
            <button type="button" onclick="window.print()">Yazdır</button>
            <a href="{{ route('orders.index') }}">Siparişlere dön</a>
        </div>
    </div>

    @foreach ($labels as $index => $label)
        <div class="label">
            <div class="brand">
                <div>
                    <strong>{{ strtoupper($label['company']->name ?? 'KARGO') }}</strong>
                    <small>Sipariş: {{ $label['order']->order_number }}</small>
                </div>
                <div class="badge">{{ $label['provider'] ?? 'kargo' }}</div>
            </div>

            <div class="section-title">Alıcı</div>
            <div class="receiver-name">{{ $label['receiver_name'] ?: '—' }}</div>
            <div class="meta" style="margin-top:6px;">
                <div>{{ $label['receiver_address'] ?: '—' }}</div>
                <div>{{ trim(($label['receiver_town'] ?? '').' / '.($label['receiver_city'] ?? ''), ' /') ?: '—' }}</div>
                <div>Tel: {{ $label['receiver_phone'] ?: '—' }}</div>
            </div>

            <div class="grid">
                <div class="item">
                    <strong>Takip / cargoKey</strong>
                    <span>{{ $label['cargo_key'] ?: '—' }}</span>
                </div>
                <div class="item">
                    <strong>Ağırlık</strong>
                    <span>{{ $label['weight'] ? $label['weight'].' kg' : '—' }}</span>
                </div>
            </div>

            <div class="barcode-wrap">
                <div class="section-title" style="margin-top:0;">
                    @if (($label['provider'] ?? '') === 'yurtici')
                        Şube barkodu (cargoKey · CODE128)
                    @else
                        Barkod (CODE128)
                    @endif
                </div>
                @if (filled($label['barcode_value']))
                    <canvas id="barcode-{{ $index }}"></canvas>
                    <div class="cargo-key">{{ $label['barcode_value'] }}</div>
                    @if (($label['provider'] ?? '') === 'yurtici')
                        <div class="hint">Yurtiçi şubesi bu CODE128 barkodu okutarak gönderiyi kabul eder.</div>
                    @endif
                @else
                    <div class="hint">Barkod değeri yok.</div>
                @endif
            </div>
        </div>
    @endforeach

    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <script>
        @foreach ($labels as $index => $label)
            @if (filled($label['barcode_value']))
                try {
                    JsBarcode('#barcode-{{ $index }}', @json($label['barcode_value']), {
                        format: 'CODE128',
                        displayValue: false,
                        width: 2,
                        height: 72,
                        margin: 0,
                        background: '#ffffff',
                        lineColor: '#000000'
                    });
                } catch (e) {}
            @endif
        @endforeach
    </script>
</body>
</html>
