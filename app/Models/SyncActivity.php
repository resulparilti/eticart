<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SyncActivity extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'uuid',
        'user_id',
        'type',
        'title',
        'status',
        'progress_current',
        'progress_total',
        'message',
        'meta',
        'started_at',
        'finished_at',
        'dismissed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'progress_current' => 'integer',
        'progress_total' => 'integer',
        'meta' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $activity): void {
            if (blank($activity->uuid)) {
                $activity->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SyncActivityLog::class)->orderBy('id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    public function isDismissed(): bool
    {
        return $this->dismissed_at !== null;
    }

    public function dismiss(): void
    {
        if ($this->isActive()) {
            return;
        }

        $this->update(['dismissed_at' => now()]);
    }

    public function progressPercent(): ?int
    {
        if ($this->progress_total === null || $this->progress_total <= 0) {
            return null;
        }

        return (int) min(100, round(($this->progress_current / $this->progress_total) * 100));
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_QUEUED => 'Bekliyor',
            self::STATUS_RUNNING => 'Çalışıyor',
            self::STATUS_COMPLETED => 'Tamam',
            self::STATUS_PARTIAL => 'Kısmi',
            self::STATUS_FAILED => 'Hata',
            default => $this->status,
        };
    }

    public function statusBadgeType(): string
    {
        return match ($this->status) {
            self::STATUS_QUEUED => 'secondary',
            self::STATUS_RUNNING => 'info',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_PARTIAL => 'warning',
            self::STATUS_FAILED => 'danger',
            default => 'secondary',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toMonitorArray(bool $withLogs = false): array
    {
        $data = [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'progress_current' => $this->progress_current,
            'progress_total' => $this->progress_total,
            'progress_percent' => $this->progressPercent(),
            'message' => $this->message,
            'meta' => $this->meta ?? [],
            'started_at' => optional($this->started_at)->toIso8601String(),
            'finished_at' => optional($this->finished_at)->toIso8601String(),
            'dismissed_at' => optional($this->dismissed_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'is_active' => $this->isActive(),
            'can_dismiss' => ! $this->isActive() && ! $this->isDismissed(),
        ];

        if ($withLogs) {
            $data['logs'] = $this->logs->map(static fn (SyncActivityLog $log): array => [
                'id' => $log->id,
                'level' => $log->level,
                'message' => $log->message,
                'context' => $log->context ?? [],
                'created_at' => optional($log->created_at)->toIso8601String(),
            ])->values()->all();
        }

        return $data;
    }
}
