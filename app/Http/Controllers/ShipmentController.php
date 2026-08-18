<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\CargoException;
use App\Jobs\CreateShipment as CreateShipmentJob;
use App\Jobs\UpdateCargoTracking;
use App\Models\CargoCompany;
use App\Models\Shipment;
use App\Models\ShopifyOrder;
use App\Services\CargoService;
use App\Services\OrderLifecycleService;
use App\Support\ShippingLabelProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShipmentController extends Controller
{
    public function __construct(
        private readonly CargoService $cargoService
    ) {
    }

    /**
     * List shipments.
     */
    public function index(Request $request): View
    {
        Shipment::query()->where('status', Shipment::STATUS_CANCELLED)->delete();

        $query = Shipment::query()
            ->with(['cargoCompany', 'order'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('cargo_company_id')) {
            $query->where('cargo_company_id', $request->integer('cargo_company_id'));
        }

        if ($request->filled('q')) {
            $q = $request->string('q')->toString();
            $query->where(function ($builder) use ($q) {
                $builder->where('order_number', 'like', "%{$q}%")
                    ->orWhere('tracking_number', 'like', "%{$q}%")
                    ->orWhere('receiver_name', 'like', "%{$q}%");
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        return view('shipments.index', [
            'shipments' => $query->paginate(20)->withQueryString(),
            'companies' => CargoCompany::query()->orderBy('name')->get(),
            'filters' => $request->only(['status', 'cargo_company_id', 'q', 'date_from', 'date_to']),
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Kargo'],
            ],
        ]);
    }

    /**
     * Show shipment details.
     */
    public function show(Shipment $shipment): View
    {
        $shipment->load(['cargoCompany', 'order.items', 'trackingEvents']);

        $lastQuery = is_array($shipment->provider_payload['last_query'] ?? null)
            ? $shipment->provider_payload['last_query']
            : null;
        $createMeta = is_array($shipment->provider_payload['create'] ?? null)
            ? $shipment->provider_payload['create']
            : null;

        return view('shipments.show', [
            'shipment' => $shipment,
            'trackingEvents' => $shipment->trackingEvents,
            'lastQuery' => $lastQuery,
            'createMeta' => $createMeta,
            'isYurtici' => $shipment->cargoCompany?->provider_type === 'yurtici',
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Kargo', 'url' => route('shipments.index')],
                ['label' => $shipment->tracking_number ?: ('#'.$shipment->id)],
            ],
        ]);
    }

    /**
     * Create shipment form for an order.
     */
    public function create(ShopifyOrder $order): View
    {
        $order->load('shipments');

        return view('shipments.create', [
            'order' => $order,
            'companies' => CargoCompany::apiReady(),
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Siparişler', 'url' => route('orders.index')],
                ['label' => $order->order_number, 'url' => route('orders.show', $order)],
                ['label' => 'Kargo Oluştur'],
            ],
        ]);
    }

    /**
     * Store a new shipment.
     */
    public function store(Request $request, ShopifyOrder $order): RedirectResponse
    {
        $validated = $request->validate([
            'cargo_company_id' => ['required', 'exists:cargo_companies,id'],
            'receiver_name' => ['required', 'string', 'min:5', 'max:255'],
            'receiver_phone' => ['nullable', 'string', 'max:50'],
            'receiver_address' => ['required', 'string', 'min:10'],
            'receiver_city' => ['nullable', 'string', 'max:100'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'insurance' => ['nullable', 'numeric', 'min:0'],
            'cargo_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'payment_type' => ['nullable', 'in:sender,receiver'],
            'receiver_town' => ['nullable', 'string', 'max:100'],
            'queue' => ['nullable', 'boolean'],
        ]);

        $company = CargoCompany::query()->findOrFail((int) $validated['cargo_company_id']);
        if ($company->provider_type === 'yurtici') {
            $request->validate([
                'receiver_phone' => ['required', 'string', 'max:50'],
                'receiver_city' => ['required', 'string', 'max:100'],
                'receiver_town' => ['required', 'string', 'max:100'],
            ]);
            $validated['receiver_phone'] = $request->input('receiver_phone');
            $validated['receiver_city'] = $request->input('receiver_city');
            $validated['receiver_town'] = $request->input('receiver_town');
        }

        try {
            if (! empty($validated['queue'])) {
                CreateShipmentJob::dispatch($order->id, (int) $validated['cargo_company_id'], $validated);

                return redirect()
                    ->route('orders.show', $order)
                    ->with('success', 'Kargo oluşturma işlemi kuyruğa alındı.');
            }

            $validated['allow_local_fallback'] = false;

            $shipment = $this->cargoService->createShipment(
                $order->id,
                (int) $validated['cargo_company_id'],
                $validated
            );

            return redirect()
                ->route('shipments.show', $shipment)
                ->with('success', 'Kargo kaydı oluşturuldu: '.$shipment->tracking_number.
                    (str_starts_with(ltrim((string) $shipment->notes), '[api]') ? ' (canlı Yurtiçi API)' : ''));
        } catch (CargoException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'Kargo oluşturulurken beklenmeyen bir hata oluştu.');
        }
    }

    /**
     * Update shipment status manually.
     */
    public function updateStatus(Request $request, Shipment $shipment): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:pending,shipped,delivered,returned,cancelled'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'tracking_url' => ['nullable', 'url', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $shipment->update([
            'status' => $validated['status'],
            'tracking_number' => $validated['tracking_number'] ?? $shipment->tracking_number,
            'tracking_url' => $validated['tracking_url'] ?? $shipment->tracking_url,
            'notes' => $validated['notes'] ?? $shipment->notes,
            'shipped_at' => $validated['status'] === Shipment::STATUS_SHIPPED && ! $shipment->shipped_at
                ? now()
                : $shipment->shipped_at,
            'delivered_at' => $validated['status'] === Shipment::STATUS_DELIVERED
                ? now()
                : $shipment->delivered_at,
        ]);

        if ($validated['status'] === Shipment::STATUS_DELIVERED && $shipment->order) {
            app(OrderLifecycleService::class)->markDelivered($shipment->order, $shipment->fresh(['cargoCompany', 'order']));
        }

        return redirect()
            ->route('shipments.show', $shipment)
            ->with('success', 'Kargo durumu güncellendi.');
    }

    /**
     * Refresh label file.
     */
    public function generateLabel(Shipment $shipment): RedirectResponse
    {
        try {
            $this->cargoService->generateLabel($shipment->id);

            return redirect()
                ->route('shipments.print-label', $shipment)
                ->with('success', 'Etiket hazır.');
        } catch (CargoException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Refresh invoice file.
     */
    public function generateInvoice(Shipment $shipment): RedirectResponse
    {
        try {
            $this->cargoService->generateInvoice($shipment->id);

            return redirect()
                ->route('shipments.print-invoice', $shipment)
                ->with('success', 'Fatura hazır.');
        } catch (CargoException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Verify the shipment exists on Yurtiçi (queryShipment, keyType=0).
     */
    public function queryYurticiVerify(Shipment $shipment): JsonResponse
    {
        return $this->yurticiQueryResponse($shipment, 'verify');
    }

    /**
     * Query live Yurtiçi operationStatus (queryShipment, keyType=0).
     */
    public function queryYurticiStatus(Shipment $shipment): JsonResponse
    {
        return $this->yurticiQueryResponse($shipment, 'status');
    }

    private function yurticiQueryResponse(Shipment $shipment, string $mode): JsonResponse
    {
        try {
            $result = $this->cargoService->queryYurtici($shipment);
            $registered = (bool) ($result['registered'] ?? $result['success'] ?? false);

            $message = $mode === 'verify'
                ? ($registered
                    ? 'Gönderi Yurtiçi Kargo sisteminde kayıtlı.'
                    : 'Yurtiçi Kargo bu cargoKey ile kayıt bulamadı.')
                : (string) ($result['operation_status_label'] ?? $result['message'] ?? 'Sorgu tamamlandı.');

            return response()->json([
                'success' => $registered,
                'mode' => $mode,
                'message' => $message,
                'result' => [
                    'out_flag' => $result['out_flag'] ?? null,
                    'job_id' => $result['job_id'] ?? $shipment->fresh()?->cargo_job_id,
                    'cargo_key' => $result['cargo_key'] ?? $shipment->cargoKey(),
                    'operation_status' => $result['operation_status'] ?? null,
                    'operation_status_label' => $result['operation_status_label'] ?? null,
                    'operation_message' => $result['operation_message'] ?? null,
                    'doc_id' => $result['doc_id'] ?? null,
                    'registered' => $registered,
                ],
            ], $registered ? 200 : 422);
        } catch (CargoException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Printable label view.
     */
    public function printLabel(Shipment $shipment): View
    {
        $shipment->load(['cargoCompany', 'order']);

        return view('labels.print', [
            'title' => 'Kargo Barkodu '.($shipment->tracking_number ?: $shipment->order_number),
            'backUrl' => route('shipments.show', $shipment),
            'labels' => [ShippingLabelProfile::fromShipment($shipment)],
        ]);
    }

    /**
     * Printable invoice view.
     */
    public function printInvoice(Shipment $shipment): View
    {
        $shipment->load(['cargoCompany', 'order.items']);

        return view('shipments.print-invoice', [
            'shipment' => $shipment,
        ]);
    }

    /**
     * Trigger tracking sync.
     */
    public function syncTracking(Request $request): RedirectResponse
    {
        try {
            if ($request->boolean('queue')) {
                UpdateCargoTracking::dispatch();

                return redirect()
                    ->route('shipments.index')
                    ->with('success', 'Takip güncellemesi kuyruğa alındı.');
            }

            $result = $this->cargoService->updateTrackingStatus();

            return redirect()
                ->route('shipments.index')
                ->with('success', $result['message']);
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('shipments.index')
                ->with('error', 'Takip güncellemesi başarısız.');
        }
    }

    /**
     * Cancel shipment via cargo company API.
     */
    public function cancel(Shipment $shipment): RedirectResponse
    {
        try {
            $orderId = $shipment->shopify_order_id;
            $this->cargoService->cancelShipment($shipment);

            return redirect()
                ->route('orders.show', $orderId)
                ->with('success', 'Kargo iptal edildi ve kayıt silindi.');
        } catch (CargoException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Kargo iptal edilirken beklenmeyen bir hata oluştu.');
        }
    }
}
