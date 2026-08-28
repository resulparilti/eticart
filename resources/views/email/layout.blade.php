@php
    $brand = is_array($brand ?? null) ? $brand : [];
    $headerBg = $brand['header_bg'] ?? '#000000';
    $headerText = $brand['header_text'] ?? '#ffffff';
    $bodyText = $brand['body_text'] ?? '#142433';
    $mutedText = $brand['muted_text'] ?? '#5b6b7c';
    $linkColor = $brand['link'] ?? '#c45c26';
    $buttonBg = $brand['button_bg'] ?? '#000000';
    $buttonText = $brand['button_text'] ?? '#ffffff';
    $logoUrl = trim((string) ($brand['logo_url'] ?? ''));
    $logoPath = (string) ($brand['logo_path'] ?? '');
    $siteUrl = trim((string) ($brand['site_url'] ?? ''));
    $accountUrl = trim((string) ($brand['account_url'] ?? ''));

    $mailBrandName = trim((string) ($brand['name'] ?? ''));
    if ($mailBrandName === '') {
        $mailBrandName = trim((string) \App\Models\Setting::getValue('general_company_name', ''));
    }
    if ($mailBrandName === '') {
        $mailBrandName = trim((string) \App\Models\Setting::getValue('general_app_name', config('app.name', '')));
    }
    if ($mailBrandName === '') {
        $mailBrandName = "O'renne";
    }

    $headingText = trim($__env->yieldContent('heading'));
    if ($headingText === '') {
        $headingText = trim((string) ($heading ?? $title ?? ''));
    }

    $logoSrc = '';
    if ($logoPath !== '' && is_readable($logoPath) && isset($message) && is_object($message) && method_exists($message, 'embed')) {
        try {
            $logoSrc = (string) $message->embed($logoPath);
        } catch (\Throwable) {
            $logoSrc = '';
        }
    }
    if ($logoSrc === '' && $logoUrl !== '') {
        $logoSrc = $logoUrl;
    }
@endphp
<!DOCTYPE html>
<html lang="tr" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $headingText !== '' ? $headingText : $mailBrandName }}</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:AllowPNG/>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <style type="text/css">
        table, td, th { font-family: Arial, Helvetica, sans-serif !important; }
        a { text-decoration: none; }
    </style>
    <![endif]-->
    <style type="text/css">
        :root { color-scheme: light; supported-color-schemes: light; }
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
        a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
        @media only screen and (max-width: 620px) {
            .email-shell { width: 100% !important; }
            .email-pad { padding-left: 16px !important; padding-right: 16px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background:#f3f3f3;font-family:Arial,Helvetica,sans-serif;color:{{ $bodyText }};">
    <div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">
        {{ $headingText !== '' ? $headingText : $mailBrandName }}
    </div>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f3f3;margin:0;padding:0;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" class="email-shell" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:600px;background:#ffffff;border:1px solid #e5e5e5;">
                    <tr>
                        <td align="center" bgcolor="{{ $headerBg }}" class="email-pad" style="background-color:{{ $headerBg }};padding:28px 24px 22px;color:{{ $headerText }};">
                            <!--[if mso]>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="font-family:Georgia,'Times New Roman',serif;font-size:28px;line-height:34px;color:{{ $headerText }};font-weight:bold;padding:4px 0 12px;">
                                {{ $mailBrandName }}
                            </td></tr></table>
                            <![endif]-->
                            <!--[if !mso]><!-->
                            @if ($logoSrc !== '')
                                <img src="{{ $logoSrc }}" alt="{{ $mailBrandName }}" width="180" style="display:block;margin:0 auto;max-width:180px;width:180px;height:auto;border:0;outline:none;text-decoration:none;font-family:Georgia,'Times New Roman',serif;font-size:26px;line-height:32px;color:{{ $headerText }};font-weight:bold;">
                            @else
                                <div style="font-family:Georgia,'Times New Roman',serif;font-size:28px;line-height:34px;color:{{ $headerText }};font-weight:bold;">{{ $mailBrandName }}</div>
                            @endif
                            <!--<![endif]-->
                            @if ($headingText !== '')
                                <div style="font-family:Arial,Helvetica,sans-serif;font-size:22px;line-height:28px;font-weight:700;color:{{ $headerText }};padding-top:16px;">
                                    {{ $headingText }}
                                </div>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="email-pad" style="padding:28px 28px 8px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.7;color:{{ $bodyText }};">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td class="email-pad" style="padding:8px 28px 24px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.6;color:{{ $mutedText }};">
                            @hasSection('links')
                                @yield('links')
                            @endif
                            <p style="margin:18px 0 0;color:{{ $bodyText }};">Sorunuz olursa bu e-postayı yanıtlayabilirsiniz.</p>
                        </td>
                    </tr>
                    <tr>
                        <td class="email-pad" style="padding:18px 28px 24px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.6;color:{{ $mutedText }};border-top:1px solid #e5e5e5;">
                            @if ($siteUrl !== '' || $accountUrl !== '')
                                <p style="margin:0 0 12px;">
                                    @if ($siteUrl !== '')
                                        <a href="{{ $siteUrl }}" target="_blank" style="color:{{ $linkColor }};text-decoration:underline;font-weight:600;">Mağazamız</a>
                                    @endif
                                    @if ($siteUrl !== '' && $accountUrl !== '')
                                        &nbsp;&nbsp;·&nbsp;&nbsp;
                                    @endif
                                    @if ($accountUrl !== '')
                                        <a href="{{ $accountUrl }}" target="_blank" style="color:{{ $linkColor }};text-decoration:underline;font-weight:600;">Hesabım</a>
                                    @endif
                                </p>
                            @endif
                            <p style="margin:0;color:{{ $bodyText }};">Teşekkürler,<br><strong>{{ $mailBrandName }}</strong></p>
                            <p style="margin:10px 0 0;">&copy; {{ date('Y') }} {{ $mailBrandName }}. Tüm hakları saklıdır.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
