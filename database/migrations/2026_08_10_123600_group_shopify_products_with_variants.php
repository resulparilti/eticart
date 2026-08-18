<?php

declare(strict_types=1);

use App\Models\ShopifyProduct;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shopify_products', function (Blueprint $table) {
            if (! Schema::hasColumn('shopify_products', 'description')) {
                $table->text('description')->nullable()->after('title');
            }
            if (! Schema::hasColumn('shopify_products', 'images')) {
                $table->json('images')->nullable()->after('description');
            }
            if (! Schema::hasColumn('shopify_products', 'variants')) {
                $table->json('variants')->nullable()->after('images');
            }
            if (! Schema::hasColumn('shopify_products', 'status')) {
                $table->string('status', 32)->nullable()->after('variants');
            }
            if (! Schema::hasColumn('shopify_products', 'handle')) {
                $table->string('handle')->nullable()->after('status');
            }
            if (! Schema::hasColumn('shopify_products', 'variant_count')) {
                $table->unsignedInteger('variant_count')->default(1)->after('stock');
            }
            if (! Schema::hasColumn('shopify_products', 'price_max')) {
                $table->decimal('price_max', 12, 2)->nullable()->after('price');
            }
        });

        $this->mergeDuplicateShopifyRows();

        Schema::table('shopify_products', function (Blueprint $table) {
            $table->dropUnique(['shopify_product_id', 'shopify_variant_id']);
        });

        Schema::table('shopify_products', function (Blueprint $table) {
            $table->unique('shopify_product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shopify_products', function (Blueprint $table) {
            $table->dropUnique(['shopify_product_id']);
        });

        Schema::table('shopify_products', function (Blueprint $table) {
            $table->unique(['shopify_product_id', 'shopify_variant_id']);
        });

        Schema::table('shopify_products', function (Blueprint $table) {
            $columns = ['description', 'images', 'variants', 'status', 'handle', 'variant_count', 'price_max'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('shopify_products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function mergeDuplicateShopifyRows(): void
    {
        $duplicateIds = DB::table('shopify_products')
            ->select('shopify_product_id')
            ->whereNull('deleted_at')
            ->groupBy('shopify_product_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('shopify_product_id');

        foreach ($duplicateIds as $shopifyProductId) {
            /** @var \Illuminate\Support\Collection<int, ShopifyProduct> $rows */
            $rows = ShopifyProduct::query()
                ->where('shopify_product_id', $shopifyProductId)
                ->orderBy('id')
                ->get();

            if ($rows->count() <= 1) {
                continue;
            }

            $keep = $rows->first();
            $variants = $rows->map(static function (ShopifyProduct $row): array {
                $title = $row->title;
                if (str_contains($title, ' — ')) {
                    [, $variantTitle] = explode(' — ', $title, 2);
                    $title = $variantTitle;
                }

                return [
                    'id' => (string) ($row->shopify_variant_id ?? ''),
                    'title' => $title !== '' ? $title : 'Varsayılan',
                    'sku' => $row->sku,
                    'price' => (float) $row->price,
                    'stock' => (int) $row->stock,
                    'inventory_item_id' => $row->inventory_item_id,
                ];
            })->values()->all();

            $prices = array_map(static fn (array $v): float => (float) ($v['price'] ?? 0), $variants);
            $baseTitle = $keep->title;
            if (str_contains($baseTitle, ' — ')) {
                [$baseTitle] = explode(' — ', $baseTitle, 2);
            }

            $keep->update([
                'title' => $baseTitle,
                'variants' => $variants,
                'variant_count' => count($variants),
                'stock' => array_sum(array_column($variants, 'stock')),
                'price' => min($prices) ?: $keep->price,
                'price_max' => max($prices) ?: $keep->price,
            ]);

            ShopifyProduct::query()
                ->where('shopify_product_id', $shopifyProductId)
                ->where('id', '!=', $keep->id)
                ->forceDelete();
        }
    }
};
