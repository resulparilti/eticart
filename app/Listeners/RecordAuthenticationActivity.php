<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\ActivityLogService;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

class RecordAuthenticationActivity
{
    public function __construct(
        private readonly ActivityLogService $logs
    ) {
    }

    public function handleLogin(Login $event): void
    {
        $user = $event->user;
        if (! $user instanceof \App\Models\User) {
            return;
        }

        $this->logs->record(
            'login',
            'sisteme giriş yaptı.',
            $user,
            'users'
        );
    }

    public function handleLogout(Logout $event): void
    {
        $user = $event->user;
        if (! $user instanceof \App\Models\User) {
            return;
        }

        $this->logs->record(
            'logout',
            'sistemden çıkış yaptı.',
            $user,
            'users'
        );
    }
}
