<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncJobLog extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'sync_job_id',
        'status',
        'message',
        'synced_count',
        'error_count',
        'duration',
        'error',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'synced_count' => 'integer',
        'error_count' => 'integer',
        'duration' => 'decimal:2',
    ];

    /**
     * Parent sync job.
     */
    public function syncJob(): BelongsTo
    {
        return $this->belongsTo(SyncJob::class);
    }
}
