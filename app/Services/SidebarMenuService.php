<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Shipment;
use App\Models\ShopifyOrder;
use Throwable;

class SidebarMenuService
{
    public function __construct(
        private readonly AdminNotificationService $notifications
    ) {
    }

    /**
     * @return array{open_orders: int, pending_shipments: int, unread_alerts: int}
     */
    public function counts(): array
    {
        try {
            return [
                'open_orders' => ShopifyOrder::query()->openUndelivered()->count(),
                'pending_shipments' => Shipment::query()->awaitingDelivery()->count(),
                'unread_alerts' => $this->notifications->unreadCount(),
            ];
        } catch (Throwable) {
            return [
                'open_orders' => 0,
                'pending_shipments' => 0,
                'unread_alerts' => 0,
            ];
        }
    }
}
