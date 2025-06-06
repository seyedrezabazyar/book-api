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
    const STATUS_STOPPED = 'stopped'; // اضافه کردیم

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
            'total_processed' => $stats['total'] ?? 0,
            'total_success' => $stats['success'] ?? 0,
            'total_failed' => $stats['failed'] ?? 0,
            'total_duplicate' => $stats['duplicate'] ?? 0,
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

    /**
     * متوقف کردن اجرا با آمار نهایی
     */
    public function stop(array $finalStats = []): void
    {
        $this->update([
            'status' => self::STATUS_STOPPED,
            'total_processed' => $finalStats['total_processed_at_stop'] ?? $this->total_processed,
            'total_success' => $finalStats['total_success_at_stop'] ?? $this->total_success,
            'total_failed' => $finalStats['total_failed_at_stop'] ?? $this->total_failed,
            'total_duplicate' => $finalStats['total_duplicate_at_stop'] ?? $this->total_duplicate,
            'execution_time' => $finalStats['execution_time'] ?? 0,
            'error_message' => $finalStats['stopped_manually'] ?? false ? 'متوقف شده توسط کاربر' : $this->error_message,
            'finished_at' => now(),
            'log_details' => array_merge($this->log_details ?? [], [
                [
                    'timestamp' => now()->toISOString(),
                    'message' => 'اجرا متوقف شد',
                    'context' => $finalStats
                ]
            ])
        ]);
    }

    /**
     * متوقف کردن سریع بدون آمار اضافی
     */
    public function markStopped(string $reason = 'متوقف شده توسط کاربر'): void
    {
        $this->update([
            'status' => self::STATUS_STOPPED,
            'error_message' => $reason,
            'finished_at' => now(),
        ]);
    }

    /**
     * بررسی وضعیت اجرا
     */
    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isStopped(): bool
    {
        return $this->status === self::STATUS_STOPPED;
    }

    /**
     * دریافت رنگ وضعیت برای UI
     */
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            self::STATUS_COMPLETED => 'green',
            self::STATUS_FAILED => 'red',
            self::STATUS_STOPPED => 'orange',
            default => 'yellow'
        };
    }

    /**
     * دریافت متن وضعیت
     */
    public function getStatusTextAttribute(): string
    {
        return match($this->status) {
            self::STATUS_RUNNING => 'در حال اجرا',
            self::STATUS_COMPLETED => 'تمام شده',
            self::STATUS_FAILED => 'ناموفق',
            self::STATUS_STOPPED => 'متوقف شده',
            default => 'نامشخص'
        };
    }
}
