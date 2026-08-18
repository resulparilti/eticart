<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPassword extends ResetPasswordNotification
{
    /**
     * Build the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        if (static::$toMailCallback) {
            return call_user_func(static::$toMailCallback, $notifiable, $this->token);
        }

        $expire = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
        $appName = (string) \App\Models\Setting::appName();

        return (new MailMessage)
            ->subject("{$appName} — Şifre sıfırlama")
            ->greeting('Merhaba')
            ->line('Hesabınız için bir şifre sıfırlama isteği aldık.')
            ->action('Şifreyi Sıfırla', $this->resetUrl($notifiable))
            ->line("Bu bağlantı {$expire} dakika içinde geçerliliğini yitirir.")
            ->line('Şifre sıfırlama talebinde bulunmadıysanız bu e-postayı yok sayabilirsiniz.')
            ->salutation("Saygılarımızla,\n{$appName}");
    }
}
