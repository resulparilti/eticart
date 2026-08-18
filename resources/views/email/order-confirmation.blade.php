@extends('email.layout')

@section('content')
    <h1 style="font-size:20px;margin:0 0 16px;">Sipariş Onayı</h1>
    <p>Merhaba <strong>{{ $customerName }}</strong>,</p>
    <p><strong>{{ $order->order_number }}</strong> numaralı siparişiniz başarıyla alındı.</p>
    @if ($body)
        <x-template-html :content="$body" />
    @else
        <p>Toplam tutar: <strong>₺{{ number_format((float) $order->total_price, 2) }} {{ $order->currency }}</strong></p>
    @endif
@endsection
