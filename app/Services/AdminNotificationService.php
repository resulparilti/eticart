<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminNotification;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class AdminNotificationService
{
    /**
     * @var array<int, string>
     */
    private const ONCE_PER_SUBJECT = [
        AdminNotification::TYPE_ORDER_CREATED,
        AdminNotification::TYPE_ORDER_CANCELLED,
        AdminNotification::TYPE_ORDER_PREPARING,
        AdminNotification::TYPE_ORDER_SHIPPED,
        AdminNotification::TYPE_ORDER_DELIVERED,
        AdminNotification::TYPE_ORDER_REFUNDED,
        AdminNotification::TYPE_ORDER_REFUND_REQUESTED,
        AdminNotification::TYPE_CUSTOMER_CREATED,
        AdminNotification::TYPE_PRODUCT_CREATED,
    ];

    public function notify(
        string $type,
        string $title,
        ?string $message = null,
        ?string $url = null,
        ?Model $subject = null
    ): ?AdminNotification {
        try {
            if ($subject && in_array($type, self::ONCE_PER_SUBJECT, true)) {
                $exists = AdminNotification::query()
                    ->where('type', $type)
                    ->where('subject_type', $subject::class)
                    ->where('subject_id', $subject->getKey())
                    ->exists();

                if ($exists) {
                    return null;
                }
            }

            return AdminNotification::query()->create([
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'url' => $url,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public function unreadCount(): int
    {
        try {
            return AdminNotification::query()->whereNull('read_at')->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, AdminNotification>
     */
    public function latestUnread(int $limit = 8)
    {
        try {
            return AdminNotification::query()
                ->latest()
                ->limit($limit)
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    public function markRead(AdminNotification $notification): void
    {
        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }
    }

    public function markAllRead(): int
    {
        return AdminNotification::query()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
