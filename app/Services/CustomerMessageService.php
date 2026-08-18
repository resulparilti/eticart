<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MailTemplate;
use App\Models\Notification;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyOrder;
use App\Models\SmsTemplate;
use App\Support\ReplacesTemplateVariables;
use Illuminate\Support\Str;

class CustomerMessageService
{
    use ReplacesTemplateVariables;

    public function __construct(
        private readonly MailService $mailService,
        private readonly SmsService $smsService
    ) {
    }

    public function smsConfigured(): bool
    {
        return $this->smsService->isConfigured();
    }

    public function mailConfigured(): bool
    {
        return filled(config('mail.from.address')) || filled(config('mail.mailers.smtp.host'));
    }

    /**
     * @return array<string, mixed>
     */
    public function templateDataForCustomer(ShopifyCustomer $customer, ?ShopifyOrder $order = null): array
    {
        $order ??= $customer->orders()->with(['shipments.cargoCompany'])->latest('id')->first();

        if ($order) {
            return $this->orderTemplateData($order);
        }

        return [
            'customer_name' => $customer->displayName(),
            'customer_email' => (string) $customer->email,
            'customer_phone' => (string) $customer->phone,
            'order_number' => '-',
            'total_price' => '0,00',
            'currency' => 'TL',
            'tracking_number' => '',
            'tracking_url' => '',
            'cargo_company' => '',
            'status' => '',
            'payment_status' => '',
            'fulfillment_status' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function templateDataForOrder(ShopifyOrder $order): array
    {
        return $this->orderTemplateData($order->loadMissing(['shipments.cargoCompany']));
    }

    /**
     * @return array<string, mixed>
     */
    private function orderTemplateData(ShopifyOrder $order): array
    {
        $shipment = $order->latestCargoShipment() ?? $order->latestActiveShipment();

        return [
            'customer_name' => $order->customer_name,
            'customer_email' => (string) $order->customer_email,
            'customer_phone' => (string) $order->customer_phone,
            'order_number' => $order->order_number,
            'total_price' => number_format((float) $order->total_price, 2, ',', '.'),
            'currency' => $order->currency ?: 'TL',
            'tracking_number' => (string) ($shipment?->publicTrackingNumber() ?? $shipment?->tracking_number ?? ''),
            'tracking_url' => (string) ($shipment?->tracking_url ?? ''),
            'cargo_company' => (string) ($shipment?->cargoCompany?->name ?? ''),
            'status' => (string) $order->fulfillment_status,
            'payment_status' => (string) $order->payment_status,
            'fulfillment_status' => (string) $order->fulfillment_status,
        ];
    }

    public function sendOrderSms(ShopifyOrder $order, string $mode, ?string $manualMessage = null, ?string $templateSlug = null): Notification
    {
        $phone = (string) $order->customer_phone;
        $data = $this->templateDataForOrder($order);

        if ($mode === 'manual') {
            $message = trim((string) $manualMessage);
            if ($message === '') {
                throw new \InvalidArgumentException('SMS metni boş olamaz.');
            }

            return $this->smsService->send($phone, $message, $order);
        }

        $slug = $templateSlug ?: 'order-confirmation-sms';

        return $this->smsService->sendFromTemplate($phone, $slug, $data, $order);
    }

    public function sendCustomerSms(ShopifyCustomer $customer, string $mode, ?string $manualMessage = null, ?string $templateSlug = null): Notification
    {
        $phone = (string) ($customer->phone ?: $customer->orders()->latest('id')->value('customer_phone'));
        $data = $this->templateDataForCustomer($customer);
        $notifiable = $customer->orders()->latest('id')->first() ?? $customer;

        if ($mode === 'manual') {
            $message = trim((string) $manualMessage);
            if ($message === '') {
                throw new \InvalidArgumentException('SMS metni boş olamaz.');
            }

            return $this->smsService->send($phone, $message, $notifiable);
        }

        $slug = $templateSlug ?: 'order-confirmation-sms';

        return $this->smsService->sendFromTemplate($phone, $slug, $data, $notifiable);
    }

    public function sendCustomerMail(ShopifyCustomer $customer, string $mode, ?string $manualSubject = null, ?string $manualBody = null, ?string $templateSlug = null): Notification
    {
        $email = (string) $customer->email;
        $order = $customer->orders()->latest('id')->first();
        $data = $this->templateDataForCustomer($customer, $order);

        if ($mode === 'manual') {
            $subject = trim((string) $manualSubject);
            $body = trim((string) $manualBody);
            if ($subject === '' || $body === '') {
                throw new \InvalidArgumentException('Mail konusu ve içeriği zorunludur.');
            }

            return $this->mailService->sendCustom($email, $subject, $body, $order ?? $customer);
        }

        $template = MailTemplate::query()
            ->where('slug', $templateSlug ?: 'order-confirmation')
            ->where('is_active', true)
            ->first();

        $subject = $this->replaceVariables((string) ($template?->subject ?? 'Bildirim - {{order_number}}'), $data);
        $body = $this->replaceVariables((string) ($template?->body ?? 'Merhaba {{customer_name}}'), $data);

        return $this->mailService->sendCustom($email, $subject, $body, $order ?? $customer);
    }

    /**
     * Preview SMS body for UI.
     */
    public function previewSms(string $templateSlug, array $data): string
    {
        $template = SmsTemplate::query()->where('slug', $templateSlug)->where('is_active', true)->first();
        $body = $template?->body ?? 'Bildirim: {{order_number}}';

        return $this->replaceVariables($body, $data);
    }

    /**
     * Preview mail subject/body for UI.
     *
     * @return array{subject: string, body: string}
     */
    public function previewMail(string $templateSlug, array $data): array
    {
        $template = MailTemplate::query()->where('slug', $templateSlug)->where('is_active', true)->first();

        return [
            'subject' => $this->replaceVariables((string) ($template?->subject ?? 'Bildirim'), $data),
            'body' => $this->replaceVariables((string) ($template?->body ?? ''), $data),
        ];
    }

    /**
     * @return array<int, array{slug: string, label: string}>
     */
    public function smsTemplateOptions(): array
    {
        return [
            ['slug' => 'order-confirmation-sms', 'label' => 'Sipariş Onayı SMS'],
            ['slug' => 'shipment-sms', 'label' => 'Kargo SMS'],
        ];
    }

    /**
     * @return array<int, array{slug: string, label: string}>
     */
    public function mailTemplateOptions(): array
    {
        return MailTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['slug', 'name'])
            ->map(static fn (MailTemplate $template): array => [
                'slug' => (string) $template->slug,
                'label' => (string) $template->name,
            ])
            ->all();
    }

    public function customerSelectLabel(ShopifyCustomer $customer): string
    {
        $parts = array_filter([
            $customer->displayName(),
            $customer->email,
            $customer->phone,
        ]);

        return Str::limit(implode(' · ', $parts), 120);
    }
}
