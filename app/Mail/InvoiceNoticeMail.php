<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\ShopifyOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Throwable;

class InvoiceNoticeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public ShopifyOrder $order,
        public array $payload = []
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) ($this->payload['subject'] ?? ('Faturanız hazır - '.$this->order->order_number)),
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
            view: 'email.invoice-notice',
            text: 'email.invoice-notice-text',
            with: [
                'order' => $this->order,
                'customerName' => $this->order->customer_name ?: 'Müşterimiz',
                'invoiceUrl' => $this->httpsUrl((string) ($this->payload['invoice_url'] ?? '')),
                'storeHost' => (string) ($this->payload['store_host'] ?? "O'renne.com"),
                'brand' => $this->brand(),
            ],
        );
    }

    public function attachmentSummary(): string
    {
        return trim((string) ($this->payload['invoice_url'] ?? '')) !== ''
            ? 'indirme linki'
            : 'yok';
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
