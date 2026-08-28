<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SyncActivityCancelledException;
use App\Models\SyncActivity;
use App\Models\SyncActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SyncActivityTracker
{
    private ?SyncActivity $current = null;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function start(string $type, string $title, ?int $total = null, array $meta = [], ?int $userId = null): SyncActivity
    {
        $activity = SyncActivity::query()->create([
            'user_id' => $userId ?? Auth::id(),
            'type' => $type,
            'title' => $title,
            'status' => SyncActivity::STATUS_QUEUED,
            'progress_current' => 0,
            'progress_total' => $total,
            'message' => 'İşlem kuyruğa alındı…',
            'meta' => $meta,
        ]);

        $this->bind($activity);
        $this->log('info', 'İşlem oluşturuldu.');

        return $activity;
    }

    public function bind(?SyncActivity $activity): void
    {
        $this->current = $activity;
    }

    public function current(): ?SyncActivity
    {
        return $this->current;
    }

    public function markRunning(?string $message = null): void
    {
        if (! $this->current) {
            return;
        }

        $this->abortIfCancelled();

        $this->current->update([
            'status' => SyncActivity::STATUS_RUNNING,
            'started_at' => $this->current->started_at ?? now(),
            'message' => $message ?? 'İşlem çalışıyor…',
        ]);

        $this->log('info', $message ?? 'İşlem başladı.');
    }

    public function setTotal(?int $total): void
    {
        if (! $this->current || $total === null) {
            return;
        }

        $this->current->update(['progress_total' => max(0, $total)]);
    }

    public function progress(int $current, ?int $total = null, ?string $message = null): void
    {
        if (! $this->current) {
            return;
        }

        $this->abortIfCancelled();

        $payload = [
            'progress_current' => max(0, $current),
            'status' => SyncActivity::STATUS_RUNNING,
        ];

        if ($total !== null) {
            $payload['progress_total'] = max(0, $total);
        }

        if ($message !== null) {
            $payload['message'] = $message;
        }

        $this->current->update($payload);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (! $this->current) {
            return;
        }

        SyncActivityLog::query()->create([
            'sync_activity_id' => $this->current->id,
            'level' => $level,
            'message' => $message,
            'context' => $context !== [] ? $context : null,
            'created_at' => now(),
        ]);

        if ($message !== '' && in_array($level, ['error', 'warning', 'success'], true)) {
            $this->current->update(['message' => $this->summarize($message)]);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function complete(string $message, int $synced = 0, int $errors = 0, array $meta = []): void
    {
        if (! $this->current) {
            return;
        }

        $this->current->refresh();
        if ($this->current->isCancelled()) {
            $this->reset();

            return;
        }

        $status = $errors > 0 ? SyncActivity::STATUS_PARTIAL : SyncActivity::STATUS_COMPLETED;
        $mergedMeta = array_merge($this->current->meta ?? [], $meta, [
            'synced' => $synced,
            'errors' => $errors,
        ]);

        $this->current->update([
            'status' => $status,
            'message' => $this->summarize($message),
            'progress_current' => $this->current->progress_total ?: max($this->current->progress_current, $synced),
            'meta' => $mergedMeta,
            'finished_at' => now(),
            'dismissed_at' => now(),
        ]);

        $this->log($errors > 0 ? 'warning' : 'success', $message, [
            'synced' => $synced,
            'errors' => $errors,
        ]);

        $this->reset();
    }

    public function fail(string $message, ?\Throwable $exception = null): void
    {
        if (! $this->current) {
            return;
        }

        $this->current->refresh();
        if ($this->current->isCancelled()) {
            $this->reset();

            return;
        }

        $this->current->update([
            'status' => SyncActivity::STATUS_FAILED,
            'message' => $this->summarize($message),
            'finished_at' => now(),
            'dismissed_at' => now(),
            'meta' => array_merge($this->current->meta ?? [], [
                'exception' => $exception?->getMessage(),
            ]),
        ]);

        $this->log('error', $message, [
            'exception' => $exception?->getMessage(),
        ]);

        $this->reset();
    }

    public function reset(): void
    {
        $this->current = null;
    }

    /**
     * Kullanıcı veya izleyici iptali: kaydı kapatır, çalışan job bir sonraki progress'te durur.
     */
    public function cancel(string $message = 'İşlem iptal edildi.'): void
    {
        if (! $this->current) {
            return;
        }

        $this->current->refresh();
        if (! $this->current->isActive()) {
            return;
        }

        $this->current->cancel($this->summarize($message));
        $this->log('warning', $message);
        $this->reset();
    }

    /**
     * @throws SyncActivityCancelledException
     */
    public function abortIfCancelled(): void
    {
        if (! $this->current) {
            return;
        }

        $this->current->refresh();
        if ($this->current->isCancelled()) {
            throw new SyncActivityCancelledException();
        }
    }

    /**
     * Cron / kuyruk job'ları arasında önceki kaydı bırakıp yeni aktivite açmak için.
     */
    public function ensureFresh(string $type, string $title, ?int $total = null, array $meta = []): SyncActivity
    {
        if ($this->current) {
            $this->current->refresh();

            if ($this->current->type === $type && $this->current->isActive()) {
                return $this->current;
            }

            $this->reset();
        }

        return $this->start($type, $title, $total, $meta);
    }

    private function summarize(string $message): string
    {
        return Str::limit(trim($message), 240);
    }
}
