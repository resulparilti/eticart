<?php

declare(strict_types=1);

namespace App\Services\Cargo;

interface CargoServiceInterface
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createShipment(array $data): array;

    /**
     * @return array<string, mixed>
     */
    public function getTrackingInfo(string $trackingNumber): array;

    public function generateLabel(int|string $shipmentId): string;

    public function generateInvoice(int|string $shipmentId): string;

    public function cancelShipment(string $trackingNumber): bool;

    public function getProviderType(): string;

    public function isConfigured(): bool;
}
