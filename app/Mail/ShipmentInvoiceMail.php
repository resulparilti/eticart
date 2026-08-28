<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Shipment;
use App\Models\ShopifyOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ShipmentInvoiceMail extends Mailable
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
            view: 'email.shipment-invoice',
            text: 'email.shipment-invoice-text',
            with: [
                'order' => $this->order,
                'shipment' => $this->shipment,
                'customerName' => $this->order->customer_name ?: $this->shipment->receiver_name,
                'statusText' => (string) ($this->payload['status_text'] ?? ''),
                'trackingUrl' => $this->httpsUrl((string) ($this->payload['tracking_url'] ?? $this->shipment->tracking_url ?? '')),
                'invoiceUrl' => $this->httpsUrl((string) ($this->payload['invoice_url'] ?? '')),
                'companyName' => (string) ($this->payload['company_name'] ?? 'Kargo'),
                'brand' => is_array($this->payload['brand'] ?? null) ? $this->payload['brand'] : [
                    'name' => '',
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
                ],
                'hasAttachment' => $this->shouldAttachInvoice(),
                'noticeRef' => (string) ($this->payload['notice_ref'] ?? ''),
                'noticeAt' => (string) ($this->payload['notice_at'] ?? ''),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (! $this->shouldAttachInvoice()) {
            return [];
        }

        $path = (string) $this->order->invoice_path;

        $name = $this->order->invoiceAttachmentName();
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) ?: 'pdf';
        $base = pathinfo($name, PATHINFO_FILENAME);
        $uniqueName = $base.'-'.now()->format('His').'.'.$ext;

        return [
            Attachment::fromStorageDisk('public', $path)
                ->as($uniqueName)
                ->withMime($this->order->invoiceMimeType()),
        ];
    }

    public function attachmentSummary(): string
    {
        $parts = [];
        if ($this->shouldAttachInvoice()) {
            $parts[] = 'fatura eki';
        }
        if (trim((string) ($this->payload['invoice_url'] ?? '')) !== '') {
            $parts[] = 'indirme linki';
        }

        return $parts !== [] ? implode(' + ', $parts) : 'yok';
    }

    /**
     * cPanel çıkış taraması PDF ekini 550 5.7.1 ile keser.
     * Varsayılan kapalı; hosting izin verirse ayarlardan açılır.
     */
    private function shouldAttachInvoice(): bool
    {
        $enabled = (string) ($this->payload['attach_invoice'] ?? '0') === '1';
        if (! $enabled) {
            return false;
        }

        return method_exists($this->order, 'invoiceIsAttachable')
            && $this->order->invoiceIsAttachable();
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
