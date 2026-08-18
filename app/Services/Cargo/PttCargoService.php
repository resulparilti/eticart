<?php

declare(strict_types=1);

namespace App\Services\Cargo;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PttCargoService extends AbstractCargoService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createShipment(array $data): array
    {
        if (! $this->isConfigured()) {
            return $this->createLocalShipment($data);
        }

        $endpoint = data_get($this->credentials(), 'settings.endpoint', 'https://pttws.ptt.gov.tr/shipment');

        Log::channel('stack')->info('PTT createShipment request', [
            'endpoint' => $endpoint,
            'order_number' => $data['order_number'] ?? null,
        ]);

        $response = Http::withToken((string) $this->credentials()['api_key'])
            ->timeout(30)
            ->post($endpoint, $data);

        if ($response->failed()) {
            return $this->createLocalShipment($data);
        }

        $tracking = (string) ($response->json('barcode') ?? $response->json('tracking_number') ?? '');

        if ($tracking === '') {
            return $this->createLocalShipment($data);
        }

        return [
            'success' => true,
            'mode' => 'api',
            'tracking_number' => $tracking,
            'tracking_url' => $this->buildTrackingUrl($tracking),
            'label_content' => null,
            'raw' => $response->json() ?? [],
        ];
    }

    public function getTrackingInfo(string $trackingNumber): array
    {
        return $this->localTrackingInfo($trackingNumber);
    }

    public function generateLabel(int|string $shipmentId): string
    {
        $path = 'cargo_labels/ptt_'.$shipmentId.'.txt';
        Storage::disk('local')->put($path, "PTT Label #{$shipmentId}");

        return $path;
    }

    public function generateInvoice(int|string $shipmentId): string
    {
        $path = 'invoices/ptt_'.$shipmentId.'.txt';
        Storage::disk('local')->put($path, "PTT Invoice #{$shipmentId}");

        return $path;
    }

    public function cancelShipment(string $trackingNumber): bool
    {
        if (! $this->isConfigured()) {
            return true;
        }

        throw new \App\Exceptions\CargoException('PTT Kargo için canlı iptal API’si henüz tanımlı değil.');
    }
}
