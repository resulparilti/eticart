<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GenericMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array{subject?: string, body?: string, title?: string}  $payload
     */
    public function __construct(
        public array $payload = []
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) ($this->payload['subject'] ?? (trim((string) \App\Models\Setting::getValue('general_company_name', \App\Models\Setting::getValue('general_app_name', config('app.name')))).' Bildirim')),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'email.generic',
            with: [
                'title' => $this->payload['title'] ?? ($this->payload['subject'] ?? 'Bildirim'),
                'body' => $this->payload['body'] ?? '',
            ],
        );
    }
}
