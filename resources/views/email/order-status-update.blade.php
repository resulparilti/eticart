@extends('email.layout')

@section('content')
    <h1 style="font-size:20px;margin:0 0 16px;">Sipariş Durumu Güncellendi</h1>
    <p>Merhaba <strong>{{ $customerName }}</strong>,</p>
    <p><strong>{{ $order->order_number }}</strong> numaralı siparişinizin yeni durumu:</p>
    <p><strong>{{ $status }}</strong></p>
    @if ($body)
        <x-template-html :content="$body" />
    @endif
@endsection
