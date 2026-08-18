<?php

declare(strict_types=1);

namespace App\Services\Cargo;

use App\Models\CargoCompany;
use Illuminate\Support\Str;

abstract class AbstractCargoService implements CargoServiceInterface
{
    public function __construct(
        protected readonly CargoCompany $company
    ) {
    }

    public function getProviderType(): string
    {
        return $this->company->provider_type;
    }

    public function isConfigured(): bool
    {
        return filled($this->company->api_key)
            || filled($this->company->username)
            || filled($this->company->api_secret);
    }

    /**
     * Local/dev shipment creation when provider credentials are missing.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function createLocalShipment(array $data): array
    {
        $tracking = strtoupper($this->getProviderType()).'-'.Str::upper(Str::random(10));

        return [
            'success' => true,
            'mode' => 'local',
            'tracking_number' => $tracking,
            'tracking_url' => $this->buildTrackingUrl($tracking),
            'label_content' => null,
            'raw' => [
                'message' => 'Yerel kargo kaydı oluşturuldu (API credential yok).',
                'payload' => $data,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function localTrackingInfo(string $trackingNumber): array
    {
        return [
            'tracking_number' => $trackingNumber,
            'status' => 'shipped',
            'status_text' => 'Yolda (yerel simülasyon)',
            'events' => [
                [
                    'date' => now()->toDateTimeString(),
                    'description' => 'Kargo yola çıktı (local mode)',
                ],
            ],
            'mode' => 'local',
        ];
    }

    protected function buildTrackingUrl(string $trackingNumber): string
    {
        return match ($this->getProviderType()) {
            'aras' => 'https://www.araskargo.com.tr/tr/cargotrack?code='.$trackingNumber,
            'mng' => 'https://www.mngkargo.com.tr/tr/kargo-takip?kod='.$trackingNumber,
            'yurtici' => 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code='.$trackingNumber,
            'ptt' => 'https://gonderitakip.ptt.gov.tr/?barkod='.$trackingNumber,
            default => '#',
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function credentials(): array
    {
        return [
            'api_key' => $this->company->api_key,
            'api_secret' => $this->company->api_secret,
            'username' => $this->company->username,
            'password' => $this->company->password,
            'settings' => $this->company->settings ?? [],
        ];
    }
}
