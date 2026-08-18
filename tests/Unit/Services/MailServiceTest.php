<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\MailService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_custom_marks_notification_sent(): void
    {
        Mail::fake();

        $notification = (new MailService())->sendCustom(
            'musteri@example.com',
            'Test Konu',
            'Test gövde'
        );

        $this->assertSame('sent', $notification->fresh()->status);
        $this->assertSame('mail', $notification->type);
        $this->assertSame('musteri@example.com', $notification->recipient);
    }

    public function test_display_name_recipient_is_normalized(): void
    {
        Mail::fake();

        $notification = (new MailService())->sendCustom(
            'Resul Parilti <resul.parilti@firma.com.tr>',
            'Test Konu',
            'Test gövde'
        );

        $this->assertSame('sent', $notification->fresh()->status);
        $this->assertSame('resul.parilti@firma.com.tr', $notification->recipient);
    }

    public function test_sms_log_mode_marks_sent(): void
    {
        config(['services.sms.provider' => 'log']);

        $notification = (new SmsService())->send('05551234567', 'Test SMS');

        $this->assertSame('sent', $notification->fresh()->status);
        $this->assertSame('sms', $notification->type);
    }
}
