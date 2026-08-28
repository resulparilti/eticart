<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\CargoException;
use App\Exceptions\ShopifyException;
use App\Exceptions\UyumSoftException;
use App\Jobs\SyncShopifyOrders;
use App\Models\CargoCompany;
use App\Models\Notification;
use App\Models\Shipment;
use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderArchive;
use App\Services\CargoService;
use App\Services\MailConfigService;
use App\Services\MailService;
use App\Services\CustomerMessageService;
use App\Services\OrderLifecycleService;
use App\Services\OrderSyncService;
use App\Services\ShopifyService;
use App\Services\UyumSoftOrderSyncService;
use App\Support\OrderMessageTemplates;
use App\Support\ShippingLabelProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class OrderController extends Controller
{
    public function __construct(
        private readonly ShopifyService $shopifyService,
        private readonly OrderSyncService $orderSyncService,
        private readonly UyumSoftOrderSyncService $uyumSoftOrderSyncService,
        private readonly CustomerMessageService $customerMessages,
        private readonly CargoService $cargoService,
        private readonly OrderLifecycleService $orderLifecycle
    ) {
    }

    /**
     * List Shopify orders.
     */
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()?->isPackingStaff()) {
            return redirect()->route('production.orders.index');
        }

        $query = ShopifyOrder::query()
            ->with(['shipments.cargoCompany', 'packedBy'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('fulfillment_status', $request->string('status')->toString());
        } elseif ($request->boolean('open')) {
            $query->openUndelivered();
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->string('payment_status')->toString());
        }

        $customerSearch = $request->filled('q')
            ? $request->string('q')->toString()
            : $request->string('customer')->toString();

        if ($customerSearch !== '') {
            $query->where(function ($builder) use ($customerSearch) {
                $builder->where('customer_name', 'like', "%{$customerSearch}%")
                    ->orWhere('customer_email', 'like', "%{$customerSearch}%")
                    ->orWhere('customer_phone', 'like', "%{$customerSearch}%")
                    ->orWhere('order_number', 'like', "%{$customerSearch}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('shopify_created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('shopify_created_at', '<=', $request->date('date_to'));
        }

        if ($request->has('packed') && $request->input('packed') !== null && $request->input('packed') !== '') {
            if ((string) $request->input('packed') === '1') {
                $query->whereNotNull('packed_at');
            } else {
                $query->whereNull('packed_at');
            }
        }

        $orders = $query->paginate(20)->withQueryString();

        return view('orders.index', [
            'orders' => $orders,
            'filters' => array_merge(
                $request->only(['status', 'payment_status', 'customer', 'date_from', 'date_to', 'open', 'packed']),
                ['customer' => $customerSearch !== '' ? $customerSearch : ($request->input('customer') ?: '')]
            ),
            'isConfigured' => $this->shopifyService->isConfigured(),
            'cargoCompanies' => CargoCompany::apiReady(),
            'messageTemplates' => OrderMessageTemplates::options(),
            'smsConfigured' => $this->customerMessages->smsConfigured(),
            'mailConfigured' => $this->customerMessages->mailConfigured(),
            'archiveCount' => ShopifyOrderArchive::query()->count(),
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Siparişler'],
            ],
        ]);
    }

    /**
     * Show a single order.
     */
    public function show(ShopifyOrder $order): View|RedirectResponse
    {
        if (request()->user()?->isPackingStaff()) {
            return redirect()->route('production.orders.show', $order);
        }

        $order->shipments()->where('status', Shipment::STATUS_CANCELLED)->delete();
        $order->load(['items', 'shipments.cargoCompany', 'user', 'packedBy']);

        $mailWaitSeconds = 0;
        try {
            $mailWaitSeconds = app(MailConfigService::class)->secondsUntilAllowed();
        } catch (Throwable) {
        }

        return view('orders.show', [
            'order' => $order,
            'smsConfigured' => $this->customerMessages->smsConfigured(),
            'mailConfigured' => $this->customerMessages->mailConfigured(),
            'messageTemplates' => OrderMessageTemplates::options(),
            'suggestedTemplateKey' => OrderMessageTemplates::suggestedKey($order),
            'customerNotices' => $order->customerNotices(),
            'cargoCompanies' => CargoCompany::apiReady(),
            'mailWaitSeconds' => $mailWaitSeconds,
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Siparişler', 'url' => route('orders.index')],
                ['label' => $order->order_number],
            ],
        ]);
    }

    /**
     * Shopify’dan silinip arşivlenen siparişler.
     */
    public function archives(Request $request): View
    {
        $query = ShopifyOrderArchive::query()->latest('archived_at');

        if ($request->filled('q')) {
            $term = $request->string('q')->toString();
            $query->where(function ($builder) use ($term): void {
                $builder->where('order_number', 'like', "%{$term}%")
                    ->orWhere('customer_name', 'like', "%{$term}%")
                    ->orWhere('customer_email', 'like', "%{$term}%")
                    ->orWhere('shopify_order_id', 'like', "%{$term}%");
            });
        }

        return view('orders.archives', [
            'archives' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only(['q']),
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Siparişler', 'url' => route('orders.index')],
                ['label' => 'Silinen sipariş arşivi'],
            ],
        ]);
    }

    public function showArchive(ShopifyOrderArchive $archive): View
    {
        return view('orders.archive-show', [
            'archive' => $archive,
            'breadcrumbs' => [
                ['label' => 'Anasayfa', 'url' => route('dashboard')],
                ['label' => 'Siparişler', 'url' => route('orders.index')],
                ['label' => 'Arşiv', 'url' => route('orders.archives.index')],
                ['label' => $archive->order_number],
            ],
        ]);
    }

    /**
     * Trigger manual Shopify order sync.
     */
    public function sync(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:250'],
            'status' => ['nullable', 'string', 'in:any,open,closed,cancelled'],
            'queue' => ['nullable', 'boolean'],
        ]);

        $limit = (int) ($validated['limit'] ?? 50);
        $status = $validated['status'] ?? 'any';

        try {
            SyncShopifyOrders::dispatch($limit, $status);

            return redirect()
                ->route('orders.index')
                ->with('success', 'Sipariş senkronizasyonu kuyruğa alındı. Shopify, yerel kayıtlar ve UyumSoft arka planda eşitlenecek.');
        } catch (ShopifyException $e) {
            return redirect()
                ->route('orders.index')
                ->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('orders.index')
                ->with('error', 'Senkronizasyon sırasında beklenmeyen bir hata oluştu.');
        }
    }

    /**
     * Bidirectional Shopify sync for a single order.
     */
    public function syncOne(ShopifyOrder $order): RedirectResponse
    {
        try {
            $result = $this->orderSyncService->syncOne($order);
            if (! empty($result['archived'])) {
                return redirect()
                    ->route('orders.archives.index')
                    ->with('warning', $result['message']);
            }

            $fresh = ShopifyOrder::query()->find($order->id);
            if ($fresh === null) {
                return redirect()
                    ->route('orders.archives.index')
                    ->with('warning', $result['message']);
            }

            $uyum = $this->syncOrderToUyumsoft($fresh);

            $redirect = redirect()->route('orders.show', $order);
            $message = trim($result['message'].($uyum !== '' ? ' '.$uyum : ''));
            if ($result['success']) {
                return $redirect->with('success', $message);
            }

            return $redirect->with('warning', $message);
        } catch (ShopifyException $e) {
            return redirect()
                ->route('orders.show', $order)
                ->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('orders.show', $order)
                ->with('error', 'Sipariş senkronizasyonu sırasında beklenmeyen bir hata oluştu.');
        }
    }

    /**
     * Push a single Shopify order to UyumSoft and pull its invoice if ready.
     */
    public function syncUyumsoft(Request $request, ShopifyOrder $order): RedirectResponse
    {
        if (! $request->isMethod('POST')) {
            return redirect()->route('orders.show', $order);
        }

        try {
            $result = $this->uyumSoftOrderSyncService->syncOrder($order);
            $flashKey = ($result['pushed'] || $result['invoice']) ? 'success' : 'info';
            $message = $result['message'].' Detaylar işlem geçmişine kaydedildi.';

            return redirect()
                ->route('orders.show', $order)
                ->with($flashKey, $message);
        } catch (UyumSoftException $e) {
            return redirect()
                ->route('orders.show', $order)
                ->with('error', $e->getMessage().' Detaylar işlem geçmişine kaydedildi.');
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('orders.show', $order)
                ->with('error', 'UyumSoft senkronu sırasında beklenmeyen bir hata oluştu. Detaylar işlem geçmişine kaydedildi.');
        }
    }

    /**
     * Send a manual SMS to the order customer.
     */
    public function sendSms(Request $request, ShopifyOrder $order): RedirectResponse
    {
        if (! $this->customerMessages->smsConfigured()) {
            return redirect()
                ->route('orders.show', $order)
                ->with('error', 'SMS ayarları tanımlı değil. Ayarlar → SMS bölümünü doldurun.');
        }

        $validated = $request->validate([
            'manual_message' => ['required', 'string', 'max:1000'],
        ]);

        if (! filled($order->customer_phone)) {
            return redirect()
                ->route('orders.show', $order)
                ->with('error', 'Siparişte müşteri telefonu yok.');
        }

        try {
            $notification = $this->customerMessages->sendOrderSms(
                $order,
                'manual',
                $validated['manual_message'],
                null
            );

            if ($notification->status === 'failed') {
                return redirect()
                    ->route('orders.show', $order)
                    ->with('error', 'SMS gönderilemedi: '.$notification->reportMessage());
            }

            return redirect()
                ->route('orders.show', $order)
                ->with('success', 'SMS gönderildi ve kayıtlara işlendi.');
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('orders.show', $order)
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Queue a mail/SMS template for the order (sent in the background).
     */
    public function sendTemplateMessage(Request $request, ShopifyOrder $order): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', 'in:sms,mail'],
            'template_key' => ['required', 'string', Rule::in(OrderMessageTemplates::keys())],
        ]);

        try {
            $this->customerMessages->queueOrderTemplate(
                $order,
                $validated['channel'],
                $validated['template_key'],
                Auth::id()
            );
        } catch (Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return redirect()
                ->route('orders.show', $order)
                ->with('error', $e->getMessage());
        }

        $channelLabel = $validated['channel'] === 'sms' ? 'SMS' : 'Mail';
        $label = OrderMessageTemplates::label($validated['template_key']);
        $message = $channelLabel.' ('.$label.') kuyruğa alındı. Arka planda gönderilecek.';

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
            ]);
        }

        return redirect()
            ->route('orders.show', $order)
            ->with('success', $message);
    }

    /**
     * Update local fulfillment status and push to Shopify.
     */
    public function updateStatus(Request $request, ShopifyOrder $order): RedirectResponse
    {
        $validated = $request->validate([
            'fulfillment_status' => ['required', 'string', 'max:50'],
            'payment_status' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        $notes = $validated['notes'] ?? $order->notes;
        if (! $order->hasInvoice()) {
            $notes = ShopifyOrder::stripInvoiceLines($notes);
        }

        $order->update([
            'fulfillment_status' => $validated['fulfillment_status'],
            'payment_status' => $validated['payment_status'] ?? $order->payment_status,
            'notes' => $notes,
            'shopify_needs_push' => true,
        ]);

        $push = $this->orderLifecycle->syncLocalStateToShopify(
            $order->fresh(['shipments.cargoCompany'])
        );

        $redirect = redirect()->route('orders.show', $order);

        if ($push['success']) {
            return $redirect->with('success', 'Sipariş durumu güncellendi ve Shopify\'a yansıtıldı.');
        }

        if ($push['skipped']) {
            return $redirect->with('success', 'Sipariş durumu güncellendi.');
        }

        return $redirect
            ->with('success', 'Sipariş durumu güncellendi.')
            ->with('warning', $push['message']);
    }

    /**
     * Send this order to the cargo company API (same flow as the orders list).
     */
    public function assignCargo(Request $request, ShopifyOrder $order): RedirectResponse
    {
        $validated = $request->validate([
            'cargo_company_id' => ['required', 'exists:cargo_companies,id'],
            'payment_type' => ['nullable', 'in:sender,receiver'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $company = CargoCompany::query()->findOrFail((int) $validated['cargo_company_id']);
        if (! $company->is_active || ! $company->isApiReady()) {
            return back()->withInput()->with('error', 'Seçilen kargo firmasının API bilgileri tanımlı değil.');
        }

        $order->load(['shipments.cargoCompany']);
        $existing = $order->latestCargoShipment();
        if ($existing) {
            return redirect()
                ->route('orders.show', $order)
                ->with('error', 'Bu sipariş zaten kargoya gönderilmiş ('.$existing->tracking_number.').');
        }

        $locality = $order->resolveShippingLocality();
        $payload = [
            'payment_type' => $validated['payment_type'] ?? 'sender',
            'receiver_name' => $order->customer_name,
            'receiver_phone' => $order->customer_phone,
            'receiver_address' => $locality['street'] !== '' ? $locality['street'] : $order->shipping_address,
            'receiver_city' => $locality['city'],
            'receiver_town' => $locality['town'],
            'weight' => $validated['weight'] ?? 1,
            'notes' => $validated['notes'] ?? null,
            'allow_local_fallback' => false,
        ];

        if ($company->provider_type === 'yurtici') {
            $missing = [];
            if (mb_strlen((string) $payload['receiver_name']) < 5) {
                $missing[] = 'alıcı adı';
            }
            if (mb_strlen((string) $payload['receiver_address']) < 10) {
                $missing[] = 'adres';
            }
            if ($payload['receiver_city'] === '' || preg_match('/^\d+$/', $payload['receiver_city'])) {
                $missing[] = 'il';
            }
            if ($payload['receiver_town'] === '' || preg_match('/^\d+$/', $payload['receiver_town'])) {
                $missing[] = 'ilçe';
            }
            if (! filled($payload['receiver_phone'])) {
                $missing[] = 'telefon';
            }

            if ($missing !== []) {
                return back()->withInput()->with('error', 'Eksik bilgi: '.implode(', ', $missing));
            }
        }

        try {
            $shipment = $this->cargoService->createShipment($order->id, $company->id, $payload);

            return redirect()
                ->route('orders.show', $order)
                ->with('success', 'Kargo API’ye gönderildi. Takip no: '.$shipment->tracking_number.
                    (str_starts_with(ltrim((string) $shipment->notes), '[api]') ? ' (canlı API)' : ''));
        } catch (CargoException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Kargo oluşturulurken beklenmeyen bir hata oluştu.');
        }
    }

    /**
     * Send one or many orders to cargo API.
     */
    public function bulkSendCargo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'exists:shopify_orders,id'],
            'cargo_company_id' => ['required', 'exists:cargo_companies,id'],
            'payment_type' => ['nullable', 'in:sender,receiver'],
            'weight' => ['nullable', 'numeric', 'min:0'],
        ]);

        $company = CargoCompany::query()->findOrFail((int) $validated['cargo_company_id']);
        $orders = ShopifyOrder::query()
            ->with(['shipments.cargoCompany'])
            ->whereIn('id', $validated['order_ids'])
            ->get()
            ->keyBy('id');

        $success = [];
        $failed = [];
        $skipped = [];

        foreach ($validated['order_ids'] as $orderId) {
            /** @var ShopifyOrder|null $order */
            $order = $orders->get($orderId);
            if (! $order) {
                $failed[] = [
                    'order_id' => $orderId,
                    'order_number' => '#'.$orderId,
                    'message' => 'Sipariş bulunamadı.',
                ];
                continue;
            }

            $existing = $order->latestCargoShipment();
            if ($existing) {
                $skipped[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'message' => 'Zaten kargoya gönderilmiş ('.$existing->tracking_number.').',
                    'tracking_number' => $existing->tracking_number,
                    'shipment_id' => $existing->id,
                    'provider' => $existing->cargoCompany?->provider_type,
                ];
                continue;
            }

            $locality = $order->resolveShippingLocality();
            $payload = [
                'payment_type' => $validated['payment_type'] ?? 'sender',
                'receiver_name' => $order->customer_name,
                'receiver_phone' => $order->customer_phone,
                'receiver_address' => $locality['street'] !== '' ? $locality['street'] : $order->shipping_address,
                'receiver_city' => $locality['city'],
                'receiver_town' => $locality['town'],
                'weight' => $validated['weight'] ?? 1,
                'allow_local_fallback' => false,
            ];

            if ($company->provider_type === 'yurtici') {
                $missing = [];
                if (mb_strlen((string) $payload['receiver_name']) < 5) {
                    $missing[] = 'alıcı adı';
                }
                if (mb_strlen((string) $payload['receiver_address']) < 10) {
                    $missing[] = 'adres';
                }
                if ($payload['receiver_city'] === '' || preg_match('/^\d+$/', $payload['receiver_city'])) {
                    $missing[] = 'il';
                }
                if ($payload['receiver_town'] === '' || preg_match('/^\d+$/', $payload['receiver_town'])) {
                    $missing[] = 'ilçe';
                }
                if (! filled($payload['receiver_phone'])) {
                    $missing[] = 'telefon';
                }

                if ($missing !== []) {
                    $failed[] = [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'message' => 'Eksik bilgi: '.implode(', ', $missing),
                    ];
                    continue;
                }
            }

            try {
                $shipment = $this->cargoService->createShipment(
                    $order->id,
                    $company->id,
                    $payload
                );

                $success[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'shipment_id' => $shipment->id,
                    'tracking_number' => $shipment->tracking_number,
                    'tracking_url' => $shipment->tracking_url,
                    'provider' => $company->provider_type,
                    'provider_name' => $company->name,
                    'message' => 'Kargo oluşturuldu.',
                ];
            } catch (CargoException $e) {
                $failed[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'message' => $e->getMessage(),
                ];
            } catch (Throwable $e) {
                report($e);
                $failed[] = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'message' => 'Beklenmeyen hata: '.$e->getMessage(),
                ];
            }
        }

        $okCount = count($success);
        $failCount = count($failed);
        $skipCount = count($skipped);

        return response()->json([
            'success' => $failCount === 0,
            'message' => "{$okCount} başarılı, {$failCount} hatalı, {$skipCount} atlandı.",
            'summary' => [
                'success' => $okCount,
                'failed' => $failCount,
                'skipped' => $skipCount,
            ],
            'results' => [
                'success' => $success,
                'failed' => $failed,
                'skipped' => $skipped,
            ],
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'provider_type' => $company->provider_type,
            ],
        ]);
    }

    /**
     * Bulk print cargo labels for selected orders.
     */
    public function bulkPrintLabels(Request $request): View|RedirectResponse
    {
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'exists:shopify_orders,id'],
        ]);

        $orders = ShopifyOrder::query()
            ->with(['shipments.cargoCompany'])
            ->whereIn('id', $validated['order_ids'])
            ->get();

        $labels = [];
        foreach ($orders as $order) {
            $shipment = $order->latestCargoShipment();
            if (! $shipment) {
                continue;
            }

            $labels[] = ShippingLabelProfile::fromShipment($shipment);
        }

        if ($labels === []) {
            return redirect()
                ->route('orders.index')
                ->with('error', 'Seçilen siparişlerde yazdırılabilir kargo kaydı yok. Önce kargo servisine gönderin.');
        }

        return view('labels.print', [
            'title' => 'Toplu Kargo Barkodları',
            'backUrl' => route('orders.index'),
            'labels' => $labels,
        ]);
    }

    /**
     * Print a single cargo barcode label for an order.
     */
    public function printLabel(ShopifyOrder $order): View|RedirectResponse
    {
        $order->load(['shipments.cargoCompany']);
        $shipment = $order->latestCargoShipment();

        if (! $shipment) {
            return redirect()
                ->route('orders.show', $order)
                ->with('error', 'Bu siparişte yazdırılabilir barkod yok. Önce kargo servisine gönderin.');
        }

        return view('labels.print', [
            'title' => 'Kargo Barkodu '.$order->order_number,
            'backUrl' => route('orders.show', $order),
            'labels' => [ShippingLabelProfile::fromShipment($shipment)],
        ]);
    }

    /**
     * Upload invoice document for an order.
     */
    public function uploadInvoice(Request $request, ShopifyOrder $order): RedirectResponse
    {
        $validated = $request->validate([
            'invoice' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ], [
            'invoice.required' => 'Fatura belgesi seçin.',
            'invoice.mimes' => 'Fatura PDF veya görsel (JPG, PNG, WEBP) olmalıdır.',
        ]);

        $file = $validated['invoice'];
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $filename = Str::uuid()->toString().'.'.$extension;
        $path = $file->storeAs('order-invoices/'.$order->id, $filename, 'public');

        if ($order->invoice_path && Storage::disk('public')->exists($order->invoice_path)) {
            Storage::disk('public')->delete($order->invoice_path);
        }

        $order->update([
            'invoice_path' => $path,
            'invoice_original_name' => $file->getClientOriginalName(),
            'invoice_uploaded_at' => now(),
            'invoice_token' => $order->newInvoiceToken(),
            'shopify_needs_push' => true,
        ]);

        $fresh = $order->fresh(['shipments.cargoCompany']) ?? $order;
        $invoiceUrl = $fresh->invoiceUrl();
        if (filled($invoiceUrl)) {
            $fresh->update([
                'notes' => ShopifyOrder::appendInvoiceLine($fresh->notes, (string) $invoiceUrl),
            ]);
            $fresh = $fresh->fresh(['shipments.cargoCompany']) ?? $fresh;
        }

        $push = $this->orderLifecycle->syncLocalStateToShopify($fresh);

        $redirect = redirect()->route('orders.show', $order);
        $message = 'Fatura yüklendi. Adres: '.$invoiceUrl;

        if ($push['success']) {
            return $redirect->with('success', $message.' Shopify sipariş detayına da yazıldı.');
        }

        if (! $push['skipped']) {
            return $redirect->with('success', $message)->with('warning', $push['message']);
        }

        return $redirect->with('success', $message);
    }

    /**
     * Remove uploaded invoice.
     */
    public function destroyInvoice(ShopifyOrder $order): RedirectResponse
    {
        if ($order->invoice_path && Storage::disk('public')->exists($order->invoice_path)) {
            Storage::disk('public')->delete($order->invoice_path);
        }

        $order->update([
            'invoice_path' => null,
            'invoice_original_name' => null,
            'invoice_uploaded_at' => null,
            'invoice_token' => null,
            'uyumsoft_einvoice_uuid' => null,
            'notes' => ShopifyOrder::stripInvoiceLines($order->notes),
            'shopify_needs_push' => true,
        ]);

        $this->orderLifecycle->syncLocalStateToShopify($order->fresh(['shipments.cargoCompany']));

        return redirect()
            ->route('orders.show', $order)
            ->with('success', 'Fatura belgesi silindi.');
    }

    /**
     * Send shipped + invoice email to the customer.
     */
    public function sendShipmentInvoiceMail(Request $request, ShopifyOrder $order, MailService $mailService): RedirectResponse
    {
        $redirect = redirect()->route('orders.show', $order);

        if ($request->isMethod('get')) {
            return $redirect->with('error', 'Mail göndermek için sipariş sayfasındaki butonu kullanın.');
        }

        try {
            $shipment = $order->latestCargoShipment();

            if (! $shipment) {
                return $redirect->with('error', 'Kargoya verilmiş bir gönderi yok. Önce kargo oluşturun.');
            }

            if (! $order->hasInvoice()) {
                return $redirect->with('error', 'Önce fatura belgesi yükleyin.');
            }

            if (! filled($order->customer_email)) {
                return $redirect->with('error', 'Müşteri e-posta adresi yok.');
            }

            return $this->redirectMailResult($order, $mailService->sendShipmentAndInvoiceNotification($order, $shipment));
        } catch (Throwable $e) {
            try {
                report($e);
            } catch (Throwable) {
            }

            return $redirect->with('error', 'Mail gönderilemedi: '.$e->getMessage());
        }
    }

    /**
     * Send invoice download link email.
     */
    public function sendInvoiceNoticeMail(ShopifyOrder $order, MailService $mailService): RedirectResponse
    {
        $redirect = redirect()->route('orders.show', $order);

        try {
            if (! $order->hasInvoice()) {
                return $redirect->with('error', 'Önce fatura belgesi oluşmalı.');
            }

            if (! filled($order->customer_email)) {
                return $redirect->with('error', 'Müşteri e-posta adresi yok.');
            }

            return $this->redirectMailResult($order, $mailService->sendInvoiceNotice($order));
        } catch (Throwable $e) {
            try {
                report($e);
            } catch (Throwable) {
            }

            return $redirect->with('error', 'Fatura maili gönderilemedi: '.$e->getMessage());
        }
    }

    /**
     * Send cargo tracking email.
     */
    public function sendCargoNoticeMail(ShopifyOrder $order, MailService $mailService): RedirectResponse
    {
        $redirect = redirect()->route('orders.show', $order);

        try {
            $shipment = $order->latestCargoShipment();
            $shipped = $shipment && in_array($shipment->status, [Shipment::STATUS_SHIPPED, Shipment::STATUS_DELIVERED], true);

            if (! $shipped && ! in_array((string) $order->fulfillment_status, ['fulfilled', 'delivered'], true)) {
                return $redirect->with('error', 'Kargo bilgilendirme maili yalnızca kargoya verildi durumunda gönderilir.');
            }

            if (! $shipment) {
                return $redirect->with('error', 'Kargoya verilmiş bir gönderi yok.');
            }

            if (! filled($order->customer_email)) {
                return $redirect->with('error', 'Müşteri e-posta adresi yok.');
            }

            return $this->redirectMailResult($order, $mailService->sendCargoNotice($order, $shipment));
        } catch (Throwable $e) {
            try {
                report($e);
            } catch (Throwable) {
            }

            return $redirect->with('error', 'Kargo maili gönderilemedi: '.$e->getMessage());
        }
    }

    private function redirectMailResult(ShopifyOrder $order, Notification $notification): RedirectResponse
    {
        $redirect = redirect()->route('orders.show', $order);
        $report = method_exists($notification, 'mailReport') ? $notification->mailReport() : [];
        $message = method_exists($notification, 'reportMessage')
            ? $notification->reportMessage()
            : trim((string) ($notification->error ?: 'Mail işlemi tamamlandı.'));

        if ($notification->status === 'failed') {
            return $redirect->with('error', $message !== '' ? $message : 'Mail gönderilemedi.');
        }

        $success = $message
            .' Alıcı: '.$notification->recipient
            .' · From: '.($report['from'] ?? '-')
            .' · SMTP: '.($report['host'] ?? '-')
            .' · Ek: '.($report['attachment'] ?? 'indirme linki');

        if (filled($report['warning'] ?? null)) {
            return $redirect->with('success', $success)->with('warning', (string) $report['warning']);
        }

        return $redirect->with('success', $success);
    }

    /**
     * Cancel cargo via provider API from the order page.
     */
    public function cancelShipment(ShopifyOrder $order, Shipment $shipment): RedirectResponse
    {
        if ((int) $shipment->shopify_order_id !== (int) $order->id) {
            abort(404);
        }

        try {
            $this->cargoService->cancelShipment($shipment);

            return redirect()
                ->route('orders.show', $order)
                ->with('success', 'Kargo iptal edildi ve kayıt silindi.');
        } catch (CargoException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Kargo iptal edilirken beklenmeyen bir hata oluştu.');
        }
    }

    private function syncPendingToUyumsoft(int $limit): string
    {
        try {
            $result = $this->uyumSoftOrderSyncService->sync($limit);

            return $result['message'];
        } catch (UyumSoftException $e) {
            return 'UyumSoft: '.$e->getMessage();
        } catch (Throwable $e) {
            report($e);

            return 'UyumSoft senkronu başarısız.';
        }
    }

    private function syncOrderToUyumsoft(ShopifyOrder $order): string
    {
        try {
            $result = $this->uyumSoftOrderSyncService->syncOrder($order);

            return $result['message'];
        } catch (UyumSoftException $e) {
            return 'UyumSoft: '.$e->getMessage();
        } catch (Throwable $e) {
            report($e);

            return 'UyumSoft senkronu başarısız.';
        }
    }
}
