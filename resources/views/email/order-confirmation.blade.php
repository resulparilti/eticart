@extends('email.layout')

@section('heading', 'Siparişiniz alındı')

@section('content')
    @if ($body)
        <x-template-html :content="$body" />
    @else
        <p style="margin:0 0 14px;">Merhaba <strong>{{ $customerName }}</strong>,</p>
        <p style="margin:0 0 14px;"><strong>{{ $order->order_number }}</strong> numaralı siparişiniz alındı.</p>
        <p style="margin:0;">Toplam tutar: <strong>{{ number_format((float) $order->total_price, 2) }} {{ $order->currency }}</strong></p>
    @endif
@endsection
