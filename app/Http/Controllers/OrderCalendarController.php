<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ShopifyOrder;
use App\Support\StatusLabels;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderCalendarController extends Controller
{
    public function index(Request $request): View
    {
        $view = $request->string('view')->toString();
        if (! in_array($view, ['month', 'week', 'day'], true)) {
            $view = 'month';
        }

        $focus = $request->filled('date')
            ? Carbon::parse($request->string('date')->toString())->startOfDay()
            : now()->startOfDay();

        return view('workspace.calendar', [
            'viewMode' => $view,
            'focusDate' => $focus->toDateString(),
            'eventsUrl' => route('calendar.events'),
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Takvim'],
            ],
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        $from = Carbon::parse($request->string('from')->toString())->startOfDay();
        $to = Carbon::parse($request->string('to')->toString())->endOfDay();

        if ($from->diffInDays($to) > 62) {
            $to = $from->copy()->addDays(62)->endOfDay();
        }

        $orders = ShopifyOrder::query()
            ->with(['items:id,shopify_order_id,product_title,variant_title,sku,quantity'])
            ->select([
                'id',
                'order_number',
                'customer_name',
                'customer_email',
                'customer_phone',
                'total_price',
                'currency',
                'fulfillment_status',
                'payment_status',
                'order_items',
                'shopify_created_at',
            ])
            ->whereBetween('shopify_created_at', [$from, $to])
            ->orderBy('shopify_created_at')
            ->limit(500)
            ->get();

        $events = $orders->map(function (ShopifyOrder $order): array {
            $status = $order->fulfillment_status ?: 'unfulfilled';
            $date = optional($order->shopify_created_at)->toDateString();

            return [
                'id' => $order->id,
                'date' => $date,
                'title' => '#'.$order->order_number.' - '.($order->customer_name ?: 'Müşteri'),
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name,
                'customer_email' => $order->customer_email,
                'customer_phone' => $order->customer_phone,
                'total' => number_format((float) $order->total_price, 2, ',', '.').' '.($order->currency ?: 'TRY'),
                'status' => $status,
                'status_label' => StatusLabels::fulfillment($status),
                'color' => $this->statusColor($status),
                'items_preview' => $this->itemsPreview($order),
                'url' => route('orders.show', $order),
            ];
        })->values();

        return response()->json([
            'events' => $events,
        ]);
    }

    private function statusColor(string $status): string
    {
        return match ($status) {
            'preparing' => '#0dcaf0',
            'partial' => '#ffc107',
            'fulfilled' => '#198754',
            'delivered' => '#0f766e',
            'cancelled', 'restocked' => '#dc3545',
            default => '#6c757d',
        };
    }

    private function itemsPreview(ShopifyOrder $order): string
    {
        if ($order->relationLoaded('items') && $order->items->isNotEmpty()) {
            $names = $order->items->take(4)->map(static function ($item): string {
                $title = trim((string) ($item->product_title ?: ''));
                $variant = trim((string) ($item->variant_title ?: ''));
                if ($title !== '' && $variant !== '' && strcasecmp($variant, 'Default Title') !== 0) {
                    return $title.' / '.$variant;
                }

                return $title !== '' ? $title : 'Ürün';
            })->all();
            $extra = $order->items->count() > 4 ? ' +'.($order->items->count() - 4) : '';

            return implode(', ', $names).$extra;
        }

        $items = $order->order_items;
        if (! is_array($items) || $items === []) {
            return '—';
        }

        $names = [];
        foreach (array_slice($items, 0, 4) as $item) {
            if (is_array($item)) {
                $names[] = (string) ($item['title'] ?? $item['name'] ?? 'Ürün');
            }
        }

        $extra = count($items) > 4 ? ' +'.(count($items) - 4) : '';

        return implode(', ', $names).$extra;
    }
}
