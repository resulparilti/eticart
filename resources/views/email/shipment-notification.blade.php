@extends('email.layout')

@section('content')
    <h1 style="font-size:20px;margin:0 0 16px;">Kargo Bildirimi</h1>
    <p>Merhaba <strong>{{ $customerName }}</strong>,</p>
    <p><strong>{{ $shipment->order_number }}</strong> numaralı siparişiniz kargoya verildi.</p>
    @if ($body)
        <x-template-html :content="$body" />
    @else
        <p>Takip numarası: <strong>{{ $shipment->tracking_number }}</strong></p>
        @if ($shipment->tracking_url)
            <p><a href="{{ $shipment->tracking_url }}" style="display:inline-block;background:#0f2a3d;color:#ffffff;text-decoration:none;padding:11px 16px;border-radius:8px;font-weight:700;">Kargoyu Takip Et</a></p>
        @endif
    @endif
@endsection
