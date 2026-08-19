<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ShopifyException;
use App\Models\ShopifyCustomer;
use App\Models\SyncActivity;
use App\Services\CustomerSyncService;
use App\Services\ShopifyService;
use App\Services\SyncActivityTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerSyncService $customerSyncService,
        private readonly ShopifyService $shopifyService,
        private readonly SyncActivityTracker $activityTracker
    ) {
    }

    public function index(Request $request): View
    {
        $search = $request->string('q')->toString();
        $city = $request->string('city')->toString();

        $query = ShopifyCustomer::query()->withCount('orders')->latest('last_order_at')->latest('id');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('shopify_customer_id', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        if ($city !== '') {
            $query->where('city', 'like', "%{$city}%");
        }

        return view('customers.index', [
            'customers' => $query->paginate(25)->withQueryString(),
            'search' => $search,
            'city' => $city,
            'counts' => [
                'all' => ShopifyCustomer::query()->count(),
                'with_email' => ShopifyCustomer::query()->whereNotNull('email')->count(),
                'with_orders' => ShopifyCustomer::query()->where('orders_count', '>', 0)->count(),
            ],
            'shopifyConfigured' => $this->shopifyService->isConfigured(),
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Müşteriler'],
            ],
        ]);
    }

    public function show(ShopifyCustomer $customer): View
    {
        $customer->load(['orders' => fn ($q) => $q->latest('shopify_created_at')->limit(50)]);

        return view('customers.show', [
            'customer' => $customer,
            'orders' => $customer->orders,
            'shopifyConfigured' => $this->shopifyService->isConfigured(),
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Müşteriler', 'url' => route('customers.index')],
                ['label' => $customer->displayName()],
            ],
        ]);
    }

    public function sync(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'source' => ['nullable', 'in:all,shopify,orders'],
        ]);

        $source = $validated['source'] ?? 'all';

        $titles = [
            'all' => 'Müşteri senkronizasyonu (Shopify + siparişler)',
            'shopify' => 'Shopify müşteri çekimi',
            'orders' => 'Siparişlerden müşteri derleme',
        ];

        $activity = $this->activityTracker->start(
            'customer_sync',
            $titles[$source] ?? 'Müşteri senkronizasyonu',
            null,
            ['source' => $source]
        );

        $activityId = $activity->id;
        $uuid = $activity->uuid;

        dispatch(function () use ($activityId, $source): void {
            $tracker = app(SyncActivityTracker::class);
            $activity = SyncActivity::query()->find($activityId);
            if (! $activity) {
                return;
            }
            $tracker->bind($activity);

            try {
                $service = app(CustomerSyncService::class);
                match ($source) {
                    'shopify' => $service->syncFromShopify(),
                    'orders' => $service->syncFromLocalOrders(),
                    default => $service->syncAll(),
                };
            } catch (\Throwable $e) {
                report($e);
                $tracker->fail($e->getMessage(), $e);
            }
        })->afterResponse();

        return redirect()
            ->route('customers.index')
            ->with('success', 'Müşteri senkronizasyonu arka planda başladı. Sağ alttan takip edebilirsiniz.')
            ->with('sync_activity_uuid', $uuid);
    }

    public function refresh(ShopifyCustomer $customer): RedirectResponse
    {
        if (! $this->shopifyService->isConfigured()) {
            return back()->with('error', 'Shopify ayarları eksik.');
        }

        if (! $customer->shopify_customer_id) {
            return back()->with('error', 'Bu müşterinin Shopify ID’si yok; yalnızca siparişlerden derlenmiş olabilir.');
        }

        try {
            $payload = $this->shopifyService->getCustomerDetails($customer->shopify_customer_id);
            if ($payload === []) {
                return back()->with('error', 'Shopify müşteri kaydı bulunamadı.');
            }
            $this->customerSyncService->upsertFromShopifyPayload($payload);
            $this->customerSyncService->recalculateOrderStats();

            return back()->with('success', 'Müşteri Shopify’dan güncellendi.');
        } catch (ShopifyException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
