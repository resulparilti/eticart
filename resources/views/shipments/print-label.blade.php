<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>Kargo Etiketi - {{ $shipment->tracking_number }}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #222; }
        .box { border: 2px solid #222; padding: 16px; max-width: 480px; }
        .brand { font-size: 20px; font-weight: bold; margin-bottom: 8px; }
        .tracking { font-size: 22px; letter-spacing: 1px; margin: 12px 0; }
        .meta { font-size: 13px; line-height: 1.5; }
        .barcode { margin-top: 16px; font-family: "Courier New", monospace; font-size: 28px; letter-spacing: 3px; }
        @media print { button { display: none; } }
    </style>
</head>
<body>
    <button onclick="window.print()">Yazdır</button>
    <div class="box">
        <div class="brand">{{ $shipment->cargoCompany->name ?? 'EtiCart Kargo' }}</div>
        <div class="meta">Sipariş: <strong>{{ $shipment->order_number }}</strong></div>
        <div class="tracking">{{ $shipment->tracking_number ?: 'TAKIP-YOK' }}</div>
        <div class="meta">
            <div><strong>Alıcı:</strong> {{ $shipment->receiver_name }}</div>
            <div><strong>Tel:</strong> {{ $shipment->receiver_phone ?: '-' }}</div>
            <div><strong>Adres:</strong> {{ $shipment->receiver_address }}</div>
            <div><strong>Şehir:</strong> {{ $shipment->receiver_city ?: '-' }}</div>
            <div><strong>Ağırlık:</strong> {{ $shipment->weight ? $shipment->weight.' kg' : '-' }}</div>
        </div>
        <div class="barcode">*{{ $shipment->tracking_number ?: $shipment->id }}*</div>
        <div class="meta" style="margin-top:12px;">QR: {{ url('/shipments/'.$shipment->id) }}</div>
    </div>
</body>
</html>
