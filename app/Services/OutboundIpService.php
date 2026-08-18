<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sunucunun dışarı çıkış (public) IP adresi — Yurtiçi vb. whitelist için.
 */
final class OutboundIpService
{
    private const CACHE_KEY = 'eticart.server_outbound_ip';

    private const CACHE_TTL_MINUTES = 60;

    /**
     * @return array{ip: ?string, cached: bool, error: ?string}
     */
    public function status(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return ['ip' => $cached, 'cached' => true, 'error' => null];
        }

        $ip = $this->fetchFromNetwork();

        if ($ip !== null) {
            Cache::put(self::CACHE_KEY, $ip, now()->addMinutes(self::CACHE_TTL_MINUTES));

            return ['ip' => $ip, 'cached' => false, 'error' => null];
        }

        return [
            'ip' => null,
            'cached' => false,
            'error' => 'Çıkış IP şu an alınamadı.',
        ];
    }

    public function ip(bool $refresh = false): ?string
    {
        return $this->status($refresh)['ip'];
    }

    private function fetchFromNetwork(): ?string
    {
        $endpoints = [
            'https://api.ipify.org',
            'https://ifconfig.me/ip',
        ];

        foreach ($endpoints as $url) {
            try {
                $response = Http::timeout(8)
                    ->withHeaders(['User-Agent' => 'EtiCart/1.0'])
                    ->get($url);

                if (! $response->successful()) {
                    continue;
                }

                $candidate = trim($response->body());
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            } catch (\Throwable $e) {
                Log::debug('Outbound IP fetch failed', ['url' => $url, 'message' => $e->getMessage()]);
            }
        }

        return null;
    }
}
