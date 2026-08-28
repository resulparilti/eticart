@extends('email.layout')

@section('heading', 'Sipariş durumu güncellendi')

@section('content')
    @if ($body)
        <x-template-html :content="$body" />
    @else
        <p style="margin:0 0 14px;">Merhaba <strong>{{ $customerName }}</strong>,</p>
        <p style="margin:0;">
            <strong>{{ $order->order_number }}</strong> numaralı siparişinizin yeni durumu:
            <strong>{{ $status }}</strong>
        </p>
    @endif
@endsection
