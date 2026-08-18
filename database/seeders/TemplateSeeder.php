<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MailTemplate;
use App\Models\SmsTemplate;
use App\Models\SyncJob;
use Illuminate\Database\Seeder;

class TemplateSeeder extends Seeder
{
    /**
     * Seed mail/SMS templates and sync jobs.
     */
    public function run(): void
    {
        $mailTemplates = [
            [
                'name' => 'Sipariş Onayı',
                'slug' => 'order-confirmation',
                'subject' => 'Siparişiniz Alındı - {{order_number}}',
                'body' => '<p>Merhaba <strong>{{customer_name}}</strong>,</p><p><strong>{{order_number}}</strong> numaralı siparişiniz alındı. Toplam tutar: <strong>{{total_price}} {{currency}}</strong>.</p><p>Siparişiniz hazırlanmaya başladığında sizi ayrıca bilgilendireceğiz.</p>',
            ],
            [
                'name' => 'Kargo Bildirimi',
                'slug' => 'shipment-notification',
                'subject' => 'Siparişiniz Kargoya Verildi - {{order_number}}',
                'body' => '<p>Merhaba <strong>{{customer_name}}</strong>,</p><p><strong>{{total_price}} {{currency}}</strong> tutarındaki <strong>{{order_number}}</strong> numaralı siparişiniz <strong>{{cargo_company}}</strong> ile kargoya verildi.</p><p>Takip numarası: <strong>{{tracking_number}}</strong></p><p><a href="{{tracking_url}}">Kargonuzu buradan takip edebilirsiniz</a></p>',
            ],
            [
                'name' => 'Sipariş Durum Güncellemesi',
                'slug' => 'order-status-update',
                'subject' => 'Sipariş Durumu Güncellendi - {{order_number}}',
                'body' => '<p>Merhaba <strong>{{customer_name}}</strong>,</p><p><strong>{{order_number}}</strong> numaralı siparişinizin yeni durumu: <strong>{{status}}</strong>.</p>',
            ],
        ];

        foreach ($mailTemplates as $template) {
            MailTemplate::query()->updateOrCreate(
                ['slug' => $template['slug']],
                array_merge($template, ['is_active' => true])
            );
        }

        $smsTemplates = [
            [
                'name' => 'Sipariş Onayı SMS',
                'slug' => 'order-confirmation-sms',
                'body' => 'Sayın {{customer_name}}, {{total_price}} TL tutarındaki {{order_number}} numaralı siparişiniz alındı.',
            ],
            [
                'name' => 'Kargo SMS',
                'slug' => 'shipment-sms',
                'body' => 'Sayın {{customer_name}}, {{total_price}} TL tutarındaki {{order_number}} numaralı siparişiniz kargoya verildi. {{tracking_number}} takip numaralı kargonuzu {{tracking_url}} adresinden takip edebilirsiniz.',
            ],
        ];

        foreach ($smsTemplates as $template) {
            SmsTemplate::query()->updateOrCreate(
                ['slug' => $template['slug']],
                array_merge($template, ['is_active' => true])
            );
        }

        $syncJobs = [
            ['job_type' => 'order_sync', 'interval_minutes' => 5],
            ['job_type' => 'product_sync', 'interval_minutes' => 15],
            ['job_type' => 'stock_sync', 'interval_minutes' => 5],
            ['job_type' => 'cargo_tracking', 'interval_minutes' => 15],
            ['job_type' => 'uyumsoft_order_sync', 'interval_minutes' => 5],
        ];

        foreach ($syncJobs as $job) {
            SyncJob::query()->updateOrCreate(
                ['job_type' => $job['job_type']],
                [
                    'interval_minutes' => $job['interval_minutes'],
                    'status' => 'idle',
                    'is_active' => true,
                ]
            );
        }
    }
}
