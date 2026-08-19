<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MailTemplate;
use App\Models\ShopifyOrder;
use App\Models\SmsTemplate;
use InvalidArgumentException;

final class OrderMessageTemplates
{
    public const ORDER_CONFIRMATION = 'order-confirmation';

    public const ORDER_SHIPPED = 'order-shipped';

    public const ORDER_INVOICE = 'order-invoice';

    public const ORDER_DELIVERED = 'order-delivered';

    public const ORDER_RETURN = 'order-return-exchange';

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return array<string, array{
     *     label: string,
     *     mail_slug: string,
     *     sms_slug: string,
     *     mail_subject: string,
     *     mail_body: string,
     *     sms_body: string
     * }>
     */
    public static function definitions(): array
    {
        return [
            self::ORDER_CONFIRMATION => [
                'label' => 'Sipariş onay',
                'mail_slug' => 'order-confirmation',
                'sms_slug' => 'order-confirmation-sms',
                'mail_subject' => 'Siparişiniz alındı - {{order_number}}',
                'mail_body' => '<p>Merhaba <strong>{{customer_name}}</strong>,</p><p><strong>{{order_number}}</strong> numaralı siparişiniz alındı. Toplam tutar: <strong>{{total_price}} {{currency}}</strong>.</p><p>Siparişiniz hazırlanmaya başladığında sizi ayrıca bilgilendireceğiz.</p>',
                'sms_body' => 'Sayın {{customer_name}}, {{order_number}} numaralı siparişiniz alındı. Tutar: {{total_price}} {{currency}}.',
            ],
            self::ORDER_SHIPPED => [
                'label' => 'Sipariş kargoya verildi',
                'mail_slug' => 'shipment-notification',
                'sms_slug' => 'shipment-sms',
                'mail_subject' => 'Siparişiniz kargoya verildi - {{order_number}}',
                'mail_body' => '<p>Merhaba <strong>{{customer_name}}</strong>,</p><p><strong>{{order_number}}</strong> numaralı siparişiniz <strong>{{cargo_company}}</strong> ile kargoya verildi.</p><p>Takip numarası: <strong>{{tracking_number}}</strong></p><p><a href="{{tracking_url}}">Kargonuzu buradan takip edebilirsiniz</a></p>',
                'sms_body' => 'Sayın {{customer_name}}, {{order_number}} kargoya verildi. Takip: {{tracking_number}} {{tracking_url}}',
            ],
            self::ORDER_INVOICE => [
                'label' => 'Sipariş faturası yüklendi',
                'mail_slug' => 'order-invoice',
                'sms_slug' => 'order-invoice-sms',
                'mail_subject' => 'Faturanız hazır - {{order_number}}',
                'mail_body' => '<p>Merhaba <strong>{{customer_name}}</strong>,</p><p><strong>{{order_number}}</strong> numaralı siparişinizin faturası hazır.</p><p>Fatura no: <strong>{{invoice_no}}</strong></p><p><a href="{{invoice_url}}">Faturayı indir</a></p>',
                'sms_body' => 'Sayın {{customer_name}}, {{order_number}} faturanız hazır. Fatura no: {{invoice_no}} İndir: {{invoice_url}}',
            ],
            self::ORDER_DELIVERED => [
                'label' => 'Sipariş teslim edildi',
                'mail_slug' => 'order-delivered',
                'sms_slug' => 'order-delivered-sms',
                'mail_subject' => 'Siparişiniz teslim edildi - {{order_number}}',
                'mail_body' => '<p>Merhaba <strong>{{customer_name}}</strong>,</p><p><strong>{{order_number}}</strong> numaralı siparişiniz teslim edildi. Bizi tercih ettiğiniz için teşekkür ederiz.</p>',
                'sms_body' => 'Sayın {{customer_name}}, {{order_number}} numaralı siparişiniz teslim edildi. Teşekkür ederiz.',
            ],
            self::ORDER_RETURN => [
                'label' => 'İade ve değişim bilgilendirmesi',
                'mail_slug' => 'order-return-exchange',
                'sms_slug' => 'order-return-exchange-sms',
                'mail_subject' => 'İade / değişim bilgisi - {{order_number}}',
                'mail_body' => '<p>Merhaba <strong>{{customer_name}}</strong>,</p><p><strong>{{order_number}}</strong> numaralı siparişiniz için iade veya değişim talebinizi aldık.</p><p>İade kargo: <strong>{{return_cargo_name}}</strong><br>İade kodu: <strong>{{return_cargo_code}}</strong></p><p>Ürünü kargo şubesine teslim ederken bu kodu kullanabilirsiniz.</p>',
                'sms_body' => 'Sayın {{customer_name}}, {{order_number}} iade/değişim: {{return_cargo_name}} kod {{return_cargo_code}}',
            ],
        ];
    }

