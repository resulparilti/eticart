<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Throwable;

class ActivityLogService
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        string $action,
        string $description,
        ?User $user = null,
        ?string $module = null,
        ?Request $request = null,
        ?Model $subject = null,
        array $properties = []
    ): ?ActivityLog {
        try {
            $request ??= request();
            $user ??= $request->user();
            $who = trim((string) ($user?->name ?: 'Sistem'));
            $when = now()->format('d.m.Y H:i:s');
            $body = trim($description);
            if ($body === '') {
                $body = 'işlem yaptı.';
            }
            if (! str_starts_with(mb_strtolower($body), mb_strtolower($who))) {
                $body = $who.' '.$body;
            }
            $body = rtrim($body, " \t.");
            if (! str_ends_with($body, $when)) {
                $body .= '. '.$when;
            }

            return ActivityLog::query()->create([
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'user_email' => $user?->email,
                'action' => $action,
                'module' => $module,
                'description' => $body,
                'route_name' => $request instanceof Request ? $request->route()?->getName() : null,
                'method' => $request instanceof Request ? $request->method() : null,
                'path' => $request instanceof Request ? '/'.$request->path() : null,
                'ip_address' => $request instanceof Request ? $request->ip() : null,
                'user_agent' => $request instanceof Request ? substr((string) $request->userAgent(), 0, 500) : null,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'properties' => $properties !== [] ? $properties : null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
