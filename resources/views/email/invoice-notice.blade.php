@extends('email.layout')

@section('heading', 'Faturanız hazır')

@section('content')
    @php
        $linkColor = $brand['link'] ?? '#c45c26';
        $buttonBg = $brand['button_bg'] ?? '#000000';
        $buttonText = $brand['button_text'] ?? '#ffffff';
        $mutedText = $brand['muted_text'] ?? '#5b6b7c';
    @endphp
    <p style="margin:0 0 14px;">Merhaba <strong>{{ $customerName }}</strong>,</p>
    <p style="margin:0 0 22px;">
        {{ $storeHost }} üzerinden yapmış olduğunuz
        <strong>{{ $order->order_number }}</strong>
        numaralı siparişiniz için oluşturulan faturayı aşağıdaki bağlantıdan indirebilirsiniz.
    </p>

    @if (filled($invoiceUrl))
        @include('email.partials.button', [
            'url' => $invoiceUrl,
            'label' => 'Faturayı indir',
            'background' => $buttonBg,
            'color' => $buttonText,
            'width' => 200,
        ])
        <div style="clear:both;height:8px;line-height:8px;font-size:8px;">&nbsp;</div>
        <p style="margin:8px 0 0;font-size:12px;word-break:break-all;">
            <a href="{{ $invoiceUrl }}" target="_blank" style="color:{{ $linkColor }};text-decoration:underline;">{{ $invoiceUrl }}</a>
        </p>
    @endif

    <p style="margin:22px 0 0;color:{{ $mutedText }};">Bizi tercih ettiğiniz için teşekkür ederiz.</p>
@endsection