    public static function suggestedKey(ShopifyOrder $order): string
    {
        $status = strtolower(trim((string) $order->fulfillment_status));

        if ($status === 'delivered') {
            return self::ORDER_DELIVERED;
        }

        if ($status === 'fulfilled' || $order->latestCargoShipment() !== null) {
            return self::ORDER_SHIPPED;
        }

        if ($order->hasInvoice()) {
            return self::ORDER_INVOICE;
        }

        return self::ORDER_CONFIRMATION;
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::definitions() as $key => $definition) {
            $options[] = [
                'key' => $key,
                'label' => $definition['label'],
            ];
        }

        return $options;
    }

    public static function label(string $key): string
    {
        return self::definition($key)['label'];
    }

    public static function slug(string $key, string $channel): string
    {
        $definition = self::definition($key);

        return $channel === 'sms' ? $definition['sms_slug'] : $definition['mail_slug'];
    }

    public static function assertKey(string $key): void
    {
        self::definition($key);
    }

    /**
     * @return array{
     *     label: string,
     *     mail_slug: string,
     *     sms_slug: string,
     *     mail_subject: string,
     *     mail_body: string,
     *     sms_body: string
     * }
     */
    public static function definition(string $key): array
    {
        $definitions = self::definitions();
        if (! isset($definitions[$key])) {
            throw new InvalidArgumentException('Bilinmeyen şablon: '.$key);
        }

        return $definitions[$key];
    }

    /**
     * Ayarlar → Mail/SMS şablonları listesine ön tanımlı kayıtları yazar (yoksa ekler).
     */
    public static function syncToDatabase(): void
    {
        foreach (self::definitions() as $definition) {
            self::firstOrFillMail(
                $definition['mail_slug'],
                $definition['label'],
                $definition['mail_subject'],
                $definition['mail_body']
            );
            self::firstOrFillSms(
                $definition['sms_slug'],
                $definition['label'],
                $definition['sms_body']
            );
        }

        foreach (self::extraMailTemplates() as $slug => $template) {
            self::firstOrFillMail(
                $slug,
                $template['name'],
                $template['subject'],
                $template['body']
            );
        }
    }

    private static function firstOrFillMail(string $slug, string $name, string $subject, string $body): void
    {
        $template = MailTemplate::query()->firstOrNew(['slug' => $slug]);
        if ($template->exists && trim((string) $template->body) !== '') {
            return;
        }

        $template->fill([
            'name' => $name,
            'subject' => $subject,
            'body' => $body,
            'is_active' => true,
        ])->save();
    }

    private static function firstOrFillSms(string $slug, string $name, string $body): void
    {
        $template = SmsTemplate::query()->firstOrNew(['slug' => $slug]);
        if ($template->exists && trim((string) $template->body) !== '') {
            return;
        }

        $template->fill([
            'name' => $name,
            'body' => $body,
            'is_active' => true,
        ])->save();
    }

    /**
     * @return array<int, string>
     */
    public static function predefinedMailSlugs(): array
    {
        $slugs = [];
        foreach (self::definitions() as $definition) {
            $slugs[] = $definition['mail_slug'];
        }

        return array_merge($slugs, array_keys(self::extraMailTemplates()));
    }

    /**
     * @return array<int, string>
     */
    public static function predefinedSmsSlugs(): array
    {
        $slugs = [];
        foreach (self::definitions() as $definition) {
            $slugs[] = $definition['sms_slug'];
        }

        return $slugs;
    }

    /**
     * @return array<string, array{name: string, subject: string, body: string, is_active: bool}>
     */
    public static function extraMailTemplates(): array
    {
        return [
            'order-status-update' => [
                'name' => 'Sipariş durum güncellemesi',
                'subject' => 'Sipariş durumu güncellendi - {{order_number}}',
                'body' => '<p>Merhaba <strong>{{customer_name}}</strong>,</p><p><strong>{{order_number}}</strong> numaralı siparişinizin yeni durumu: <strong>{{status}}</strong>.</p>',
                'is_active' => true,
            ],
        ];
    }
}
