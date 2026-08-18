<?php

declare(strict_types=1);

namespace App\Services\Cargo;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ArasCargoService extends AbstractCargoService
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

        $endpoint = data_get($this->credentials(), 'settings.endpoint', 'https://customerws.araskargo.com.tr/arascargoservice.asmx');

        Log::channel('stack')->info('Aras createShipment request', [
            'endpoint' => $endpoint,
            'order_number' => $data['order_number'] ?? null,
        ]);

        // Real SOAP integration can replace this stub when credentials are validated.
        $response = Http::timeout(30)->asForm()->post($endpoint, [
            'UserName' => $this->credentials()['username'],
            'Password' => $this->credentials()['password'],
            'TradingWaybillNumber' => $data['order_number'] ?? null,
            'ReceiverName' => $data['receiver_name'] ?? null,
            'ReceiverPhone' => $data['receiver_phone'] ?? null,
            'ReceiverAddress' => $data['receiver_address'] ?? null,
            'ReceiverCityName' => $data['receiver_city'] ?? null,
            'PieceCount' => 1,
            'Weight' => $data['weight'] ?? 1,
        ]);

        if ($response->failed()) {
            Log::channel('stack')->warning('Aras API failed, falling back to local', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return $this->createLocalShipment($data);
        }

        $tracking = (string) ($response->json('tracking_number') ?? $response->json('TrackingNumber') ?? '');

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
        if (! $this->isConfigured()) {
            return $this->localTrackingInfo($trackingNumber);
        }

        return $this->localTrackingInfo($trackingNumber);
    }

    public function generateLabel(int|string $shipmentId): string
    {
        $path = 'cargo_labels/aras_'.$shipmentId.'.txt';
        Storage::disk('local')->put($path, "Aras Label #{$shipmentId}");

        return $path;
    }

    public function generateInvoice(int|string $shipmentId): string
    {
        $path = 'invoices/aras_'.$shipmentId.'.txt';
        Storage::disk('local')->put($path, "Aras Invoice #{$shipmentId}");

        return $path;
    }

    public function cancelShipment(string $trackingNumber): bool
    {
        if (! $this->isConfigured()) {
            return true;
        }

        throw new \App\Exceptions\CargoException('Aras Kargo için canlı iptal API’si henüz tanımlı değil.');
    }
}
