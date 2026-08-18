<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Shipment;
use App\Services\MailService;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendTrackingInfo implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        public int $shipmentId
    ) {
    }

    /**
     * Send shipment tracking via SMS and mail.
     */
    public function handle(MailService $mailService, SmsService $smsService): void
    {
        $shipment = Shipment::query()->with(['order', 'cargoCompany'])->findOrFail($this->shipmentId);
        $phone = $shipment->receiver_phone ?: $shipment->order?->customer_phone;

        if ($phone) {
            $smsService->sendFromTemplate($phone, 'shipment-sms', [
                'customer_name' => $shipment->receiver_name ?: $shipment->order?->customer_name,
                'order_number' => $shipment->order_number,
                'total_price' => number_format((float) ($shipment->order?->total_price ?? $shipment->amount ?? 0), 0, ',', '.'),
                'tracking_number' => $shipment->tracking_number,
                'tracking_url' => $shipment->tracking_url,
                'cargo_company' => $shipment->cargoCompany?->name ?? 'Kargo',
                'status' => 'Kargoya verildi',
            ], $shipment);
        }

        if ($shipment->order?->customer_email) {
            $mailService->sendShipmentNotification($shipment);
        }

        Log::channel('stack')->info('SendTrackingInfo completed', [
            'shipment_id' => $shipment->id,
            'phone' => (bool) $phone,
            'email' => (bool) $shipment->order?->customer_email,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('stack')->error('SendTrackingInfo failed', [
            'shipment_id' => $this->shipmentId,
            'message' => $exception?->getMessage(),
        ]);
    }
}
