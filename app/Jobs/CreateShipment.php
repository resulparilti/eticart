<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\CargoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateShipment implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $orderId,
        public int $cargoCompanyId,
        public array $payload = []
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(CargoService $cargoService): void
    {
        $shipment = $cargoService->createShipment($this->orderId, $this->cargoCompanyId, $this->payload);

        Log::channel('stack')->info('CreateShipment job completed', [
            'shipment_id' => $shipment->id,
            'tracking_number' => $shipment->tracking_number,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('stack')->error('CreateShipment job failed', [
            'order_id' => $this->orderId,
            'message' => $exception?->getMessage(),
        ]);
    }
}
