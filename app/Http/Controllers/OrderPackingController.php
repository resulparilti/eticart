<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ShopifyOrder;
use App\Services\OrderPackingService;
use App\Support\OrderPackingChecklist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class OrderPackingController extends Controller
{
    public function __construct(
        private readonly OrderPackingService $packing
    ) {
    }

    public function updateChecklist(Request $request, ShopifyOrder $order): JsonResponse
    {
        $this->guardPackable($order);

        $validated = $request->validate([
            'item' => ['nullable', 'string', Rule::in(OrderPackingChecklist::keys())],
            'checked' => ['nullable', 'boolean'],
            'gift_box' => ['required', 'boolean'],
            'gift_box_size' => ['nullable', 'string', 'max:50'],
            'checklist' => ['required', 'array'],
            'checklist.*' => ['boolean'],
        ]);

        $fresh = $this->packing->saveChecklist(
            $order,
            $request->user(),
            $validated['checklist'],
            (bool) $validated['gift_box'],
            $validated['gift_box_size'] ?? null,
            $validated['item'] ?? null,
            array_key_exists('checked', $validated) ? (bool) $validated['checked'] : null
        );

        $giftBox = (bool) $fresh->packing_gift_box;

        return response()->json([
            'ok' => true,
            'complete' => OrderPackingChecklist::isComplete($giftBox, $fresh->packing_checklist),
            'checklist' => $fresh->packing_checklist,
            'gift_box' => $giftBox,
            'gift_box_size' => $fresh->packing_gift_box_size,
        ]);
    }

    public function complete(Request $request, ShopifyOrder $order): RedirectResponse
    {
        $this->guardPackable($order);

        $validated = $request->validate([
            'gift_box' => ['nullable', 'boolean'],
            'gift_box_size' => ['nullable', 'string', 'max:50'],
            'checklist' => ['required', 'array'],
            'checklist.*' => ['nullable'],
            'photo' => ['nullable', 'image', 'max:12288'],
        ]);

        $checklist = [];
        foreach (OrderPackingChecklist::keys() as $key) {
            $checklist[$key] = ! empty($validated['checklist'][$key]);
        }

        try {
            $this->packing->complete(
                $order,
                $request->user(),
                $checklist,
                $request->boolean('gift_box'),
                $validated['gift_box_size'] ?? null,
                $request->file('photo')
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $order->order_number.' hazırlandı olarak işaretlendi.');
    }

    private function guardPackable(ShopifyOrder $order): void
    {
        if (in_array((string) $order->fulfillment_status, ['cancelled', 'refunded'], true)) {
            abort(422, 'İptal veya iade edilmiş sipariş hazırlanamaz.');
        }
    }
}
