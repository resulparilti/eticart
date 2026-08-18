<?php

declare(strict_types=1);

namespace App\Services\Cargo;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MngCargoService extends AbstractCargoService
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

        Log::channel('stack')->info('MNG createShipment (SOAP stub)', [
            'order_number' => $data['order_number'] ?? null,
        ]);

        // SOAP entegrasyonu credential doğrulamasından sonra bağlanacak.
        return $this->createLocalShipment($data);
    }

    public function getTrackingInfo(string $trackingNumber): array
    {
        return $this->localTrackingInfo($trackingNumber);
    }

    public function generateLabel(int|string $shipmentId): string
    {
        $path = 'cargo_labels/mng_'.$shipmentId.'.txt';
        Storage::disk('local')->put($path, "MNG Label #{$shipmentId}");

        return $path;
    }

    public function generateInvoice(int|string $shipmentId): string
    {
        $path = 'invoices/mng_'.$shipmentId.'.txt';
        Storage::disk('local')->put($path, "MNG Invoice #{$shipmentId}");

        return $path;
    }

    public function cancelShipment(string $trackingNumber): bool
    {
        if (! $this->isConfigured()) {
            return true;
        }

        throw new \App\Exceptions\CargoException('MNG Kargo için canlı iptal API’si henüz tanımlı değil.');
    }
}
