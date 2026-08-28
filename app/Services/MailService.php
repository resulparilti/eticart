<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\CargoNoticeMail;
use App\Mail\GenericMail;
use App\Mail\InvoiceNoticeMail;
use App\Mail\OrderConfirmationMail;
use App\Mail\OrderStatusUpdateMail;
use App\Mail\ShipmentInvoiceMail;
use App\Mail\ShipmentNotificationMail;
use App\Support\StatusLabels;
use App\Models\MailTemplate;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\ShopifyOrder;
use App\Support\ReplacesTemplateVariables;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MailService
{
    use ReplacesTemplateVariables;

    /**
     * Send order confirmation email.
     */
    public function sendOrderConfirmation(ShopifyOrder $order): Notification
    {
        $template = $this->template('order-confirmation');
        $data = $this->orderData($order);
        $subject = $this->replaceVariables($template?->subject ?? 'Siparişiniz Alındı - {{order_number}}', $data);
        $body = $this->replaceVariables($template?->body ?? 'Merhaba {{customer_name}}, {{order_number}} siparişiniz alındı.', $data);

        return $this->dispatchMail(
            recipient: (string) $order->customer_email,
            subject: $subject,
            body: $body,
            mailable: new OrderConfirmationMail($order, compact('subject', 'body')),
            notifiable: $order
        );
    }

    /**
     * Send shipment notification email.
     */
    public function sendShipmentNotification(Shipment $shipment): Notification
    {
        $shipment->loadMissing('order');
        $template = $this->template('shipment-notification');
        $data = array_merge($this->orderData($shipment->order), [
            'tracking_number' => $shipment->tracking_number,
            'tracking_url' => $shipment->tracking_url,
            'cargo_company' => $shipment->cargoCompany?->name ?? 'Kargo',
            'customer_name' => $shipment->receiver_name ?: $shipment->order?->customer_name,
        ]);
        $subject = $this->replaceVariables($template?->subject ?? 'Kargonuz Yola Çıktı - {{order_number}}', $data);
        $body = $this->replaceVariables($template?->body ?? 'Takip no: {{tracking_number}}', $data);
        $recipient = $shipment->order?->customer_email;

        return $this->dispatchMail(
            recipient: (string) $recipient,
            subject: $subject,
            body: $body,
            mailable: new ShipmentNotificationMail($shipment, compact('subject', 'body')),
            notifiable: $shipment
        );
    }

    /**
     * Send order status update email.
     */
    public function sendOrderStatus(ShopifyOrder $order, ?string $status = null): Notification
    {
        $template = $this->template('order-status-update');
        $data = array_merge($this->orderData($order), [
            'status' => $status ?: $order->fulfillment_status,
        ]);
        $subject = $this->replaceVariables($template?->subject ?? 'Sipariş Durumu - {{order_number}}', $data);
        $body = $this->replaceVariables($template?->body ?? 'Yeni durum: {{status}}', $data);

        return $this->dispatchMail(
            recipient: (string) $order->customer_email,
            subject: $subject,
            body: $body,
            mailable: new OrderStatusUpdateMail($order, [
                'subject' => $subject,
                'body' => $body,
                'status' => $data['status'],
            ]),
            notifiable: $order
        );
    }

    /**
     * Kargoya verildi + fatura oluştu bildirimi (fatura ekli).
     */
    public function sendShipmentAndInvoiceNotification(ShopifyOrder $order, Shipment $shipment): Notification
    {
        try {
            return $this->buildAndSendShipmentInvoice($order, $shipment);
        } catch (Throwable $e) {
            try {
                Log::channel('stack')->error('Shipment invoice mail failed', [
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            } catch (Throwable) {
            }

            try {
                $failed = Notification::query()->create([
                    'type' => 'mail',
                    'recipient' => (string) $order->customer_email,
                    'subject' => 'Sipariş '.$order->order_number,
                    'body' => 'shipment-invoice',
                    'status' => 'failed',
                    'notifiable_type' => $order::class,
                    'notifiable_id' => $order->id,
                    'error' => json_encode([
                        'ok' => false,
                        'message' => $e->getMessage(),
                    ], JSON_UNESCAPED_UNICODE),
                ]);

                return $failed;
            } catch (Throwable) {
                $notification = new Notification();
                $notification->status = 'failed';
                $notification->error = $e->getMessage();
                $notification->recipient = (string) $order->customer_email;

                return $notification;
            }
        }
    }

    private function buildAndSendShipmentInvoice(ShopifyOrder $order, Shipment $shipment): Notification
    {
        $shipment->loadMissing(['cargoCompany', 'order']);
        $company = $shipment->cargoCompany;
        $statusText = StatusLabels::shipment($shipment->status);

        $rawStatus = mb_strtolower(trim($statusText), 'UTF-8');
        if (in_array($rawStatus, ['başarılı', 'basarili', 'success', 'ok', ''], true)) {
            $statusText = StatusLabels::shipment($shipment->status);
        }

        $companyName = $company?->name ?? 'Kargo';
        $trackingUrl = $this->httpsUrl((string) ($shipment->tracking_url ?? ''));
        $brand = $this->branding();

        $invoiceUrl = $this->invoiceUrlFor($order);
        $brandName = trim((string) ($brand['name'] ?? ''));
        $subject = ($brandName !== '' ? $brandName.' · ' : '').'Siparişiniz kargoya verildi - '.$order->order_number;

        return $this->dispatchMail(
            recipient: (string) $order->customer_email,
            subject: $subject,
            body: 'shipment-invoice',
            mailable: new ShipmentInvoiceMail($order, $shipment, [
                'subject' => $subject,
                'status_text' => $statusText,
                'tracking_url' => $trackingUrl,
                'invoice_url' => $invoiceUrl,
                'company_name' => $companyName,
                'brand' => $brand,
                'attach_invoice' => (string) (Setting::getValue('mail_attach_invoice', '0') === '1' ? '1' : '0'),
            ]),
            notifiable: $order
        );
    }

    public function sendInvoiceNotice(ShopifyOrder $order): Notification
    {
        $brand = $this->branding();
        $brandName = trim((string) ($brand['name'] ?? ''));
        $subject = ($brandName !== '' ? $brandName.' · ' : '').'Faturanız hazır - '.$order->order_number;

        return $this->dispatchMail(
            recipient: (string) $order->customer_email,
            subject: $subject,
            body: 'invoice-notice',
            mailable: new InvoiceNoticeMail($order, [
                'subject' => $subject,
                'invoice_url' => $this->invoiceUrlFor($order),
                'store_host' => $this->storeHost(),
                'brand' => $brand,
            ]),
            notifiable: $order
        );
    }

    public function sendCargoNotice(ShopifyOrder $order, Shipment $shipment): Notification
    {
        $shipment->loadMissing(['cargoCompany', 'order']);
        $brand = $this->branding();
        $brandName = trim((string) ($brand['name'] ?? ''));
        $subject = ($brandName !== '' ? $brandName.' · ' : '').'Siparişiniz kargoya verildi - '.$order->order_number;

        return $this->dispatchMail(
            recipient: (string) $order->customer_email,
            subject: $subject,
            body: 'cargo-notice',
            mailable: new CargoNoticeMail($order, $shipment, [
                'subject' => $subject,
                'status_text' => StatusLabels::shipment($shipment->status),
                'tracking_url' => $this->httpsUrl((string) ($shipment->tracking_url ?? '')),
                'company_name' => $shipment->cargoCompany?->name ?? 'Kargo',
                'store_host' => $this->storeHost(),
                'brand' => $brand,
            ]),
            notifiable: $order
        );
    }

    /**
     * Send a custom email (HTML şablon ile).
     */
    public function sendCustom(string $recipient, string $subject, string $body, mixed $notifiable = null): Notification
    {
        $templateKey = trim($body);
        if ($templateKey === 'shipment-invoice') {
            if ($notifiable instanceof ShopifyOrder) {
                $shipment = $notifiable->latestCargoShipment();
                if ($shipment) {
                    return $this->sendShipmentAndInvoiceNotification($notifiable, $shipment);
                }
            }

            return $this->failedTemplateResend(
                $recipient,
                $subject,
                $body,
                $notifiable,
                'Kargo + fatura maili için siparişte kargo kaydı gerekir.'
            );
        }

        if ($templateKey === 'invoice-notice') {
            if ($notifiable instanceof ShopifyOrder && $notifiable->hasInvoice()) {
                return $this->sendInvoiceNotice($notifiable);
            }

            return $this->failedTemplateResend(
                $recipient,
                $subject,
                $body,
                $notifiable,
                'Fatura bilgilendirme maili için siparişte fatura bağlantısı gerekir.'
            );
        }

        if ($templateKey === 'cargo-notice') {
            $order = $notifiable instanceof ShopifyOrder ? $notifiable : ($notifiable instanceof Shipment ? $notifiable->order : null);
            $shipment = $notifiable instanceof Shipment
                ? $notifiable
                : ($order instanceof ShopifyOrder ? $order->latestCargoShipment() : null);
            if ($order instanceof ShopifyOrder && $shipment) {
                return $this->sendCargoNotice($order, $shipment);
            }

            return $this->failedTemplateResend(
                $recipient,
                $subject,
                $body,
                $notifiable,
                'Kargo bilgilendirme maili için sipariş kargoya verilmiş olmalıdır.'
            );
        }

        return $this->dispatchMail(
            recipient: $recipient,
            subject: $subject,
            body: $body,
            mailable: new GenericMail([
                'subject' => $subject,
                'title' => $subject,
                'body' => $body,
            ]),
            notifiable: $notifiable,
        );
    }

    private function template(string $slug): ?MailTemplate
    {
        return MailTemplate::query()->where('slug', $slug)->where('is_active', true)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function orderData(?ShopifyOrder $order): array
    {
        if (! $order) {
            return [];
        }

        return [
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'order_number' => $order->order_number,
            'total_price' => number_format((float) $order->total_price, 2),
            'currency' => $order->currency ?: 'TL',
            'payment_status' => StatusLabels::payment($order->payment_status),
            'fulfillment_status' => StatusLabels::fulfillment($order->fulfillment_status),
            'status' => StatusLabels::fulfillment($order->fulfillment_status),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function sampleTemplateData(): array
    {
        return [
            'customer_name' => 'Ahmet Solmaz',
            'customer_email' => 'ahmet@example.com',
            'customer_phone' => '05551234567',
            'order_number' => '#23423434',
            'total_price' => '4.500,00',
            'currency' => 'TL',
            'payment_status' => 'Ödendi',
            'fulfillment_status' => 'Kargoya verildi',
            'status' => 'Kargoya verildi',
            'tracking_number' => '454221545',
            'tracking_url' => 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=454221545',
            'cargo_company' => 'Yurtiçi Kargo',
            'invoice_no' => 'ETF2026001',
            'invoice_url' => 'https://example.com/fatura/ornek',
            'return_cargo_name' => 'Yurtiçi Kargo',
            'return_cargo_code' => '216625941',
        ];
    }

    public function sendTemplatePreview(MailTemplate $template, string $recipient): Notification
    {
        $data = $this->sampleTemplateData();
        $subject = $this->replaceVariables((string) $template->subject, $data);
        $body = $this->replaceVariables((string) $template->body, $data);

        return $this->sendCustom($recipient, '[TEST] '.$subject, $body);
    }

    private function dispatchMail(
        string $recipient,
        string $subject,
        string $body,
        mixed $mailable,
        mixed $notifiable = null,
        bool $rawBody = false
    ): Notification {
        $recipient = $this->normalizeRecipient($recipient);

        $notification = Notification::query()->create([
            'type' => 'mail',
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'status' => 'pending',
            'notifiable_type' => $notifiable ? $notifiable::class : null,
            'notifiable_id' => $notifiable->id ?? null,
        ]);

        if ($recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            $notification->update([
                'status' => 'failed',
                'error' => $recipient === ''
                    ? 'Alıcı e-posta adresi boş.'
                    : 'Alıcı e-posta adresi geçersiz: '.$recipient,
            ]);

            return $notification;
        }

        try {
            $mailConfig = app(MailConfigService::class);
            $mailConfig->applyFromSettings();

            $fromAddress = $mailConfig->resolvedFromAddress() ?: (string) config('mail.from.address');
            $fromName = trim(str_replace(
                ["\r", "\n", "\0"],
                ' ',
                (string) (Setting::getValue('mail_from_name', config('mail.from.name')) ?: $this->brandName($mailConfig))
            ));
            $replyTo = trim((string) Setting::getValue('mail_from_address', ''));

            if ($fromAddress === '' || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
                $this->storeMailReport($notification, false, [
                    'message' => 'Geçerli bir From e-posta adresi yok. Ayarlar → Mail bölümünden From ve SMTP kullanıcı adını kontrol edin.',
                    'mailer' => (string) config('mail.default'),
                    'from' => $fromAddress,
                    'recipient' => $recipient,
                ]);

                return $notification;
            }

            $mailer = (string) config('mail.default');
            $host = (string) config('mail.mailers.smtp.host');
            $attachment = 'indirme linki';
            if (is_object($mailable) && method_exists($mailable, 'attachmentSummary')) {
                $attachment = $mailable->attachmentSummary();
            }

            $context = [
                'mailer' => $mailer,
                'host' => $host !== '' ? $host : '-',
                'from' => $fromAddress,
                'reply_to' => $replyTo,
                'recipient' => $recipient,
                'attachment' => $attachment,
            ];

            if ($mailer === 'log' && ! app()->runningUnitTests()) {
                $this->storeMailReport($notification, false, array_merge($context, [
                    'message' => 'Mailer "log". Gerçek e-posta gitmedi; kayıt yalnızca sunucu loguna yazılır. Ayarlar → Mail’den sendmail veya smtp seçip kaydedin.',
                ]));

                return $notification;
            }

            $wait = 0;
            if (method_exists($mailConfig, 'secondsUntilAllowed')) {
                $wait = (int) $mailConfig->secondsUntilAllowed();
            }
            if ($wait > 0) {
                $minutes = max(1, (int) ceil($wait / 60));
                $this->storeMailReport($notification, false, array_merge($context, [
                    'message' => 'Peş peşe gönderim toplu mail sayılır. '.$wait.' saniye (yaklaşık '.$minutes.' dk) sonra tekrar deneyin.',
                ]));

                return $notification;
            }

            if ($mailable) {
                $mailable->from($fromAddress, $fromName);
                if ($replyTo !== '' && strcasecmp($replyTo, $fromAddress) !== 0) {
                    $mailable->replyTo($replyTo, $fromName);
                }

                $mailable->withSymfonyMessage(function ($message) use ($fromAddress) {
                    $message->returnPath($fromAddress);
                    $headers = $message->getHeaders();
                    if (! $headers->has('List-Unsubscribe')) {
                        $headers->addTextHeader('List-Unsubscribe', '<mailto:'.$fromAddress.'>');
                    }
                });

                Mail::mailer($mailer)
                    ->to($recipient)
                    ->send($mailable);
            } else {
                Mail::mailer($mailer)->raw($body, function ($message) use ($recipient, $subject, $fromAddress, $fromName, $replyTo) {
                    $message->to($recipient)
                        ->subject($subject)
                        ->from($fromAddress, $fromName);

                    if ($replyTo !== '' && strcasecmp($replyTo, $fromAddress) !== 0) {
                        $message->replyTo($replyTo, $fromName);
                    }
                });
            }

            $warning = '';
            if ($mailer === 'smtp' && $mailConfig->isSharedSmtpHost($host)) {
                $recipientDomain = strtolower((string) substr((string) strrchr($recipient, '@'), 1));
                if ($recipientDomain !== '' && ! in_array($recipientDomain, ['gmail.com', 'googlemail.com', 'google.com'], true)) {
                    $warning = 'SMTP Gmail/Outlook. '.$recipient.' kurumsal kutusu maili spam veya quarantine klasörüne atabilir. Kurumsal teslimat için Mailer = sendmail veya kendi domain SMTP’sini kullanın.';
                }
            }

            $accepted = $mailer === 'sendmail'
                ? 'Sunucu sendmail ile maili kabul etti. Gelen kutusuna düşmesi alıcı sunucusuna bağlıdır.'
                : 'SMTP sunucusu maili kabul etti. Gelen kutusuna düşmesi alıcı sunucusuna bağlıdır.';

            if (method_exists($mailConfig, 'markOutboundSent')) {
                $mailConfig->markOutboundSent();
            }

            $this->storeMailReport($notification, true, array_merge($context, [
                'message' => $accepted,
                'warning' => $warning,
            ]));

            Log::channel('stack')->info('Mail sent', $context);
        } catch (Throwable $e) {
            $this->storeMailReport($notification, false, [
                'message' => $e->getMessage(),
                'mailer' => (string) config('mail.default'),
                'host' => (string) (config('mail.mailers.smtp.host') ?: '-'),
                'from' => (string) config('mail.from.address'),
                'recipient' => $recipient,
            ]);

            Log::channel('stack')->error('Mail send failed', [
                'recipient' => $recipient,
                'message' => $e->getMessage(),
            ]);
        }

        return $notification->fresh() ?? $notification;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function storeMailReport(Notification $notification, bool $ok, array $report): void
    {
        $payload = array_merge($report, ['ok' => $ok]);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $error = is_string($json) ? $json : (string) ($report['message'] ?? 'Mail raporu yazılamadı.');

        try {
            $notification->update([
                'status' => $ok ? 'sent' : 'failed',
                'sent_at' => $ok ? now() : $notification->sent_at,
                'error' => $error,
            ]);
        } catch (Throwable $e) {
            try {
                $notification->update([
                    'status' => $ok ? 'sent' : 'failed',
                    'error' => mb_substr((string) ($report['message'] ?? $e->getMessage()), 0, 500),
                ]);
            } catch (Throwable) {
                // Kayıt yazılamasa da gönderim akışı 500 olmasın.
            }
        }
    }

    /**
     * Shopify sometimes stores "Name <email@domain>" or trailing spaces.
     */
    private function normalizeRecipient(string $recipient): string
    {
        $recipient = trim($recipient);
        $recipient = preg_replace('/^mailto:/i', '', $recipient) ?? $recipient;
        $recipient = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $recipient) ?? $recipient;
        if (preg_match('/<([^>]+)>/', $recipient, $matches) === 1) {
            $recipient = trim($matches[1]);
        }

        $recipient = trim($recipient, " \t\n\r\0\x0B\"'");

        if (str_contains($recipient, '@')) {
            [$local, $domain] = explode('@', $recipient, 2);
            $domain = rtrim(strtolower(trim($domain)), '.');
            if (function_exists('idn_to_ascii')) {
                try {
                    $flags = defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0;
                    $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : null;
                    $ascii = $variant === null
                        ? idn_to_ascii($domain, $flags)
                        : idn_to_ascii($domain, $flags, $variant);
                    if (is_string($ascii) && $ascii !== '') {
                        $domain = $ascii;
                    }
                } catch (Throwable) {
                    // IDN dönüşümü yoksa domain olduğu gibi kullanılır.
                }
            }
            $recipient = trim($local).'@'.$domain;
        }

        return $recipient;
    }

    private function brandName(MailConfigService $mailConfig): string
    {
        if (method_exists($mailConfig, 'brandName')) {
            $name = trim((string) $mailConfig->brandName());
            if ($name !== '') {
                return $name;
            }
        }

        $name = trim((string) Setting::getValue('general_company_name', ''));
        if ($name === '') {
            $name = trim((string) Setting::getValue('general_app_name', ''));
        }
        if ($name === '') {
            $name = trim((string) config('app.name', ''));
        }

        return $name;
    }

    /**
     * @return array<string, mixed>
     */
    private function branding(): array
    {
        try {
            return app(MailConfigService::class)->branding();
        } catch (Throwable) {
            return [
                'name' => trim((string) Setting::getValue('general_company_name', Setting::getValue('general_app_name', config('app.name')))),
                'header_bg' => '#000000',
                'header_text' => '#ffffff',
                'body_text' => '#142433',
                'muted_text' => '#5b6b7c',
                'link' => '#c45c26',
                'button_bg' => '#000000',
                'button_text' => '#ffffff',
                'logo_url' => null,
                'site_url' => '',
                'account_url' => '',
            ];
        }
    }

    private function storeHost(): string
    {
        try {
            return app(MailConfigService::class)->storeHost();
        } catch (Throwable) {
            return "O'renne.com";
        }
    }

    private function invoiceUrlFor(ShopifyOrder $order): string
    {
        try {
            return $this->httpsUrl((string) ($order->invoiceUrl() ?? ''));
        } catch (Throwable $e) {
            Log::channel('stack')->warning('Invoice URL could not be built', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            return '';
        }
    }

    private function failedTemplateResend(
        string $recipient,
        string $subject,
        string $body,
        mixed $notifiable,
        string $message
    ): Notification {
        return Notification::query()->create([
            'type' => 'mail',
            'recipient' => $this->normalizeRecipient($recipient),
            'subject' => $subject,
            'body' => $body,
            'status' => 'failed',
            'notifiable_type' => $notifiable ? $notifiable::class : null,
            'notifiable_id' => $notifiable->id ?? null,
            'error' => json_encode([
                'ok' => false,
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function httpsUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (
            str_starts_with($url, 'http://')
            && ! str_contains($url, 'localhost')
            && ! str_contains($url, '127.0.0.1')
        ) {
            return 'https://'.substr($url, 7);
        }

        return $url;
    }
}
