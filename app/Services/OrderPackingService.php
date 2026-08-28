<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShopifyOrder;
use App\Models\User;
use App\Support\OrderPackingChecklist;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

class OrderPackingService
{
    public function __construct(
        private readonly ActivityLogService $logs
    ) {
    }

    /**
     * @param  array<string, mixed>  $checklist
     */
    public function saveChecklist(
        ShopifyOrder $order,
        User $user,
        array $checklist,
        bool $giftBox,
        ?string $giftBoxSize,
        ?string $changedKey = null,
        ?bool $changedChecked = null
    ): ShopifyOrder {
        $clean = $this->sanitizeChecklist($checklist);

        $order->update([
            'packing_checklist' => $clean,
            'packing_gift_box' => $giftBox,
            'packing_gift_box_size' => $giftBox ? $this->normalizeSize($giftBoxSize) : null,
        ]);

        $fresh = $order->fresh() ?? $order;

        if ($changedKey && isset(OrderPackingChecklist::labels()[$changedKey])) {
            $label = OrderPackingChecklist::labels()[$changedKey];
            $verb = $changedChecked ? 'işaretledi' : 'işaretini kaldırdı';
            $this->logs->record(
                'prepare',
                "{$fresh->order_number} hazırlama listesinde «{$label}» maddesini {$verb}.",
                $user,
                'orders',
                null,
                $fresh,
                [
                    'item' => $changedKey,
                    'checked' => (bool) $changedChecked,
                    'checklist' => $clean,
                    'gift_box' => $giftBox,
                    'gift_box_size' => $fresh->packing_gift_box_size,
                ]
            );
        }

        return $fresh;
    }

    public function complete(
        ShopifyOrder $order,
        User $user,
        array $checklist,
        bool $giftBox,
        ?string $giftBoxSize,
        ?UploadedFile $photo = null
    ): ShopifyOrder {
        $clean = $this->sanitizeChecklist($checklist);
        $size = $giftBox ? $this->normalizeSize($giftBoxSize) : null;

        if ($giftBox && $size === null) {
            throw new \InvalidArgumentException('Hediye kutusu boyutu seçilmelidir.');
        }

        if (! OrderPackingChecklist::isComplete($giftBox, $clean)) {
            throw new \InvalidArgumentException('Hazırlama listesi tamamlanmadan sipariş kapatılamaz.');
        }

        $photoPath = $order->packing_photo_path;
        if ($photo) {
            $photoPath = $this->storePhoto($order, $user, $photo);
            if ($order->packing_photo_path && $order->packing_photo_path !== $photoPath) {
                Storage::disk('public')->delete($order->packing_photo_path);
            }
        }

        $order->update([
            'packing_checklist' => $clean,
            'packing_gift_box' => $giftBox,
            'packing_gift_box_size' => $size,
            'packing_photo_path' => $photoPath,
            'packed_at' => now(),
            'packed_by_user_id' => $user->id,
            'packed_by_name' => $user->name,
        ]);

        $fresh = $order->fresh() ?? $order;
        $marked = OrderPackingChecklist::checkedLabels($clean, $giftBox);
        $photoNote = $photoPath ? 'Fotoğraf yüklendi' : 'Fotoğraf yok';

        $this->logs->record(
            'prepare',
            $fresh->order_number.' siparişini hazırladı. İşaretlenen: '.implode('; ', $marked).'. '.$photoNote.'.',
            $user,
            'orders',
            null,
            $fresh,
            [
                'checklist' => $clean,
                'checked_labels' => $marked,
                'gift_box' => $giftBox,
                'gift_box_size' => $fresh->packing_gift_box_size,
                'photo' => (bool) $photoPath,
                'photo_path' => $photoPath,
            ]
        );

        return $fresh;
    }

    private function storePhoto(ShopifyOrder $order, User $user, UploadedFile $photo): string
    {
        $orderSlug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $order->order_number), '-');
        if ($orderSlug === '') {
            $orderSlug = 'siparis-'.$order->id;
        }
        $filename = sprintf(
            '%s_u%s_%s.jpg',
            $orderSlug,
            $user->id,
            now()->format('Ymd-His')
        );
        $path = 'order-preparations/'.$filename;

        try {
            $manager = new ImageManager(new Driver());
            $encoded = $manager->read($photo->getRealPath() ?: $photo->getPathname())
                ->scaleDown(1600, 1600)
                ->toJpeg(82);
            Storage::disk('public')->put($path, (string) $encoded);
        } catch (Throwable $e) {
            report($e);
            Storage::disk('public')->putFileAs('order-preparations', $photo, $filename);
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $checklist
     * @return array<string, bool>
     */
    private function sanitizeChecklist(array $checklist): array
    {
        $clean = [];
        foreach (OrderPackingChecklist::keys() as $key) {
            $clean[$key] = ! empty($checklist[$key]);
        }

        return $clean;
    }

    private function normalizeSize(?string $size): ?string
    {
        $size = trim((string) $size);
        if ($size === '') {
            return null;
        }

        return Str::limit($size, 50, '');
    }
}
