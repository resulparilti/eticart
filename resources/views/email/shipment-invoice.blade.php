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
        <strong>{{ $order->order_number }}</strong> numaralı siparişiniz kargoya teslim edildi.
        @if ($hasAttachment ?? false)
            Fatura belgesi bu e-postanın ekinde yer alır. Dilerseniz aşağıdaki bağlantıdan da indirebilirsiniz.
        @else
            Fatura belgesini aşağıdaki güvenli bağlantıdan indirebilirsiniz.
        @endif
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
                        <td style="padding:5px 0;font-size:13px;color:{{ $mutedText }};width:42%;">Takip kodu</td>
                        <td style="padding:5px 0;font-size:15px;font-weight:700;color:{{ $bodyText }};">{{ $shipment->tracking_number ?: '—' }}</td>
                    </tr>
                    <tr>
                        <td style="padding:5px 0;font-size:13px;color:{{ $mutedText }};">Kargoya veriliş</td>
                        <td style="padding:5px 0;font-size:14px;color:{{ $bodyText }};">{{ optional($shipment->shipped_at ?? $shipment->created_at)->format('d.m.Y H:i') ?: '—' }}</td>
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

    @if (filled($invoiceUrl) || ($hasAttachment ?? false))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e5e5e5;margin:0;">
            <tr>
                <td style="padding:18px 16px;">
                    <div style="font-size:15px;font-weight:700;margin:0 0 8px;color:{{ $bodyText }};">Fatura</div>
                    <p style="margin:0 0 14px;font-size:14px;line-height:1.6;color:{{ $mutedText }};">
                        @if ($hasAttachment ?? false)
                            Faturanız e-posta ekinde gönderilmiştir. Eki göremiyorsanız aşağıdaki buton ile indirebilirsiniz.
                        @else
                            Fatura e-postaya ekli değildir. Aşağıdaki güvenli bağlantıdan indirebilirsiniz.
                        @endif
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
                </td>
            </tr>
        </table>
    @endif
@endsection
