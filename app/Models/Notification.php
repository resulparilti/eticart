<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Notification extends Model
{
    /**
     * Custom outbound mail/SMS log table (avoids Laravel DB notification conflict).
     *
     * @var string
     */
    protected $table = 'message_notifications';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'type',
        'recipient',
        'subject',
        'body',
        'status',
        'notifiable_type',
        'notifiable_id',
        'sent_at',
        'error',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sent_at' => 'datetime',
    ];

    /**
     * Related entity (order, shipment, etc.).
     */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * SMTP / hata raporu (JSON veya düz metin).
     *
     * @return array{
     *     ok: bool,
     *     message: string,
     *     mailer?: string,
     *     host?: string,
     *     from?: string,
     *     reply_to?: string,
     *     recipient?: string,
     *     attachment?: string,
     *     warning?: string
     * }
     */
    public function mailReport(): array
    {
        $raw = trim((string) $this->error);
        if ($raw !== '' && str_starts_with($raw, '{')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_merge([
                    'ok' => $this->status === 'sent',
                    'message' => '',
                ], $decoded);
            }
        }

        return [
            'ok' => $this->status === 'sent',
            'message' => $raw,
        ];
    }

    public function reportMessage(): string
    {
        $report = $this->mailReport();
        $message = trim((string) ($report['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        return $this->status === 'failed'
            ? 'Mail gönderilemedi.'
            : 'SMTP sunucusu maili kabul etti.';
    }

    public function statusLabel(): string
    {
        return match ((string) $this->status) {
            'sent' => 'SMTP teslim',
            'failed' => 'Başarısız',
            'pending' => 'Beklemede',
            default => (string) $this->status,
        };
    }
}
