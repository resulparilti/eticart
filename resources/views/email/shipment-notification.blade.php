@extends('email.layout')

@section('heading', 'Siparişiniz kargoya verildi')

@section('content')
    @if ($body)
        <x-template-html :content="$body" />
    @else
        <p style="margin:0 0 14px;">Merhaba <strong>{{ $customerName }}</strong>,</p>
        <p style="margin:0 0 22px;">
            <strong>{{ $shipment->order_number }}</strong> numaralı siparişiniz kargoya verildi.
        </p>
        <p style="margin:0 0 16px;">Takip numarası: <strong>{{ $shipment->tracking_number }}</strong></p>
        @if ($shipment->tracking_url)
            @include('email.partials.button', [
                'url' => $shipment->tracking_url,
                'label' => 'Kargoyu takip et',
                'background' => $brand['button_bg'] ?? '#000000',
                'color' => $brand['button_text'] ?? '#ffffff',
            ])
            <div style="clear:both;height:10px;line-height:10px;font-size:10px;">&nbsp;</div>
            <p style="margin:8px 0 0;font-size:12px;word-break:break-all;">
                <a href="{{ $shipment->tracking_url }}" target="_blank" style="color:{{ $brand['link'] ?? '#c45c26' }};text-decoration:underline;">{{ $shipment->tracking_url }}</a>
            </p>
        @endif
    @endif
@endsection
