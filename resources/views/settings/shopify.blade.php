@extends('layouts.app')

@section('title', 'Shopify Ayarları')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">Shopify Ayarları</h1>
            <p class="eticart-muted mb-0">Admin API token veya Partner uygulaması (OAuth) ile bağlanın.</p>
        </div>
        <a href="{{ route('settings.index') }}" class="btn btn-outline-secondary">Geri</a>
    </div>

    <div class="eticart-card p-3 mb-3" style="max-width: 820px;">
        <h2 class="h6 mb-2">Sipariş durumu (Fulfilled) yetkisi</h2>
        <p class="small eticart-muted mb-3">
            Etiket ve fatura linki <code>write_orders</code> ile gider. Shopify’daki siparişi
            <strong>Fulfilled / Kargoya verildi</strong> yapmak için fulfillment izinleri şarttır.
            İzin eklendikten sonra <strong>eski token çalışmaz</strong> — yeni Access Token üretilmelidir.
        </p>
        @if (! $shopifyConfigured)
            <div class="alert alert-danger small mb-3">
                Access token kayıtlı değil. Dev Dashboard’da kapsam eklemek yetmez;
                Shopify uygulamasını <strong>yeniden bağlayıp izin vermeniz</strong> gerekir.
                Eski token 401 sonrası silindiği için senkron durur.
            </div>
        @elseif ($scopeError)
            <div class="alert alert-warning small">Yetki listesi okunamadı: {{ $scopeError }}</div>
        @elseif ($missingFulfillmentScopes === [])
            <div class="alert alert-success small mb-3">Fulfillment izinleri bu token’da tanımlı.</div>
        @else
            <div class="alert alert-danger small mb-3">
                Bu token’da eksik izinler:
                @foreach ($missingFulfillmentScopes as $scope)
                    <code class="me-1">{{ $scope }}</code>
                @endforeach
            </div>
        @endif
        @if ($oauthConfigured && filled($settings['shopify_store_url'] ?? null))
            <form method="POST" action="{{ route('settings.shopify.reconnect') }}" class="mb-3">
                @csrf
                <button type="submit" class="btn btn-primary">Shopify’ı yeniden bağla</button>
                <span class="small eticart-muted ms-2">Shopify izin ekranı açılır; onaylayınca yeni token kaydedilir.</span>
            </form>
        @endif
        @if ($grantedScopes !== [])
            <p class="small mb-2">Token’daki mevcut izinler:</p>
            <p class="small mb-3">
                @foreach ($grantedScopes as $scope)
                    <code class="me-1">{{ $scope }}</code>
                @endforeach
            </p>
        @endif
        <h3 class="h6 mt-2">Dev Dashboard (önerilen)</h3>
        <ol class="small mb-3">
            <li><a href="https://dev.shopify.com/dashboard" target="_blank" rel="noopener">dev.shopify.com/dashboard</a> → EtiCart uygulaması</li>
            <li><strong>Versions / Sürümler</strong> → <strong>Create version</strong> (mevcut Kapsamlar sayfası yetmez; yeni sürüm şart)</li>
            <li><strong>Access → Select scopes / Kapsam seç</strong> içinde arama kutusuna şunları yazın (API kodu değil):
                <ul class="mt-1 mb-1">
                    <li><strong>Merchant-managed fulfillment orders</strong> → Read + Write (asıl gerekli olan bu)</li>
                    <li><strong>Fulfillments</strong> → isteğe bağlı; tek başına yeterli değil</li>
                </ul>
            </li>
            <li>Sağ üstten <strong>Release</strong></li>
            <li>Mağazada uygulamayı <strong>kaldırıp tekrar yükleyin</strong> veya EtiCart’tan OAuth’u yeniden başlatın. Sürüm yayınlamak eski token’ı güncellemez.</li>
        </ol>
        <p class="small eticart-muted mb-2">Kapsamlar kutusuna yapıştırılacak liste:</p>
        <textarea class="form-control form-control-sm mb-3" rows="3" readonly onclick="this.select()">{{ \App\Services\ShopifyOAuthService::DEFAULT_SCOPES }}</textarea>

        <h3 class="h6">Mağaza içi “Uygulama geliştir” (eski custom app)</h3>
        <ol class="small mb-0">
            <li>Shopify Admin → <strong>Ayarlar → Uygulamalar → Uygulama geliştir</strong></li>
            <li>Admin API entegrasyonu → aynı izinler → kaydet → <strong>token’ı yeniden oluştur</strong></li>
        </ol>
    </div>

    <div class="eticart-card p-3 mb-3" style="max-width: 820px;">
        <h2 class="h6 mb-2">Shopify Dev Dashboard’a yapıştırın</h2>
        <p class="small eticart-muted mb-3">
            Shopify localhost kabul etmez. Aşağıdaki adresler <code>SHOPIFY_APP_URL</code> / genel HTTPS tünel adresine göre üretilir.
            @unless ($oauthUrls['is_public_https'])
                <strong class="text-warning">Şu an localhost görünüyor — önce Cloudflare Tunnel veya Ngrok açın.</strong>
            @endunless
        </p>

        <div class="mb-3">
            <label class="form-label">Uygulama URL’si</label>
            <input class="form-control" readonly value="{{ $oauthUrls['app_url'] }}" onclick="this.select()">
        </div>
        <div class="mb-3">
            <label class="form-label">Yönlendirme URL’leri (Allowed redirection URL(s))</label>
            @foreach ($oauthUrls['redirect_urls'] as $url)
                <input class="form-control mb-2" readonly value="{{ $url }}" onclick="this.select()">
            @endforeach
        </div>
        <div class="mb-0">
            <label class="form-label">Compliance webhook’ları</label>
            @foreach ($oauthUrls['webhook_urls'] as $topic => $url)
                <div class="small mb-1"><code>{{ $topic }}</code></div>
                <input class="form-control mb-2" readonly value="{{ $url }}" onclick="this.select()">
            @endforeach
        </div>
        <p class="small eticart-muted mb-0 mt-2">
            Tünel örneği (ayrı terminal): <code>cloudflared tunnel --url http://127.0.0.1:8000</code>
            veya <code>ngrok http 8000</code>. Çıkan <code>https://….trycloudflare.com</code> adresini aşağıya
            <strong>Genel uygulama adresi</strong> olarak kaydedin, sonra bu kutuları tekrar kopyalayın.
        </p>
    </div>

    <div class="eticart-card p-3" style="max-width: 820px;">
        <form method="POST" action="{{ route('settings.shopify.update') }}">
            @csrf
            @method('PUT')

            <h2 class="h6 mb-3">Partner uygulaması (OAuth)</h2>
            <div class="mb-3">
                <label class="form-label">Genel uygulama adresi (SHOPIFY_APP_URL)</label>
                <input type="url" name="shopify_app_url" class="form-control" value="{{ old('shopify_app_url', $settings['shopify_app_url'] ?? '') }}" placeholder="https://xxxx.trycloudflare.com">
            </div>
            <div class="mb-3">
                <label class="form-label">Client ID (API key)</label>
                <input type="text" name="shopify_api_key" class="form-control" value="{{ old('shopify_api_key', $settings['shopify_api_key'] ?? '') }}">
            </div>
            <div class="mb-3">
                <label class="form-label">Client secret</label>
                <input type="password" name="shopify_api_secret" class="form-control" value="" autocomplete="new-password" placeholder="{{ filled($settings['shopify_api_secret'] ?? null) ? 'Kayıtlı — değiştirmek için yazın' : 'Client secret' }}">
            </div>
            <div class="mb-4">
                <label class="form-label">OAuth kapsamları</label>
                <textarea name="shopify_scopes" rows="3" class="form-control">{{ old('shopify_scopes', $settings['shopify_scopes'] ?? $oauthUrls['scopes']) }}</textarea>
            </div>

            <h2 class="h6 mb-3">Mevcut Admin API (custom app token)</h2>
            <div class="mb-3">
                <label class="form-label">Store URL</label>
                <input type="text" name="shopify_store_url" class="form-control" value="{{ old('shopify_store_url', $settings['shopify_store_url'] ?? '') }}" placeholder="your-store.myshopify.com">
            </div>
            <div class="mb-3">
                <label class="form-label">Access Token</label>
                <input type="password" name="shopify_access_token" class="form-control" value="" autocomplete="new-password" placeholder="{{ filled($settings['shopify_access_token'] ?? null) ? 'Kayıtlı — değiştirmek için yazın' : 'Access Token' }}">
            </div>
            <div class="mb-3">
                <label class="form-label">API Version</label>
                <input type="text" name="shopify_api_version" class="form-control" value="{{ old('shopify_api_version', $settings['shopify_api_version'] ?? '2024-01') }}">
            </div>
            <div class="mb-3">
                <label class="form-label">Location ID</label>
                <input type="text" name="shopify_location_id" class="form-control" value="{{ old('shopify_location_id', $settings['shopify_location_id'] ?? '') }}">
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Kaydet</button>
            </div>
        </form>

        <form method="POST" action="{{ route('settings.shopify.test') }}" class="mt-3">
            @csrf
            <button type="submit" class="btn btn-outline-primary">Bağlantıyı Test Et</button>
        </form>
    </div>

    <div class="eticart-card p-3 mt-3" style="max-width: 820px;">
        <h2 class="h6">Müşteri bilgisi gelmiyorsa</h2>
        <p class="small eticart-muted mb-2">
            Shopify, özel uygulamalarda ad / e-posta / telefon / adresi <strong>Protected Customer Data</strong> ile korur.
            Partner uygulamasında <code>read_customers</code> + <code>read_orders</code> ve Level 2 PII erişimi gerekir.
        </p>
        <ol class="small mb-0">
            <li>Dev Dashboard’da uygulamayı oluşturun; yukarıdaki URL’leri yapıştırın</li>
            <li>Client ID / Secret’i bu sayfaya kaydedin</li>
            <li>Mağazaya uygulamayı yükleyin (OAuth) veya custom app token kullanın</li>
            <li>Protected Customer Data → Level 2 (name, email, phone, address)</li>
        </ol>
    </div>
@endsection
