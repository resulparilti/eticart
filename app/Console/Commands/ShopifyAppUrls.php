<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ShopifyOAuthService;
use Illuminate\Console\Command;

class ShopifyAppUrls extends Command
{
    protected $signature = 'shopify:app-urls';

    protected $description = 'Shopify Dev Dashboard için Uygulama URL ve yönlendirme URL listesini yazdırır.';

    public function handle(ShopifyOAuthService $oauth): int
    {
        $urls = $oauth->dashboardUrls();

        $this->info('Shopify Dev Dashboard URL’leri');
        $this->line('Genel adres : '.$urls['base']);
        $this->line('Uygulama URL’si : '.$urls['app_url']);
        $this->newLine();
        $this->line('Yönlendirme URL’leri:');
        foreach ($urls['redirect_urls'] as $url) {
            $this->line('  - '.$url);
        }
        $this->newLine();
        $this->line('Compliance webhook’ları (isteğe bağlı / zorunlu olabilir):');
        foreach ($urls['webhook_urls'] as $topic => $url) {
            $this->line("  {$topic} : {$url}");
        }
        $this->newLine();
        $this->line('Önerilen Admin API kapsamları:');
        $this->line('  '.$urls['scopes']);

        if (! $urls['is_public_https']) {
            $this->newLine();
            $this->warn('Bu adres henüz Shopify tarafından kabul edilmez (localhost / http).');
            $this->warn('Cloudflare Tunnel veya Ngrok ile HTTPS genel adres alın, .env içine SHOPIFY_APP_URL olarak yazın.');
            $this->line('Örnek: cloudflared tunnel --url http://127.0.0.1:8000');
        }

        return self::SUCCESS;
    }
}
