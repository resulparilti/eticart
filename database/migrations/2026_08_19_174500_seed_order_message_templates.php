<?php

declare(strict_types=1);

use App\Models\MailTemplate;
use App\Models\SmsTemplate;
use App\Support\OrderMessageTemplates;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (OrderMessageTemplates::definitions() as $definition) {
            MailTemplate::query()->firstOrCreate(
                ['slug' => $definition['mail_slug']],
                [
                    'name' => $definition['label'],
                    'subject' => $definition['mail_subject'],
                    'body' => $definition['mail_body'],
                    'is_active' => true,
                ]
            );

            SmsTemplate::query()->firstOrCreate(
                ['slug' => $definition['sms_slug']],
                [
                    'name' => $definition['label'],
                    'body' => $definition['sms_body'],
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Kullanıcı düzenlemeleri korunur.
    }
};
