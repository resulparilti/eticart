<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EtiCart — Shopify Uygulaması</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5" style="max-width: 640px;">
        <h1 class="h3 mb-2">EtiCart Shopify bağlantısı</h1>
        <p class="text-secondary">Bu adres Shopify Dev Dashboard’daki <strong>Uygulama URL’si</strong>dir.</p>

        @if (session('error') || ! empty($error))
            <div class="alert alert-danger">{{ session('error') ?: $error }}</div>
        @endif

        @if (! $oauthConfigured)
            <div class="alert alert-warning">
                Client ID / Secret henüz kaydedilmedi. EtiCart → Ayarlar → Shopify ekranına Partner uygulamasının
                API key ve secret değerlerini girin.
            </div>
        @endif

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('shopify.install') }}">
                    <label class="form-label">Mağaza adresi</label>
                    <input type="text" name="shop" class="form-control mb-3" required
                           value="{{ old('shop', $shop) }}" placeholder="magaza.myshopify.com">
                    <button class="btn btn-primary" type="submit" @disabled(! $oauthConfigured)>
                        Shopify’a bağla
                    </button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body small">
                <div class="fw-semibold mb-2">Dashboard’a yapıştırılacak adresler</div>
                <div class="mb-2"><span class="text-secondary">Uygulama URL’si</span><br><code>{{ $urls['app_url'] }}</code></div>
                <div class="mb-2"><span class="text-secondary">Yönlendirme</span><br>
                    @foreach ($urls['redirect_urls'] as $url)
                        <code>{{ $url }}</code><br>
                    @endforeach
                </div>
                @unless ($urls['is_public_https'])
                    <div class="text-warning">
                        Bu adres localhost. Shopify kabul etmez; Cloudflare Tunnel / Ngrok HTTPS adresi kullanın
                        ve <code>SHOPIFY_APP_URL</code> değerini güncelleyin.
                    </div>
                @endunless
            </div>
        </div>
    </div>
</body>
</html>
