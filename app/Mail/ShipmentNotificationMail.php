<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Shipment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ShipmentNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public Shipment $shipment,
        public array $payload = []
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) ($this->payload['subject'] ?? ('Kargonuz Yola Çıktı - '.$this->shipment->order_number)),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'email.shipment-notification',
            with: [
                'shipment' => $this->shipment,
                'body' => $this->payload['body'] ?? null,
                'customerName' => $this->shipment->receiver_name,
            ],
        );
    }
}
