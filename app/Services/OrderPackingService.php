<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PackingInProgressException;
use App\Models\ShopifyOrder;
use App\Models\User;
use App\Support\OrderPackingChecklist;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

class OrderPackingService
{
    public const LOCKED_MESSAGE = 'Bu siparişi bir başka personel hazırlamaya başladı, lütfen farklı bir sipariş hazırlayınız.';

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
        $order = $this->withLock($order, function (ShopifyOrder $locked) use ($user, $checklist, $giftBox, $giftBoxSize): ShopifyOrder {
            $this->assertCanContinue($locked, $user);
            $clean = $this->sanitizeChecklist($checklist);
            $this->applyClaim($locked, $user);

            $locked->update([
                'packing_checklist' => $clean,
                'packing_gift_box' => $giftBox,
                'packing_gift_box_size' => $giftBox ? $this->normalizeSize($giftBoxSize) : null,
                'packing_started_by_user_id' => $locked->packing_started_by_user_id,
                'packing_started_by_name' => $locked->packing_started_by_name,
            ]);

            return $locked->fresh() ?? $locked;
        });

        $fresh = $order;
        $clean = $this->sanitizeChecklist($checklist);

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

        $fresh = $this->withLock($order, function (ShopifyOrder $locked) use ($user, $clean, $giftBox, $size, $photo): ShopifyOrder {
            $this->assertCanContinue($locked, $user);
            $this->applyClaim($locked, $user);

            $photoPath = $locked->packing_photo_path;
            if ($photo) {
                $photoPath = $this->storePhoto($locked, $user, $photo);
                if ($locked->packing_photo_path && $locked->packing_photo_path !== $photoPath) {
                    Storage::disk('public')->delete($locked->packing_photo_path);
                }
            }

            $locked->update([
                'packing_checklist' => $clean,
                'packing_gift_box' => $giftBox,
                'packing_gift_box_size' => $size,
                'packing_photo_path' => $photoPath,
                'packed_at' => now(),
                'packed_by_user_id' => $user->id,
                'packed_by_name' => $user->name,
                'packing_started_by_user_id' => $locked->packing_started_by_user_id ?: $user->id,
                'packing_started_by_name' => $locked->packing_started_by_name ?: $user->name,
            ]);

            return $locked->fresh() ?? $locked;
        });
        $marked = OrderPackingChecklist::checkedLabels($clean, $giftBox);
        $photoNote = $fresh->packing_photo_path ? 'Fotoğraf yüklendi' : 'Fotoğraf yok';

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
                'photo' => (bool) $fresh->packing_photo_path,
                'photo_path' => $fresh->packing_photo_path,
            ]
        );

        return $fresh;
    }

    public function reset(ShopifyOrder $order, User $user): ShopifyOrder
    {
        if ($order->packing_photo_path) {
            Storage::disk('public')->delete($order->packing_photo_path);
        }

        $order->update([
            'packed_at' => null,
            'packed_by_user_id' => null,
            'packed_by_name' => null,
            'packing_checklist' => null,
            'packing_gift_box' => false,
            'packing_gift_box_size' => null,
            'packing_photo_path' => null,
            'packing_started_by_user_id' => null,
            'packing_started_by_name' => null,
        ]);

        $fresh = $order->fresh() ?? $order;
        $this->logs->record(
            'prepare',
            $fresh->order_number.' siparişinin hazırlanma prosedürünü iptal etti.',
            $user,
            'orders',
            null,
            $fresh
        );

        return $fresh;
    }

    /**
     * @return array{ok: bool, can_start: bool, started_by: ?string, message: ?string}
     */
    public function statusFor(ShopifyOrder $order, User $user): array
    {
        $claimed = $order->isPackingClaimedByOther($user);

        return [
            'ok' => true,
            'can_start' => ! $claimed,
            'started_by' => $order->hasPackingProgress() ? $order->packingStarterName() : null,
            'message' => $claimed ? self::LOCKED_MESSAGE : null,
        ];
    }

    /**
     * Hazırlama sayfasına giren veya Hazırla diyen personel siparişi üstlenir.
     */
    public function claim(ShopifyOrder $order, User $user): ShopifyOrder
    {
        if ($order->isPacked() || in_array((string) $order->fulfillment_status, ['cancelled', 'refunded'], true)) {
            return $order;
        }

        return $this->withLock($order, function (ShopifyOrder $locked) use ($user): ShopifyOrder {
            if ($locked->isPacked()) {
                return $locked;
            }

            $this->assertCanContinue($locked, $user);
            $this->applyClaim($locked, $user);
            $locked->save();

            return $locked;
        });
    }

    /**
     * @template T
     * @param  callable(ShopifyOrder): T  $callback
     * @return T
     */
    private function withLock(ShopifyOrder $order, callable $callback): mixed
    {
        return DB::transaction(function () use ($order, $callback) {
            $locked = ShopifyOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            return $callback($locked);
        });
    }

    private function assertCanContinue(ShopifyOrder $order, User $user): void
    {
        if ($order->isPackingClaimedByOther($user)) {
            throw new PackingInProgressException(self::LOCKED_MESSAGE);
        }
    }

    private function applyClaim(ShopifyOrder $order, User $user): void
    {
        if ($order->packing_started_by_user_id) {
            return;
        }

        $order->packing_started_by_user_id = $user->id;
        $order->packing_started_by_name = $user->name;
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
