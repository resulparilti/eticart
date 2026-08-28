<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderItem;
use App\Models\User;
use App\Models\UyumSoftProduct;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ProductionFloorService
{
    public const LOW_STOCK_THRESHOLD = 5;

    public function __construct(
        private readonly ProductImageCacheService $images
    ) {
    }

    /**
     * @return array{total: int, delivered: int, incoming: int, awaiting: int, packed: int, in_progress: int}
     */
    public function todayStats(?User $user = null): array
    {
        $today = Carbon::today();
        $packedQuery = ShopifyOrder::query()->whereDate('packed_at', $today);
        $inProgressQuery = ShopifyOrder::query()
            ->whereNull('packed_at')
            ->whereNotNull('packing_started_by_user_id');

        if ($user?->isPackingStaff()) {
            $packedQuery->where('packed_by_user_id', $user->id);
            $inProgressQuery->where('packing_started_by_user_id', $user->id);
        }

        return [
            'total' => ShopifyOrder::query()
                ->where(function ($query) use ($today): void {
                    $query->whereDate('shopify_created_at', $today)
                        ->orWhere(function ($inner) use ($today): void {
                            $inner->whereNull('shopify_created_at')->whereDate('created_at', $today);
                        });
                })
                ->count(),
            'delivered' => ShopifyOrder::query()
                ->where('fulfillment_status', 'delivered')
                ->whereDate('updated_at', $today)
                ->count(),
            'incoming' => ShopifyOrder::query()
                ->where(function ($query) use ($today): void {
                    $query->whereDate('shopify_created_at', $today)
                        ->orWhere(function ($inner) use ($today): void {
                            $inner->whereNull('shopify_created_at')->whereDate('created_at', $today);
                        });
                })
                ->openUndelivered()
                ->count(),
            'awaiting' => ShopifyOrder::query()->awaitingPacking()->count(),
            'packed' => $packedQuery->count(),
            'in_progress' => $inProgressQuery->count(),
        ];
    }

    /**
     * @return Collection<int, ShopifyOrder>
     */
    public function awaitingOrders(int $limit = 20): Collection
    {
        return ShopifyOrder::query()
            ->with('items')
            ->awaitingPacking()
            ->orderByDesc('shopify_created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array<int, array{product: UyumSoftProduct, variants: array<int, array<string, mixed>>}>
     */
    public function lowStockGroups(int $limit = 12): array
    {
        $threshold = self::LOW_STOCK_THRESHOLD;
        $groups = [];

        $products = UyumSoftProduct::query()
            ->where('is_active', true)
            ->where('stock', '<=', $threshold)
            ->orderBy('stock')
            ->orderBy('title')
            ->limit($limit)
            ->get();

        foreach ($products as $product) {
            $lowVariants = [];
            foreach ($product->variantRows() as $row) {
                $stock = $this->stockInt($row['stock'] ?? 0);
                if ($stock > $threshold) {
                    continue;
                }
                $lowVariants[] = [
                    'title' => (string) ($row['title'] ?? 'Varyant'),
                    'sku' => $row['sku'] ?? null,
                    'barcode' => $row['barcode'] ?? null,
                    'stock' => $stock,
                    'image' => $this->images->displayUrl($product, $row['image'] ?? null),
                ];
            }

            if ($lowVariants === []) {
                $lowVariants[] = [
                    'title' => 'Varsayılan',
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'stock' => $this->stockInt($product->stock),
                    'image' => $product->primaryImageUrl(),
                ];
            }

            usort($lowVariants, static fn (array $a, array $b): int => $a['stock'] <=> $b['stock']);
            $groups[] = [
                'product' => $product,
                'variants' => $lowVariants,
            ];
        }

        return $groups;
    }

    /**
     * @param  Collection<int, ShopifyOrderItem>  $items
     * @return Collection<int, array{item: ShopifyOrderItem, image: ?string, barcode: ?string}>
     */
    public function decorateItems(Collection $items): Collection
    {
        $tokens = [];
        foreach ($items as $item) {
            foreach ([$item->sku, $item->barcode] as $token) {
                $token = trim((string) $token);
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }
        $tokens = array_values(array_unique($tokens));

        $catalog = $this->catalogIndex($tokens);

        return $items->map(function (ShopifyOrderItem $item) use ($catalog): array {
            $hit = $this->matchCatalog($item, $catalog);

            return [
                'item' => $item,
                'image' => $hit['image'] ?? null,
                'barcode' => $item->barcode ?: ($hit['barcode'] ?? null),
            ];
        });
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array<string, array{image: ?string, barcode: ?string}>
     */
    private function catalogIndex(array $tokens): array
    {
        if ($tokens === []) {
            return [];
        }

        $query = UyumSoftProduct::query()->with('shopifyProduct');
        $query->where(function ($builder) use ($tokens): void {
            $builder->whereIn('sku', $tokens)->orWhereIn('barcode', $tokens);
                foreach ($tokens as $token) {
                    $safe = addcslashes($token, '%_\\');
                    $builder->orWhere('variant_info', 'like', '%'.$safe.'%');
                }
        });

        $index = [];
        foreach ($query->get() as $product) {
            $parentImage = $product->primaryImageUrl();
            $this->putIndex($index, $product->sku, $product->barcode, $parentImage);
            foreach ($product->variantRows() as $row) {
                $image = trim((string) ($row['image'] ?? '')) ?: $parentImage;
                $this->putIndex($index, $row['sku'] ?? null, $row['barcode'] ?? null, $this->images->displayUrl($product, $image));
            }
        }

        return $index;
    }

    /**
     * @param  array<string, array{image: ?string, barcode: ?string}>  $index
     */
    private function putIndex(array &$index, mixed $sku, mixed $barcode, ?string $image): void
    {
        $sku = trim((string) $sku);
        $barcode = trim((string) $barcode);
        $payload = [
            'image' => $image,
            'barcode' => $barcode !== '' ? $barcode : null,
        ];
        if ($sku !== '') {
            $index['s:'.mb_strtolower($sku)] = $payload;
        }
        if ($barcode !== '') {
            $index['b:'.mb_strtolower($barcode)] = $payload;
        }
    }

    /**
     * @param  array<string, array{image: ?string, barcode: ?string}>  $index
     * @return array{image: ?string, barcode: ?string}
     */
    private function matchCatalog(ShopifyOrderItem $item, array $index): array
    {
        $sku = mb_strtolower(trim((string) $item->sku));
        $barcode = mb_strtolower(trim((string) $item->barcode));
        if ($barcode !== '' && isset($index['b:'.$barcode])) {
            return $index['b:'.$barcode];
        }
        if ($sku !== '' && isset($index['s:'.$sku])) {
            return $index['s:'.$sku];
        }

        return ['image' => null, 'barcode' => $item->barcode];
    }

    private function stockInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
