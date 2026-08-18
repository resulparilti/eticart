<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Kargo Barkodu' }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 12px;
            font-family: Arial, Helvetica, sans-serif;
            color: #111;
            background: #e8e8e8;
        }
        .toolbar {
            max-width: 210mm;
            margin: 0 auto 12px;
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
        .print-sheet {
            width: 210mm;
            height: 297mm;
            margin: 0 auto 16px;
            background: #fff;
            display: grid;
            grid-template-columns: 105mm 105mm;
            grid-template-rows: 99mm 99mm 99mm;
            page-break-after: always;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .12);
        }
        .print-sheet:last-child { page-break-after: auto; }
        .label-slot {
            width: 105mm;
            height: 99mm;
            display: flex;
            align-items: flex-start;
            justify-content: flex-start;
            overflow: hidden;
        }
        .label {
            width: 105mm;
            max-width: 105mm;
            border: 1px dashed #bbb;
            background: #fff;
            padding: 4mm 4mm 3.5mm;
        }
        .heading {
            font-family: "Times New Roman", Times, serif;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .2px;
            text-transform: uppercase;
            margin: 0 0 4px;
            line-height: 1.15;
        }
        .receiver {
            font-size: 9px;
            line-height: 1.4;
            text-transform: uppercase;
            white-space: pre-line;
            overflow: visible;
        }
        .receiver-phone {
            text-transform: none;
            letter-spacing: 0;
            word-break: break-all;
        }
        .barcode-block {
            margin: 7mm 0 0;
            text-align: center;
        }
        .barcode-block canvas {
            max-width: 100%;
            height: auto !important;
            display: block;
            margin: 0 auto;
        }
        .barcode-text {
            margin-top: 4px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .5px;
            font-family: "Courier New", monospace;
            text-transform: uppercase;
        }
        .rule {
            border: 0;
            border-top: 1px solid #111;
            margin: 5px 0 4px;
        }
        .sender {
            font-size: 8px;
            line-height: 1.35;
        }
        .sender strong { font-weight: 700; }
        .job-barcode-block {
            margin-top: 4px;
            text-align: center;
        }
        .job-barcode-block canvas {
            max-width: 85%;
            height: auto !important;
            display: block;
            margin: 0 auto;
        }
        .missing {
            margin-top: 8px;
            text-align: center;
            color: #666;
            font-size: 8px;
        }
        @media print {
            body { background: #fff; padding: 0; }
            .toolbar { display: none !important; }
            .print-sheet {
                margin: 0;
                box-shadow: none;
                page-break-inside: avoid;
            }
            .label { border: 1px dashed #999; }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <div>{{ count($labels) }} barkod · A4 (6 etiket/sayfa)</div>
        <div>
            <button type="button" onclick="window.print()">Yazdır</button>
            <a href="{{ $backUrl ?? route('orders.index') }}">Geri</a>
        </div>
    </div>

    @php
        $labelIndex = 0;
        $perPage = 6;
        $sheets = collect($labels)->chunk($perPage);
    @endphp

    @foreach ($sheets as $sheetLabels)
        <div class="print-sheet">
            @foreach ($sheetLabels as $label)
                @php
                    $company = $label['company'] ?? \App\Support\ShippingLabelProfile::company();
                    $address = \App\Support\ShippingLabelProfile::formatAddress($label);
                    $barcode = (string) ($label['barcode_value'] ?? '');
                    $jobBarcode = trim((string) ($label['job_barcode_value'] ?? $label['job_id'] ?? ''));
                    $receiverLines = array_values(array_filter([
                        mb_strtoupper((string) ($label['receiver_name'] ?? ''), 'UTF-8'),
                        $address !== '' ? mb_strtoupper($address, 'UTF-8') : null,
                    ]));
                    $phone = trim((string) ($label['receiver_phone'] ?? ''));
                    $senderLine = trim(implode(', ', array_filter([
                        $company['name'] !== '' ? $company['name'] : null,
                        trim($company['address'].($company['phone'] !== '' ? ', '.$company['phone'] : ''), ', '),
                    ])), ', ');
                    $currentIndex = $labelIndex;
                    $labelIndex++;
                @endphp
                <div class="label-slot">
                    <div class="label">
                        <h1 class="heading">TESLİMAT ADRESİ</h1>
                        <div class="receiver">{{ implode("\n", $receiverLines) }}</div>
                        @if ($phone !== '')
                            <div class="receiver-phone">TEL: {{ $phone }}</div>
                        @endif

                        <div class="barcode-block">
                            @if ($barcode !== '')
                                <canvas id="barcode-{{ $currentIndex }}"></canvas>
                                <div class="barcode-text">{{ $barcode }}</div>
                            @else
                                <div class="missing">Barkod yok. Önce kargo servisine gönderin.</div>
                            @endif
                        </div>

                        <hr class="rule">

                        <div class="sender">
                            <div><strong>Gönderen:</strong> {{ $senderLine !== '' ? $senderLine : '—' }}</div>
                        </div>

                        @if ($jobBarcode !== '')
                            <div class="job-barcode-block">
                                <canvas id="job-barcode-{{ $currentIndex }}"></canvas>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach

            @for ($slot = $sheetLabels->count(); $slot < $perPage; $slot++)
                <div class="label-slot label-slot--empty"></div>
            @endfor
        </div>
    @endforeach

    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <script>
        @foreach ($labels as $index => $label)
            @if (filled($label['barcode_value'] ?? null))
                try {
                    JsBarcode('#barcode-{{ $index }}', @json((string) $label['barcode_value']), {
                        format: 'CODE128',
                        displayValue: false,
                        width: 1.8,
                        height: 44,
                        margin: 0,
                        background: '#ffffff',
                        lineColor: '#000000'
                    });
                } catch (e) {}
            @endif
            @php
                $jobVal = trim((string) ($label['job_barcode_value'] ?? $label['job_id'] ?? ''));
            @endphp
            @if ($jobVal !== '')
                try {
                    JsBarcode('#job-barcode-{{ $index }}', @json($jobVal), {
                        format: 'CODE128',
                        displayValue: false,
                        width: 1.2,
                        height: 28,
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
