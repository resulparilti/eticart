<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\LogRetentionService;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;
use Throwable;

class PruneLogsOnLogin
{
    public function __construct(
        private readonly LogRetentionService $retention
    ) {
    }

    public function handle(Login $event): void
    {
        try {
            $this->retention->pruneOnLogin();
        } catch (Throwable $e) {
            try {
                Log::warning('Login log retention failed', [
                    'message' => $e->getMessage(),
                ]);
            } catch (Throwable) {
            }
        }
    }
}
