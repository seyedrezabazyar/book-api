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
        'total_enhanced',
        'execution_time',
        'success_rate',
        'log_details',
        'error_message',
        'stop_reason',
        'started_at',
        'finished_at',
        'last_activity_at',
    ];

    protected $casts = [
        'log_details' => 'array',
        'total_processed' => 'integer',
        'total_success' => 'integer',
        'total_failed' => 'integer',
        'total_duplicate' => 'integer',
        'total_enhanced' => 'integer',
        'execution_time' => 'float',
        'success_rate' => 'float',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    const STATUS_RUNNING = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_STOPPED = 'stopped';

    public function config(): BelongsTo
    {
        return $this->belongsTo(Config::class);
    }

    /**
     * ایجاد ExecutionLog جدید
     */
    public static function createNew(Config $config): self
    {
        return self::create([
            'config_id' => $config->id,
            'execution_id' => uniqid('exec_' . time() . '_'),
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
            'last_activity_at' => now(),
            'log_details' => [],
            'total_processed' => 0,
            'total_success' => 0,
            'total_failed' => 0,
            'total_duplicate' => 0,
            'total_enhanced' => 0,
            'execution_time' => 0,
            'success_rate' => 0,
        ]);
    }

    /**
     * افزودن ورودی لاگ
     */
    public function addLogEntry(string $message, array $context = []): void
    {
        try {
            $currentLogs = $this->log_details ?? [];

            if (!is_array($currentLogs)) {
                $currentLogs = [];
            }

            $newEntry = [
                'timestamp' => now()->toISOString(),
                'message' => (string)$message,
                'context' => $this->sanitizeContext($context)
            ];

            $currentLogs[] = $newEntry;

            // محدود کردن تعداد لاگ‌ها
            if (count($currentLogs) > 1000) {
                $currentLogs = array_slice($currentLogs, -1000);
            }

            $this->update([
                'log_details' => $currentLogs,
                'last_activity_at' => now()
            ]);
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
     * بروزرسانی پیشرفت با آمار جدید - کاملاً اصلاح شده
     */
    public function updateProgress(array $pageStats): void
    {
        Log::debug("📊 شروع بروزرسانی ExecutionLog progress", [
            'log_id' => $this->id,
            'execution_id' => $this->execution_id,
            'incoming_stats' => $pageStats,
            'current_stats' => [
                'total_processed' => $this->total_processed,
                'total_success' => $this->total_success,
                'total_enhanced' => $this->total_enhanced,
                'total_failed' => $this->total_failed,
                'total_duplicate' => $this->total_duplicate,
            ]
        ]);

        try {
            $self = $this; // کپی کردن reference برای استفاده در closure

            DB::transaction(function () use ($pageStats, $self) {
                $log = ExecutionLog::lockForUpdate()->find($self->id);

                if (!$log) {
                    throw new \Exception("ExecutionLog {$self->id} یافت نشد");
                }

                // پردازش آمار ورودی با کلیدهای استاندارد - تعریف متغیرها داخل closure
                $totalToAdd = $self->extractStatValue($pageStats, ['total_processed', 'total']);
                $successToAdd = $self->extractStatValue($pageStats, ['total_success', 'success']);
                $failedToAdd = $self->extractStatValue($pageStats, ['total_failed', 'failed']);
                $duplicateToAdd = $self->extractStatValue($pageStats, ['total_duplicate', 'duplicate']);
                $enhancedToAdd = $self->extractStatValue($pageStats, ['total_enhanced', 'enhanced']);

                // بروزرسانی آمار
                $log->increment('total_processed', $totalToAdd);
                $log->increment('total_success', $successToAdd);
                $log->increment('total_failed', $failedToAdd);
                $log->increment('total_duplicate', $duplicateToAdd);
                $log->increment('total_enhanced', $enhancedToAdd);

                // محاسبه و بروزرسانی نرخ موفقیت
                $log->refresh(); // دریافت مقادیر جدید
                $newTotal = $log->total_processed;
                $newActualSuccess = $log->total_success + $log->total_enhanced;
                $newSuccessRate = $newTotal > 0 ? round(($newActualSuccess / $newTotal) * 100, 2) : 0;

                $log->update([
                    'success_rate' => $newSuccessRate,
                    'last_activity_at' => now()
                ]);

                // لاگ کردن آمار داخل transaction
                Log::debug("📊 آمار ExecutionLog بروزرسانی شد", [
                    'log_id' => $self->id,
                    'execution_id' => $self->execution_id,
                    'added_stats' => [
                        'total_processed' => $totalToAdd,
                        'total_success' => $successToAdd,
                        'total_enhanced' => $enhancedToAdd,
                        'total_failed' => $failedToAdd,
                        'total_duplicate' => $duplicateToAdd
                    ]
                ]);
            });

            $this->refresh();

            $this->addLogEntry('📊 پیشرفت بروزرسانی شد', [
                'page_stats' => $pageStats,
                'cumulative_stats' => [
                    'total_processed' => $this->total_processed,
                    'total_success' => $this->total_success,
                    'total_enhanced' => $this->total_enhanced,
                    'total_failed' => $this->total_failed,
                    'total_duplicate' => $this->total_duplicate,
                    'success_rate' => $this->success_rate,
                    'real_success_rate' => $this->getRealSuccessRateAttribute()
                ]
            ]);

            Log::info("✅ ExecutionLog progress بروزرسانی شد", [
                'log_id' => $this->id,
                'execution_id' => $this->execution_id,
                'final_stats' => [
                    'total_processed' => $this->total_processed,
                    'total_success' => $this->total_success,
                    'total_enhanced' => $this->total_enhanced,
                    'total_failed' => $this->total_failed,
                    'total_duplicate' => $this->total_duplicate,
                    'real_success_rate' => $this->getRealSuccessRateAttribute()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error("❌ خطا در بروزرسانی ExecutionLog progress", [
                'log_id' => $this->id,
                'error' => $e->getMessage(),
                'pageStats' => $pageStats,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * استخراج مقدار آمار با کلیدهای مختلف
     */
    private function extractStatValue(array $stats, array $possibleKeys): int
    {
        foreach ($possibleKeys as $key) {
            if (isset($stats[$key]) && is_numeric($stats[$key])) {
                return (int)$stats[$key];
            }
        }
        return 0;
    }

    /**
     * تکمیل اجرا با آمار نهایی
     */
    public function markCompleted(array $stats): void
    {
        try {
            $executionTime = $this->calculateExecutionTime();

            // استخراج آمار نهایی
            $finalStats = [
                'total_processed' => $this->extractStatValue($stats, ['total_processed', 'total']) ?: $this->total_processed,
                'total_success' => $this->extractStatValue($stats, ['total_success', 'success']) ?: $this->total_success,
                'total_failed' => $this->extractStatValue($stats, ['total_failed', 'failed']) ?: $this->total_failed,
                'total_duplicate' => $this->extractStatValue($stats, ['total_duplicate', 'duplicate']) ?: $this->total_duplicate,
                'total_enhanced' => $this->extractStatValue($stats, ['total_enhanced', 'enhanced']) ?: $this->total_enhanced,
            ];

            // محاسبه نرخ موفقیت نهایی
            $totalProcessed = $finalStats['total_processed'];
            $realSuccess = $finalStats['total_success'] + $finalStats['total_enhanced'];
            $finalSuccessRate = $totalProcessed > 0 ? round(($realSuccess / $totalProcessed) * 100, 2) : 0;

            $this->update([
                'status' => self::STATUS_COMPLETED,
                'total_processed' => $finalStats['total_processed'],
                'total_success' => $finalStats['total_success'],
                'total_failed' => $finalStats['total_failed'],
                'total_duplicate' => $finalStats['total_duplicate'],
                'total_enhanced' => $finalStats['total_enhanced'],
                'execution_time' => $executionTime,
                'success_rate' => $finalSuccessRate,
                'finished_at' => now(),
                'last_activity_at' => now(),
            ]);

            // بروزرسانی آمار کانفیگ
            $this->config?->syncStatsFromLogs();

            $this->addLogEntry('✅ اجرا با موفقیت تمام شد', [
                'final_stats' => $finalStats,
                'execution_time_seconds' => $executionTime,
                'final_success_rate' => $finalSuccessRate,
                'real_success_rate' => $this->getRealSuccessRateAttribute()
            ]);

            Log::info("✅ ExecutionLog مارک completed شد", [
                'execution_id' => $this->execution_id,
                'execution_time' => $executionTime,
                'final_stats' => $finalStats,
                'success_rate' => $finalSuccessRate
            ]);
        } catch (\Exception $e) {
            Log::error("❌ خطا در markCompleted", [
                'execution_id' => $this->execution_id,
                'error' => $e->getMessage(),
                'stats' => $stats
            ]);
            throw $e;
        }
    }

    /**
     * محاسبه زمان اجرا
     */
    private function calculateExecutionTime(): float
    {
        if ($this->started_at) {
            $endTime = $this->finished_at ?: now();
            $seconds = $this->started_at->diffInSeconds($endTime);
            return max(0, $seconds); // اطمینان از مثبت بودن
        }

        return 0;
    }

    public function markFailed(string $errorMessage): void
    {
        $executionTime = $this->calculateExecutionTime();

        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'execution_time' => $executionTime,
            'finished_at' => now(),
            'last_activity_at' => now(),
        ]);

        $this->addLogEntry('❌ اجرا با خطا متوقف شد', [
            'error' => $errorMessage,
            'execution_time_seconds' => $executionTime
        ]);
    }

    /**
     * متوقف کردن اجرا با آمار نهایی
     */
    public function stop(array $finalStats = []): void
    {
        try {
            $executionTime = $this->calculateExecutionTime();

            $config = $this->config;
            $actualStats = [
                'total_processed' => $this->total_processed,
                'total_success' => $this->total_success,
                'total_failed' => $this->total_failed,
                'total_enhanced' => $this->total_enhanced,
                'total_duplicate' => $this->total_duplicate
            ];

            // محاسبه نرخ موفقیت نهایی
            $realSuccess = $actualStats['total_success'] + $actualStats['total_enhanced'];
            $finalSuccessRate = $actualStats['total_processed'] > 0 ?
                round(($realSuccess / $actualStats['total_processed']) * 100, 2) : 0;

            $this->update([
                'status' => self::STATUS_STOPPED,
                'total_processed' => max($finalStats['total_processed_at_stop'] ?? 0, $actualStats['total_processed']),
                'total_success' => max($finalStats['total_success_at_stop'] ?? 0, $actualStats['total_success']),
                'total_failed' => max($finalStats['total_failed_at_stop'] ?? 0, $actualStats['total_failed']),
                'total_duplicate' => max($finalStats['total_duplicate_at_stop'] ?? 0, $actualStats['total_duplicate']),
                'total_enhanced' => max($finalStats['total_enhanced_at_stop'] ?? 0, $actualStats['total_enhanced']),
                'execution_time' => $executionTime,
                'success_rate' => $finalSuccessRate,
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
                'final_stats' => $actualStats,
                'final_success_rate' => $finalSuccessRate
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
                'last_activity_at' => now(),
                'error_message' => 'خطا در فرآیند توقف: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * محاسبه نرخ موفقیت واقعی (شامل enhanced)
     */
    public function getRealSuccessRateAttribute(): float
    {
        if ($this->total_processed <= 0) {
            return 0;
        }

        $realSuccess = $this->total_success + $this->total_enhanced;
        return round(($realSuccess / $this->total_processed) * 100, 2);
    }

    /**
     * بررسی وضعیت‌ها
     */
    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }
}
