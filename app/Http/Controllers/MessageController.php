<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ShopifyCustomer;
use App\Services\CustomerMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class MessageController extends Controller
{
    public function __construct(
        private readonly CustomerMessageService $messages
    ) {
    }

    public function create(): View
    {
        return view('messages.send', [
            'smsTemplates' => $this->messages->smsTemplateOptions(),
            'mailTemplates' => $this->messages->mailTemplateOptions(),
            'smsConfigured' => $this->messages->smsConfigured(),
            'mailConfigured' => $this->messages->mailConfigured(),
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Mesaj Gönder'],
            ],
        ]);
    }

    public function customersSearch(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());

        $query = ShopifyCustomer::query()->orderByDesc('last_order_at')->limit(30);

        if ($q !== '') {
            $query->where(function ($builder) use ($q): void {
                $builder->where('full_name', 'like', "%{$q}%")
                    ->orWhere('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            });
        }

        $results = $query->get()->map(fn (ShopifyCustomer $customer): array => [
            'id' => $customer->id,
            'text' => $this->messages->customerSelectLabel($customer),
        ])->values();

        return response()->json(['results' => $results]);
    }

    public function customerPreview(int $customerId): JsonResponse
    {
        $customer = ShopifyCustomer::query()->findOrFail($customerId);
        $data = $this->messages->templateDataForCustomer($customer);

        return response()->json([
            'data' => $data,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'sms_preview' => [
                'order-confirmation-sms' => $this->messages->previewSms('order-confirmation-sms', $data),
                'shipment-sms' => $this->messages->previewSms('shipment-sms', $data),
            ],
            'mail_preview' => collect($this->messages->mailTemplateOptions())
                ->mapWithKeys(fn (array $row): array => [
                    $row['slug'] => $this->messages->previewMail($row['slug'], $data),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:shopify_customers,id'],
            'channel' => ['required', 'in:sms,mail'],
            'mode' => ['required', 'in:template,manual'],
            'template_slug' => ['nullable', 'string', 'max:100'],
            'manual_message' => ['nullable', 'string', 'max:1000'],
            'manual_subject' => ['nullable', 'string', 'max:255'],
            'manual_body' => ['nullable', 'string', 'max:20000'],
        ]);

        $customer = ShopifyCustomer::query()->findOrFail((int) $validated['customer_id']);

        try {
            if ($validated['channel'] === 'sms') {
                if (! $this->messages->smsConfigured()) {
                    return back()->withInput()->with('error', 'SMS ayarları tanımlı değil. Ayarlar → SMS bölümünü doldurun.');
                }

                $notification = $this->messages->sendCustomerSms(
                    $customer,
                    $validated['mode'],
                    $validated['manual_message'] ?? null,
                    $validated['template_slug'] ?? null
                );
            } else {
                $notification = $this->messages->sendCustomerMail(
                    $customer,
                    $validated['mode'],
                    $validated['manual_subject'] ?? null,
                    $validated['manual_body'] ?? null,
                    $validated['template_slug'] ?? null
                );
            }

            if ($notification->status === 'failed') {
                return back()
                    ->withInput()
                    ->with('error', 'Gönderim başarısız: '.$notification->reportMessage());
            }

            return redirect()
                ->route('messages.send')
                ->with('success', 'Mesaj gönderildi ve kayıtlara işlendi.');
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', $e->getMessage());
        }
    }
}
