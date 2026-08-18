<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\ShopifyOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderConfirmationMail extends Mailable
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
            subject: (string) ($this->payload['subject'] ?? ('Siparişiniz Alındı - '.$this->order->order_number)),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'email.order-confirmation',
            with: [
                'order' => $this->order,
                'body' => $this->payload['body'] ?? null,
                'customerName' => $this->order->customer_name,
            ],
        );
    }
}
