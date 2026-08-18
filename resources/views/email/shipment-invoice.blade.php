@php
    $brand = is_array($brand ?? null) ? $brand : [];
    $headerBg = $brand['header_bg'] ?? '#0f2a3d';
    $headerText = $brand['header_text'] ?? '#ffffff';
    $bodyText = $brand['body_text'] ?? '#142433';
    $mutedText = $brand['muted_text'] ?? '#5b6b7c';
    $linkColor = $brand['link'] ?? '#c45c26';
    $buttonBg = $brand['button_bg'] ?? '#0f2a3d';
    $buttonText = $brand['button_text'] ?? '#ffffff';
    $brandName = trim((string) ($brand['name'] ?? ''));
    $logoUrl = (string) ($brand['logo_url'] ?? '');
    $siteUrl = (string) ($brand['site_url'] ?? '');
    $accountUrl = (string) ($brand['account_url'] ?? '');
@endphp
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Siparişiniz kargoya verildi</title>
</head>
<body style="margin:0;padding:0;background:#eef3f7;font-family:Arial,Helvetica,sans-serif;color:{{ $bodyText }};">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef3f7;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #d9e2ec;">
                    <tr>
                        <td style="background:{{ $headerBg }};padding:22px 28px;color:{{ $headerText }};">
                            @if ($logoUrl !== '' && str_starts_with($logoUrl, 'https://'))
                                <img src="{{ $logoUrl }}" alt="{{ $brandName }}" height="36" style="display:block;max-height:36px;width:auto;margin-bottom:10px;border:0;">
                            @endif
                            <div style="font-size:22px;font-weight:700;color:{{ $headerText }};">Siparişiniz kargoya verildi</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;color:{{ $bodyText }};">
                            <p style="margin:0 0 14px;font-size:15px;line-height:1.6;">
                                Merhaba <strong>{{ $customerName }}</strong>,
                            </p>
                            <p style="margin:0 0 22px;font-size:15px;line-height:1.7;">
                                <strong>{{ $order->order_number }}</strong> numaralı siparişiniz kargoya teslim edildi.
                                @if ($hasAttachment ?? false)
                                    Fatura belgesi bu e-postanın ekinde yer alır. Dilerseniz aşağıdaki bağlantıdan da indirebilirsiniz.
                                @else
                                    Fatura belgesini aşağıdaki güvenli bağlantıdan indirebilirsiniz.
                                @endif
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #d9e2ec;border-radius:10px;overflow:hidden;margin-bottom:20px;">
                                <tr>
                                    <td style="padding:8px 20px;background:{{ $headerBg }};color:{{ $headerText }};font-size:12px;letter-spacing:.08em;text-transform:uppercase;">
                                        Kargo bilgisi
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <div style="font-size:18px;font-weight:700;margin-bottom:12px;">{{ $companyName }}</div>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="padding:5px 0;font-size:13px;color:{{ $mutedText }};width:40%;">Takip kodu</td>
                                                <td style="padding:5px 0;font-size:15px;font-weight:700;">{{ $shipment->tracking_number ?: '—' }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:5px 0;font-size:13px;color:{{ $mutedText }};">Kargoya veriliş</td>
                                                <td style="padding:5px 0;font-size:14px;">{{ optional($shipment->shipped_at ?? $shipment->created_at)->format('d.m.Y H:i') ?: '—' }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:5px 0;font-size:13px;color:{{ $mutedText }};">Güncel durum</td>
                                                <td style="padding:5px 0;font-size:14px;font-weight:600;">{{ $statusText ?: 'Kargoda' }}</td>
                                            </tr>
                                        </table>
                                        @if (filled($trackingUrl))
                                            <div style="margin-top:16px;">
                                                <a href="{{ $trackingUrl }}" style="display:inline-block;background:{{ $buttonBg }};color:{{ $buttonText }};text-decoration:none;padding:11px 16px;border-radius:8px;font-size:14px;font-weight:700;">Kargoyu takip et</a>
                                            </div>
                                            <div style="margin-top:8px;font-size:12px;color:{{ $mutedText }};word-break:break-all;">
                                                <a href="{{ $trackingUrl }}" style="color:{{ $linkColor }};">{{ $trackingUrl }}</a>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #d9e2ec;border-radius:10px;overflow:hidden;">
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <div style="font-size:15px;font-weight:700;margin-bottom:8px;">Fatura</div>
                                        <p style="margin:0 0 14px;font-size:14px;line-height:1.6;color:{{ $mutedText }};">
                                            @if ($hasAttachment ?? false)
                                                Faturanız e-posta ekinde gönderilmiştir. Eki göremiyorsanız aşağıdaki buton ile indirebilirsiniz.
                                            @else
                                                Faturanızı aşağıdaki buton ile indirebilirsiniz.
                                            @endif
                                        </p>
                                        @if (filled($invoiceUrl))
                                            <a href="{{ $invoiceUrl }}" style="display:inline-block;background:{{ $buttonBg }};color:{{ $buttonText }};text-decoration:none;padding:11px 16px;border-radius:8px;font-size:14px;font-weight:700;">Faturayı indir</a>
                                            <div style="margin-top:8px;font-size:12px;word-break:break-all;">
                                                <a href="{{ $invoiceUrl }}" style="color:{{ $linkColor }};">{{ $invoiceUrl }}</a>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:22px 0 0;font-size:13px;line-height:1.6;color:{{ $mutedText }};">
                                Sorunuz olursa bu e-postayı yanıtlayabilirsiniz.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px 24px;font-size:12px;color:{{ $mutedText }};border-top:1px solid #d9e2ec;">
                            @if ($siteUrl !== '' || $accountUrl !== '')
                                <p style="margin:0 0 10px;">
                                    @if ($siteUrl !== '')
                                        <a href="{{ $siteUrl }}" style="color:{{ $linkColor }};">Mağazamız</a>
                                    @endif
                                    @if ($siteUrl !== '' && $accountUrl !== '')
                                        &nbsp;·&nbsp;
                                    @endif
                                    @if ($accountUrl !== '')
                                        <a href="{{ $accountUrl }}" style="color:{{ $linkColor }};">Hesabım</a>
                                    @endif
                                </p>
                            @endif
                            <p style="margin:0;">Teşekkürler,<br><strong>{{ $brandName }}</strong></p>
                            <p style="margin:10px 0 0;">&copy; {{ date('Y') }} {{ $brandName }}. Tüm hakları saklıdır.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
