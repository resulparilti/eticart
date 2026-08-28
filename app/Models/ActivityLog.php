<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    public $timestamps = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'user_name',
        'user_email',
        'action',
        'module',
        'description',
        'route_name',
        'method',
        'path',
        'ip_address',
        'user_agent',
        'subject_type',
        'subject_id',
        'properties',
        'created_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'properties' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function actorLabel(): string
    {
        $name = trim((string) ($this->user_name ?: $this->user?->name ?: 'Sistem'));
        if ($this->user_id && $this->user?->trashed()) {
            return $name.' (silindi)';
        }
        if ($this->user_id === null && filled($this->user_name)) {
            return $name.' (silindi)';
        }

        return $name;
    }

    public function summary(): string
    {
        return trim($this->actorLabel().' '.$this->description);
    }
}
