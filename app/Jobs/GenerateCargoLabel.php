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

class GenerateCargoLabel implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public int $shipmentId
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(CargoService $cargoService): void
    {
        $path = $cargoService->generateLabel($this->shipmentId);

        Log::channel('stack')->info('GenerateCargoLabel completed', [
            'shipment_id' => $this->shipmentId,
            'path' => $path,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('stack')->error('GenerateCargoLabel failed', [
            'shipment_id' => $this->shipmentId,
            'message' => $exception?->getMessage(),
        ]);
    }
}
