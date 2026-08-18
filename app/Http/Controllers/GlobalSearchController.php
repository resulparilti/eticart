<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ShopifyCustomer;
use App\Models\ShopifyOrder;
use App\Models\UyumSoftProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    private const MIN_CHARS = 3;

    private const LIMIT = 8;

    /**
     * Navbar AJAX arama: müşteri / sipariş / ürün sonuçlarını gruplu döner.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());

        if (mb_strlen($q) < self::MIN_CHARS) {
            return response()->json([
                'ok' => false,
                'message' => 'En az '.self::MIN_CHARS.' karakter girin.',
                'groups' => [],
            ]);
        }

        $customers = $this->searchCustomers($q);
        $orders = $this->searchOrders($q);
        $products = $this->searchProducts($q);

        $groups = [];

        if ($customers !== []) {
            $groups[] = [
                'key' => 'customers',
                'label' => 'Müşteriler',
                'items' => $customers,
            ];
        }

        if ($orders !== []) {
            $groups[] = [
                'key' => 'orders',
                'label' => 'Siparişler',
                'items' => $orders,
            ];
        }

        if ($products !== []) {
            $groups[] = [
                'key' => 'products',
                'label' => 'Ürünler',
                'items' => $products,
            ];
        }

        return response()->json([
            'ok' => $groups !== [],
            'message' => $groups === [] ? 'Sonuç bulunamadı.' : null,
            'groups' => $groups,
        ]);
    }

    /**
     * @return array<int, array{title: string, subtitle: string|null, url: string}>
     */
    private function searchCustomers(string $q): array
    {
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        $phoneVariants = strlen($digits) >= 7 ? $this->phoneVariants($digits) : [];

        return ShopifyCustomer::query()
            ->where(function ($builder) use ($q, $phoneVariants) {
                $builder->where('full_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%");

                foreach ($phoneVariants as $variant) {
                    $builder->orWhere('phone', 'like', "%{$variant}%");
                }
            })
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(static function (ShopifyCustomer $customer): array {
                $subtitleParts = array_filter([(string) $customer->phone, (string) $customer->email]);

                return [
                    'title' => $customer->displayName(),
                    'subtitle' => $subtitleParts !== [] ? implode(' · ', $subtitleParts) : null,
                    'url' => route('customers.show', $customer),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array{title: string, subtitle: string|null, url: string}>
     */
    private function searchOrders(string $q): array
    {
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        $phoneVariants = strlen($digits) >= 7 ? $this->phoneVariants($digits) : [];
        $orderQ = ltrim($q, '#');

        return ShopifyOrder::query()
            ->where(function ($builder) use ($q, $orderQ, $phoneVariants) {
                $builder->where('order_number', 'like', "%{$q}%")
                    ->orWhere('order_number', 'like', "%{$orderQ}%")
                    ->orWhere('customer_name', 'like', "%{$q}%")
                    ->orWhere('customer_email', 'like', "%{$q}%")
                    ->orWhere('customer_phone', 'like', "%{$q}%");

                foreach ($phoneVariants as $variant) {
                    $builder->orWhere('customer_phone', 'like', "%{$variant}%");
                }
            })
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(static function (ShopifyOrder $order): array {
                $subtitleParts = array_filter([
                    (string) $order->customer_name,
                    (string) $order->customer_phone,
                ]);

                return [
                    'title' => 'Sipariş '.$order->order_number,
                    'subtitle' => $subtitleParts !== [] ? implode(' · ', $subtitleParts) : null,
                    'url' => route('orders.show', $order),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array{title: string, subtitle: string|null, url: string}>
     */
    private function searchProducts(string $q): array
    {
        return UyumSoftProduct::query()
            ->where(function ($builder) use ($q) {
                $builder->where('title', 'like', "%{$q}%")
                    ->orWhere('sku', 'like', "%{$q}%")
                    ->orWhere('barcode', 'like', "%{$q}%")
                    ->orWhere('uyumsoft_id', 'like', "%{$q}%");
            })
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(static function (UyumSoftProduct $product): array {
                $subtitleParts = array_filter([
                    $product->sku ? 'SKU: '.$product->sku : null,
                    $product->barcode ? 'Barkod: '.$product->barcode : null,
                ]);

                return [
                    'title' => (string) ($product->title ?: 'Ürün #'.$product->id),
                    'subtitle' => $subtitleParts !== [] ? implode(' · ', $subtitleParts) : null,
                    'url' => route('products.show', $product),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function phoneVariants(string $digits): array
    {
        $variants = array_filter([
            $digits,
            strlen($digits) === 11 && str_starts_with($digits, '0') ? substr($digits, 1) : null,
            strlen($digits) === 10 ? '0'.$digits : null,
            strlen($digits) >= 10 ? '90'.substr($digits, -10) : null,
            strlen($digits) >= 10 ? substr($digits, -10) : null,
        ]);

        return array_values(array_unique($variants));
    }
}
