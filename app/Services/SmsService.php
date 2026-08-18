<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Notification;
use App\Models\Setting;
use App\Models\SmsTemplate;
use App\Support\ReplacesTemplateVariables;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SmsService
{
    use ReplacesTemplateVariables;

    private string $provider;

    private string $apiKey;

    private string $apiSecret;

    private string $header;

    public function __construct()
    {
        $this->provider = (string) (Setting::getValue('sms_provider') ?: config('services.sms.provider') ?: 'log');
        $this->apiKey = (string) (Setting::getValue('sms_api_key') ?: config('services.sms.api_key') ?: '');
        $this->apiSecret = (string) (Setting::getValue('sms_api_secret') ?: config('services.sms.api_secret') ?: '');
        $this->header = (string) (Setting::getValue('sms_header') ?: config('services.sms.header') ?: 'ETICART');
    }

    /**
     * Whether a real SMS provider is configured.
     */
    public function isConfigured(): bool
    {
        return $this->provider !== 'log' && $this->apiKey !== '';
    }

    /**
     * Send a single SMS.
     */
    public function send(string $phone, string $message, mixed $notifiable = null): Notification
    {
        $phone = $this->normalizePhone($phone);

        $notification = Notification::query()->create([
            'type' => 'sms',
            'recipient' => $phone,
            'subject' => null,
            'body' => $message,
            'status' => 'pending',
            'notifiable_type' => $notifiable ? $notifiable::class : null,
            'notifiable_id' => $notifiable->id ?? null,
        ]);

        if ($phone === '') {
            $notification->update([
                'status' => 'failed',
                'error' => 'Telefon numarası boş.',
            ]);

            return $notification;
        }

        try {
            if (! $this->isConfigured() || $this->provider === 'log') {
                Log::channel('stack')->info('SMS local/log send', [
                    'phone' => $phone,
                    'header' => $this->header,
                    'message' => $message,
                ]);
            } else {
                $this->sendViaProvider($phone, $message);
            }

            $notification->update([
                'status' => 'sent',
                'sent_at' => now(),
                'error' => null,
            ]);
        } catch (Throwable $e) {
            $notification->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            Log::channel('stack')->error('SMS send failed', [
                'phone' => $phone,
                'message' => $e->getMessage(),
            ]);
        }

        return $notification->fresh();
    }

    /**
     * Send SMS to multiple phones.
     *
     * @param  array<int, string>  $phones
     * @return array<int, Notification>
     */
    public function sendBulk(array $phones, string $message): array
    {
        $results = [];

        foreach ($phones as $phone) {
            $results[] = $this->send((string) $phone, $message);
        }

        return $results;
    }

    /**
     * Send SMS using a template slug.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendFromTemplate(string $phone, string $templateSlug, array $data = [], mixed $notifiable = null): Notification
    {
        $template = SmsTemplate::query()->where('slug', $templateSlug)->where('is_active', true)->first();
        $body = $template?->body ?? 'Bildirim: {{order_number}}';
        $message = $this->replaceVariables($body, $data);

        return $this->send($phone, $message, $notifiable);
    }

    /**
     * @return array<string, string>
     */
    public function sampleTemplateData(): array
    {
        return [
            'customer_name' => 'Ahmet Solmaz',
            'order_number' => '#23423434',
            'total_price' => '4500',
            'tracking_number' => '454221545',
            'tracking_url' => 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=454221545',
            'cargo_company' => 'Yurtiçi Kargo',
            'status' => 'Kargoya verildi',
        ];
    }

    public function sendTemplatePreview(SmsTemplate $template, string $phone): Notification
    {
        $message = $this->replaceVariables((string) $template->body, $this->sampleTemplateData());

        return $this->send($phone, '[TEST] '.$message);
    }

    /**
     * Get provider balance when available.
     *
     * @return array<string, mixed>
     */
    public function getBalance(): array
    {
        if (! $this->isConfigured()) {
            return [
                'provider' => $this->provider,
                'balance' => null,
                'mode' => 'local',
                'message' => 'SMS local/log modunda. Bakiye sorgusu yok.',
            ];
        }

        if ($this->provider === 'netgsm') {
            $response = Http::asForm()->timeout(20)->post('https://api.netgsm.com.tr/balance', [
                'usercode' => $this->apiKey,
                'password' => $this->apiSecret,
            ]);

            return [
                'provider' => 'netgsm',
                'balance' => $response->body(),
                'mode' => 'api',
            ];
        }

        return [
            'provider' => $this->provider,
            'balance' => null,
            'mode' => 'unsupported',
        ];
    }

    private function sendViaProvider(string $phone, string $message): void
    {
        if ($this->provider === 'netgsm') {
            $response = Http::asForm()->timeout(30)->post('https://api.netgsm.com.tr/sms/send/get', [
                'usercode' => $this->apiKey,
                'password' => $this->apiSecret,
                'gsmno' => $phone,
                'message' => $message,
                'msgheader' => $this->header,
            ]);

            if ($response->failed()) {
                throw new \RuntimeException('Netgsm SMS gönderimi başarısız: '.$response->body());
            }

            return;
        }

        // Generic fallback endpoint from settings.
        $endpoint = (string) Setting::getValue('sms_endpoint', '');

        if ($endpoint === '') {
            throw new \RuntimeException("SMS provider '{$this->provider}' için endpoint tanımlı değil.");
        }

        $response = Http::withToken($this->apiKey)->timeout(30)->post($endpoint, [
            'to' => $phone,
            'message' => $message,
            'header' => $this->header,
            'api_secret' => $this->apiSecret,
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('SMS API hatası: '.$response->body());
        }
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($phone, '0') && strlen($phone) === 11) {
            $phone = '90'.substr($phone, 1);
        }

        return $phone;
    }
}
