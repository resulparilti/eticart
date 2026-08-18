<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>Kargo Faturası - {{ $shipment->order_number }}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 32px; color: #222; }
        h1 { font-size: 22px; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; font-size: 13px; }
        th { background: #f5f5f5; }
        .totals { margin-top: 16px; width: 280px; margin-left: auto; }
        .totals td { border: none; padding: 4px 0; }
        .muted { color: #666; font-size: 12px; }
        @media print { button { display: none; } }
    </style>
</head>
<body>
    <button onclick="window.print()">Yazdır</button>
    <h1>Kargo Faturası</h1>
    <div class="muted">EtiCart — {{ now()->format('d.m.Y H:i') }}</div>

    <p>
        <strong>Sipariş:</strong> {{ $shipment->order_number }}<br>
        <strong>Firma:</strong> {{ $shipment->cargoCompany->name ?? '-' }}<br>
        <strong>Takip:</strong> {{ $shipment->tracking_number ?: '-' }}<br>
        <strong>Alıcı:</strong> {{ $shipment->receiver_name }} / {{ $shipment->receiver_phone }}
    </p>

    <table>
        <thead>
            <tr>
                <th>Ürün</th>
                <th>Adet</th>
                <th>Fiyat</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($shipment->order?->items ?? [] as $item)
                <tr>
                    <td>{{ $item->product_title }} {{ $item->variant_title ? '('.$item->variant_title.')' : '' }}</td>
                    <td>{{ $item->quantity }}</td>
                    <td>₺{{ number_format((float) $item->price, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3">Kalem bulunamadı</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>Sipariş tutarı</td>
            <td align="right">₺{{ number_format((float) ($shipment->amount ?? 0), 2) }}</td>
        </tr>
        <tr>
            <td>Kargo ücreti</td>
            <td align="right">₺{{ number_format((float) ($shipment->cargo_cost ?? 0), 2) }}</td>
        </tr>
        <tr>
            <td>Sigorta</td>
            <td align="right">₺{{ number_format((float) ($shipment->insurance ?? 0), 2) }}</td>
        </tr>
    </table>

    <p class="muted" style="margin-top:24px;">
        Bu belge bilgilendirme amaçlıdır. Resmi e-fatura UyumSoft üzerinden üretilir.
    </p>
</body>
</html>
