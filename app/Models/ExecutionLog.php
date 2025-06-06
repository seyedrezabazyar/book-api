<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionLog extends Model
{
    protected $fillable = [
        'config_id',
        'execution_id',
        'status',
        'total_processed',
        'total_success',
        'total_failed',
        'total_duplicate',
        'execution_time',
        'log_details',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'log_details' => 'array',
        'total_processed' => 'integer',
        'total_success' => 'integer',
        'total_failed' => 'integer',
        'total_duplicate' => 'integer',
        'execution_time' => 'float',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    const STATUS_RUNNING = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    public function config(): BelongsTo
    {
        return $this->belongsTo(Config::class);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function getSuccessRateAttribute(): float
    {
        if ($this->total_processed === 0) return 0;
        return round(($this->total_success / $this->total_processed) * 100, 1);
    }

    public static function createNew(Config $config): self
    {
        return self::create([
            'config_id' => $config->id,
            'execution_id' => uniqid('exec_'),
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
            'log_details' => [],
        ]);
    }

    public function addLogEntry(string $message, array $context = []): void
    {
        $logs = $this->log_details ?? [];
        $logs[] = [
            'timestamp' => now()->toISOString(),
            'message' => $message,
            'context' => $context,
        ];

        $this->update(['log_details' => $logs]);
    }

    public function markCompleted(array $stats): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'total_processed' => $stats['total'],
            'total_success' => $stats['success'],
            'total_failed' => $stats['failed'],
            'total_duplicate' => $stats['duplicate'],
            'execution_time' => $stats['execution_time'] ?? 0,
            'finished_at' => now(),
        ]);
    }

    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'finished_at' => now(),
        ]);
    }
}
