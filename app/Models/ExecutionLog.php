<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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
    const STATUS_STOPPED = 'stopped';

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
        try {
            $currentLogs = $this->log_details ?? [];

            // اطمینان از اینکه log_details یک آرایه است
            if (!is_array($currentLogs)) {
                $currentLogs = [];
            }

            $newEntry = [
                'timestamp' => now()->toISOString(),
                'message' => (string)$message,
                'context' => $this->sanitizeContext($context)
            ];

            $currentLogs[] = $newEntry;

            $this->update(['log_details' => $currentLogs]);
        } catch (\Exception $e) {
            Log::error("❌ خطا در افزودن log entry", [
                'execution_id' => $this->execution_id,
                'message' => $message,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * پاکسازی context برای جلوگیری از خطای array to string
     */
    private function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeContext($value);
            } elseif (is_object($value)) {
                try {
                    $sanitized[$key] = json_decode(json_encode($value), true);
                } catch (\Exception $e) {
                    $sanitized[$key] = 'Object (' . get_class($value) . ')';
                }
            } elseif (is_resource($value)) {
                $sanitized[$key] = 'Resource';
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * تکمیل اجرا با آمار نهایی
     */
    public function markCompleted(array $stats): void
    {
        $executionTime = $this->started_at ? now()->diffInSeconds($this->started_at) : 0;

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'total_processed' => $stats['total'] ?? 0,
            'total_success' => $stats['success'] ?? 0,
            'total_failed' => $stats['failed'] ?? 0,
            'total_duplicate' => $stats['duplicate'] ?? 0,
            'execution_time' => $stats['execution_time'] ?? $executionTime,
            'finished_at' => now(),
        ]);

        // بروزرسانی آمار کانفیگ
        $this->config?->syncStatsFromLogs();

        $this->addLogEntry('اجرا با موفقیت تمام شد', [
            'final_stats' => $stats,
            'execution_time' => $executionTime
        ]);
    }

    public function markFailed(string $errorMessage): void
    {
        $executionTime = $this->started_at ? now()->diffInSeconds($this->started_at) : 0;

        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'execution_time' => $executionTime,
            'finished_at' => now(),
        ]);

        $this->addLogEntry('اجرا با خطا متوقف شد', [
            'error' => $errorMessage,
            'execution_time' => $executionTime
        ]);
    }

    /**
     * متوقف کردن اجرا با آمار نهایی
     */
    public function stop(array $finalStats = []): void
    {
        try {
            $executionTime = $this->started_at ? now()->diffInSeconds($this->started_at) : 0;
            $executionTime = max(0, $executionTime);

            $config = $this->config;
            $actualStats = [
                'total_processed' => $config ? $config->total_processed : 0,
                'total_success' => $config ? $config->total_success : 0,
                'total_failed' => $config ? $config->total_failed : 0
            ];

            $this->update([
                'status' => self::STATUS_STOPPED,
                'total_processed' => max($finalStats['total_processed_at_stop'] ?? 0, $actualStats['total_processed']),
                'total_success' => max($finalStats['total_success_at_stop'] ?? 0, $actualStats['total_success']),
                'total_failed' => max($finalStats['total_failed_at_stop'] ?? 0, $actualStats['total_failed']),
                'total_duplicate' => $finalStats['total_duplicate_at_stop'] ?? $this->total_duplicate,
                'execution_time' => $executionTime,
                'success_rate' => $actualStats['total_processed'] > 0
                    ? round(($actualStats['total_success'] / $actualStats['total_processed']) * 100, 2)
                    : 0,
                'stop_reason' => $finalStats['stopped_manually'] ?? false ? 'متوقف شده توسط کاربر' : 'متوقف شده',
                'error_message' => $finalStats['stopped_manually'] ?? false ? 'متوقف شده توسط کاربر' : $this->error_message,
                'finished_at' => now(),
                'last_activity_at' => now(),
            ]);

            if ($config) {
                $config->syncStatsFromLogs();
            }

            $this->addLogEntry('⏹️ اجرا متوقف شد', [
                'stopped_manually' => $finalStats['stopped_manually'] ?? false,
                'execution_time_seconds' => $executionTime,
                'stopped_at' => now()->toISOString(),
                'final_stats' => [
                    'total_processed' => $this->total_processed,
                    'total_success' => $this->total_success,
                    'total_failed' => $this->total_failed
                ]
            ]);

            Log::info("⏹️ ExecutionLog متوقف شد", [
                'execution_id' => $this->execution_id,
                'execution_time' => $executionTime,
                'final_stats' => $actualStats
            ]);
        } catch (\Exception $e) {
            Log::error("❌ خطا در متوقف کردن ExecutionLog", [
                'execution_id' => $this->execution_id,
                'error' => $e->getMessage()
            ]);

            $this->update([
                'status' => self::STATUS_STOPPED,
                'finished_at' => now(),
                'error_message' => 'خطا در فرآیند توقف: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * متوقف کردن سریع بدون آمار اضافی
     */
    public function markStopped(string $reason = 'متوقف شده توسط کاربر'): void
    {
        $this->stop(['stopped_manually' => true]);
    }

    /**
     * بروزرسانی پیشرفت در حین اجرا
     */
    public function updateProgress(array $pageStats): void
    {
        Log::info("📊 بروزرسانی ExecutionLog progress", [
            'log_id' => $this->id,
            'execution_id' => $this->execution_id,
            'incoming_stats' => $pageStats
        ]);

        try {
            DB::transaction(function () use ($pageStats) {
                $log = ExecutionLog::lockForUpdate()->find($this->id);

                if (!$log) {
                    throw new \Exception("ExecutionLog {$this->id} یافت نشد");
                }

                $totalToAdd = is_numeric($pageStats['total'] ?? 0) ? (int)($pageStats['total'] ?? 0) : 0;
                $successToAdd = is_numeric($pageStats['success'] ?? 0) ? (int)($pageStats['success'] ?? 0) : 0;
                $failedToAdd = is_numeric($pageStats['failed'] ?? 0) ? (int)($pageStats['failed'] ?? 0) : 0;
                $duplicateToAdd = is_numeric($pageStats['duplicate'] ?? 0) ? (int)($pageStats['duplicate'] ?? 0) : 0;

                $log->increment('total_processed', $totalToAdd);
                $log->increment('total_success', $successToAdd);
                $log->increment('total_failed', $failedToAdd);
                $log->increment('total_duplicate', $duplicateToAdd);

                $newTotal = $log->total_processed + $totalToAdd;
                $newSuccessRate = $newTotal > 0
                    ? round((($log->total_success + $successToAdd) / $newTotal) * 100, 2)
                    : 0;

                $log->update([
                    'success_rate' => $newSuccessRate,
                    'last_activity_at' => now()
                ]);
            });

            $this->refresh();

            $this->addLogEntry('📊 پیشرفت بروزرسانی شد', [
                'page_stats' => $pageStats,
                'cumulative_stats' => [
                    'total_processed' => $this->total_processed,
                    'total_success' => $this->total_success,
                    'total_failed' => $this->total_failed,
                    'total_duplicate' => $this->total_duplicate,
                    'success_rate' => $this->success_rate
                ]
            ]);

            Log::info("✅ ExecutionLog progress بروزرسانی شد", [
                'log_id' => $this->id,
                'execution_id' => $this->execution_id,
                'final_stats' => [
                    'total_processed' => $this->total_processed,
                    'total_success' => $this->total_success,
                    'total_failed' => $this->total_failed,
                    'total_duplicate' => $this->total_duplicate
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("❌ خطا در بروزرسانی ExecutionLog progress", [
                'log_id' => $this->id,
                'error' => $e->getMessage(),
                'pageStats' => $pageStats
            ]);
            throw $e;
        }
    }

    /**
     * محاسبه زمان اجرا صحیح
     */
    public function getCorrectExecutionTime(): float
    {
        if ($this->execution_time && $this->execution_time > 0) {
            return $this->execution_time;
        }

        if ($this->started_at && $this->finished_at) {
            $diff = $this->finished_at->diffInSeconds($this->started_at);
            return max(0, $diff);
        }

        if ($this->started_at && $this->status === 'running') {
            $diff = now()->diffInSeconds($this->started_at);
            return max(0, $diff);
        }

        return 0;
    }

    /**
     * بررسی وضعیت‌ها
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
        switch ($this->status) {
            case self::STATUS_COMPLETED:
                return 'green';
            case self::STATUS_FAILED:
                return 'red';
            case self::STATUS_STOPPED:
                return 'orange';
            default:
                return 'yellow';
        }
    }

    /**
     * دریافت متن وضعیت
     */
    public function getStatusTextAttribute(): string
    {
        switch ($this->status) {
            case self::STATUS_RUNNING:
                return 'در حال اجرا';
            case self::STATUS_COMPLETED:
                return 'تمام شده';
            case self::STATUS_FAILED:
                return 'ناموفق';
            case self::STATUS_STOPPED:
                return 'متوقف شده';
            default:
                return 'نامشخص';
        }
    }

    /**
     * دریافت خلاصه آمار برای نمایش
     */
    public function getStatsSummary(): string
    {
        if ($this->status === 'running') {
            return 'در حال اجرا...';
        }

        $parts = [];

        if ($this->total_processed > 0) {
            $parts[] = "کل: " . number_format($this->total_processed);
        }

        if ($this->total_success > 0) {
            $parts[] = "✅ " . number_format($this->total_success);
        }

        if ($this->total_failed > 0) {
            $parts[] = "❌ " . number_format($this->total_failed);
        }

        if ($this->total_duplicate > 0) {
            $parts[] = "🔄 " . number_format($this->total_duplicate);
        }

        return empty($parts) ? 'بدون آمار' : implode(' | ', $parts);
    }
}
