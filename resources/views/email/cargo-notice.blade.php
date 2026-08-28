@extends('email.layout')

@section('heading', 'Siparişiniz kargoya verildi')

@section('content')
    @php
        $linkColor = $brand['link'] ?? '#c45c26';
        $buttonBg = $brand['button_bg'] ?? '#000000';
        $buttonText = $brand['button_text'] ?? '#ffffff';
        $headerBg = $brand['header_bg'] ?? '#000000';
        $headerText = $brand['header_text'] ?? '#ffffff';
        $mutedText = $brand['muted_text'] ?? '#5b6b7c';
        $bodyText = $brand['body_text'] ?? '#142433';
    @endphp
    <p style="margin:0 0 14px;">Merhaba <strong>{{ $customerName }}</strong>,</p>
    <p style="margin:0 0 22px;">
        {{ $storeHost }} üzerinden yapmış olduğunuz
        <strong>{{ $order->order_number }}</strong>
        numaralı siparişiniz kargoya verilmiştir.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e5e5e5;margin:0 0 18px;">
        <tr>
            <td align="center" bgcolor="{{ $headerBg }}" style="background-color:{{ $headerBg }};padding:10px 16px;font-family:Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:{{ $headerText }};font-weight:700;">
                Kargo bilgisi
            </td>
        </tr>
        <tr>
            <td style="padding:18px 16px;">
                <div style="font-size:18px;font-weight:700;margin:0 0 12px;color:{{ $bodyText }};">{{ $companyName }}</div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                        <td style="padding:5px 0;font-size:13px;color:{{ $mutedText }};width:42%;">Takip numarası</td>
                        <td style="padding:5px 0;font-size:15px;font-weight:700;color:{{ $bodyText }};">{{ $shipment->tracking_number ?: '—' }}</td>
                    </tr>
                    <tr>
                        <td style="padding:5px 0;font-size:13px;color:{{ $mutedText }};">Güncel durum</td>
                        <td style="padding:5px 0;font-size:14px;font-weight:600;color:{{ $bodyText }};">{{ $statusText ?: 'Kargoda' }}</td>
                    </tr>
                </table>
                @if (filled($trackingUrl))
                    <div style="margin-top:16px;">
                        @include('email.partials.button', [
                            'url' => $trackingUrl,
                            'label' => 'Kargoyu takip et',
                            'background' => $buttonBg,
                            'color' => $buttonText,
                            'width' => 220,
                        ])
                        <div style="clear:both;height:8px;line-height:8px;font-size:8px;">&nbsp;</div>
                        <p style="margin:8px 0 0;font-size:12px;word-break:break-all;">
                            <a href="{{ $trackingUrl }}" target="_blank" style="color:{{ $linkColor }};text-decoration:underline;">{{ $trackingUrl }}</a>
                        </p>
                    </div>
                @endif
            </td>
        </tr>
    </table>

    <p style="margin:0;color:{{ $mutedText }};">Bizi tercih ettiğiniz için teşekkür ederiz.</p>
@endsection
