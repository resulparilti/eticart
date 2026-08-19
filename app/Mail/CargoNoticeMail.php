<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Shipment;
use App\Models\ShopifyOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Throwable;

class CargoNoticeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public ShopifyOrder $order,
        public Shipment $shipment,
        public array $payload = []
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) ($this->payload['subject'] ?? ('Siparişiniz kargoya verildi - '.$this->order->order_number)),
        );
    }

    public function headers(): Headers
    {
        try {
            $from = (string) (config('mail.from.address') ?: '');
            $headers = [];
            if (filter_var($from, FILTER_VALIDATE_EMAIL)) {
                $headers['List-Unsubscribe'] = '<mailto:'.$from.'>';
            }

            return new Headers(text: $headers);
        } catch (Throwable) {
            return new Headers();
        }
    }

    public function content(): Content
    {
        return new Content(
            view: 'email.cargo-notice',
            text: 'email.cargo-notice-text',
            with: [
                'order' => $this->order,
                'shipment' => $this->shipment,
                'customerName' => $this->order->customer_name ?: $this->shipment->receiver_name ?: 'Müşterimiz',
                'statusText' => (string) ($this->payload['status_text'] ?? ''),
                'trackingUrl' => $this->httpsUrl((string) ($this->payload['tracking_url'] ?? $this->shipment->tracking_url ?? '')),
                'companyName' => (string) ($this->payload['company_name'] ?? 'Kargo'),
                'storeHost' => (string) ($this->payload['store_host'] ?? "O'renne.com"),
                'brand' => $this->brand(),
            ],
        );
    }

    public function attachmentSummary(): string
    {
        return 'kargo takip bilgisi';
    }

    /**
     * @return array<string, mixed>
     */
    private function brand(): array
    {
        return is_array($this->payload['brand'] ?? null) ? $this->payload['brand'] : [
            'name' => '',
            'header_bg' => '#0f2a3d',
            'header_text' => '#ffffff',
            'body_text' => '#142433',
            'muted_text' => '#5b6b7c',
            'link' => '#c45c26',
            'button_bg' => '#0f2a3d',
            'button_text' => '#ffffff',
            'logo_url' => null,
            'site_url' => '',
            'account_url' => '',
        ];
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
