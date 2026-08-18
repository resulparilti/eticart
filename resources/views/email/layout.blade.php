@php
    $mailBrandName = trim((string) ($brand['name'] ?? ''));
    if ($mailBrandName === '') {
        $mailBrandName = trim((string) \App\Models\Setting::getValue('general_company_name', ''));
    }
    if ($mailBrandName === '') {
        $mailBrandName = trim((string) \App\Models\Setting::getValue('general_app_name', config('app.name', '')));
    }
@endphp
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? $mailBrandName }}</title>
</head>
<body style="margin:0;padding:0;background:#eef3f7;font-family:Arial,Helvetica,sans-serif;color:#142433;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef3f7;padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #d9e2ec;">
                    <tr>
                        <td style="padding:28px 28px 8px;font-size:15px;line-height:1.7;color:#142433;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 28px 24px;font-size:13px;line-height:1.6;color:#5b6b7c;">
                            @hasSection('links')
                                @yield('links')
                            @endif
                            <p style="margin:16px 0 0;">Teşekkürler,<br><strong>{{ $mailBrandName }}</strong></p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px 22px;font-size:12px;color:#5b6b7c;border-top:1px solid #d9e2ec;">
                            &copy; {{ date('Y') }} {{ $mailBrandName }}. Tüm hakları saklıdır.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
