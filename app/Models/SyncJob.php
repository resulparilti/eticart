<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncJob extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'job_type',
        'status',
        'interval_minutes',
        'is_active',
        'last_run',
        'next_run',
        'last_error',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'interval_minutes' => 'integer',
        'is_active' => 'boolean',
        'last_run' => 'datetime',
        'next_run' => 'datetime',
    ];

    /**
     * Job execution logs.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(SyncJobLog::class);
    }
}
