<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ShopifyOrder;
use App\Models\SyncActivity;
use App\Services\CustomerMessageService;
use App\Services\SyncActivityTracker;
use App\Support\OrderMessageTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendOrderTemplateMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public int $orderId,
        public string $channel,
        public string $templateKey,
        public int $activityId,
    ) {
    }

    public function handle(CustomerMessageService $messages, SyncActivityTracker $tracker): void
    {
        $activity = SyncActivity::query()->find($this->activityId);
        if ($activity) {
            $tracker->bind($activity);
            $tracker->markRunning('Gönderim başlatıldı…');
        }

        $order = ShopifyOrder::query()->find($this->orderId);
        if (! $order) {
            $tracker->fail('Sipariş bulunamadı.');

            return;
        }

        try {
            $notification = $messages->sendOrderTemplate($order, $this->channel, $this->templateKey);
            $label = OrderMessageTemplates::label($this->templateKey);

            if ($notification->status === 'failed') {
                $tracker->fail($notification->reportMessage());

                return;
            }

            $tracker->complete(
                $label.' gönderildi ('.$order->order_number.')',
                1
            );
        } catch (Throwable $e) {
            report($e);
            $tracker->fail($e->getMessage(), $e);

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::channel('stack')->error('SendOrderTemplateMessage failed', [
            'order_id' => $this->orderId,
            'channel' => $this->channel,
            'template' => $this->templateKey,
            'message' => $exception?->getMessage(),
        ]);

        $activity = SyncActivity::query()->find($this->activityId);
        if (! $activity || ! $activity->isActive()) {
            return;
        }

        $tracker = app(SyncActivityTracker::class);
        $tracker->bind($activity);
        $tracker->fail($exception?->getMessage() ?: 'Gönderim kuyruğu başarısız oldu.', $exception);
    }
}
