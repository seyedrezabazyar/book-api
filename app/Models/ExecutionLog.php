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

    /**
     * دریافت نرخ موفقیت واقعی (شامل کتاب‌های بهبود یافته)
     */
    public function getRealSuccessRateAttribute(): float
    {
        if ($this->total_processed === 0) return 0;

        $realSuccess = $this->total_success + $this->total_enhanced;
        return round(($realSuccess / $this->total_processed) * 100, 1);
    }

    public static function createNew(Config $config): self
    {
        return self::create([
            'config_id' => $config->id,
            'execution_id' => uniqid('exec_'),
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
            'last_activity_at' => now(),
            'log_details' => [],
        ]);
    }

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
     * بروزرسانی پیشرفت با آمار جدید
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

                // آمار سنتی
                $totalToAdd = is_numeric($pageStats['total'] ?? 0) ? (int)($pageStats['total'] ?? 0) : 0;
                $successToAdd = is_numeric($pageStats['success'] ?? 0) ? (int)($pageStats['success'] ?? 0) : 0;
                $failedToAdd = is_numeric($pageStats['failed'] ?? 0) ? (int)($pageStats['failed'] ?? 0) : 0;
                $duplicateToAdd = is_numeric($pageStats['duplicate'] ?? 0) ? (int)($pageStats['duplicate'] ?? 0) : 0;

                // آمار جدید
                $enhancedToAdd = is_numeric($pageStats['enhanced'] ?? 0) ? (int)($pageStats['enhanced'] ?? 0) : 0;

                $log->increment('total_processed', $totalToAdd);
                $log->increment('total_success', $successToAdd);
                $log->increment('total_failed', $failedToAdd);
                $log->increment('total_duplicate', $duplicateToAdd);
                $log->increment('total_enhanced', $enhancedToAdd);

                // محاسبه نرخ موفقیت جدید
                $newTotal = $log->total_processed + $totalToAdd;
                $newActualSuccess = ($log->total_success + $successToAdd) + ($log->total_enhanced + $enhancedToAdd);
                $newSuccessRate = $newTotal > 0 ? round(($newActualSuccess / $newTotal) * 100, 2) : 0;

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
                    'total_enhanced' => $this->total_enhanced,
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
                    'total_enhanced' => $this->total_enhanced,
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
            'total_enhanced' => $stats['enhanced'] ?? 0,
            'execution_time' => $stats['execution_time'] ?? $executionTime,
            'finished_at' => now(),
            'last_activity_at' => now(),
        ]);

        // بروزرسانی آمار کانفیگ
        $this->config?->syncStatsFromLogs();

        $this->addLogEntry('✅ اجرا با موفقیت تمام شد', [
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
            'last_activity_at' => now(),
        ]);

        $this->addLogEntry('❌ اجرا با خطا متوقف شد', [
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
                'total_failed' => $config ? $config->total_failed : 0,
                'total_enhanced' => $this->total_enhanced
            ];

            $this->update([
                'status' => self::STATUS_STOPPED,
                'total_processed' => max($finalStats['total_processed_at_stop'] ?? 0, $actualStats['total_processed']),
                'total_success' => max($finalStats['total_success_at_stop'] ?? 0, $actualStats['total_success']),
                'total_failed' => max($finalStats['total_failed_at_stop'] ?? 0, $actualStats['total_failed']),
                'total_duplicate' => $finalStats['total_duplicate_at_stop'] ?? $this->total_duplicate,
                'total_enhanced' => $finalStats['total_enhanced_at_stop'] ?? $this->total_enhanced,
                'execution_time' => $executionTime,
                'success_rate' => $this->calculateFinalSuccessRate($actualStats),
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
                    'total_enhanced' => $this->total_enhanced,
                    'total_failed' => $this->total_failed,
                    'real_success_rate' => $this->real_success_rate
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
                'last_activity_at' => now(),
                'error_message' => 'خطا در فرآیند توقف: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * محاسبه نرخ موفقیت نهایی
     */
    private function calculateFinalSuccessRate(array $stats): float
    {
        $totalProcessed = $stats['total_processed'] ?? $this->total_processed;
        if ($totalProcessed <= 0) return 0;

        $realSuccess = ($stats['total_success'] ?? $this->total_success) +
            ($stats['total_enhanced'] ?? $this->total_enhanced);

        return round(($realSuccess / $totalProcessed) * 100, 2);
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
     * دریافت خلاصه آمار تفصیلی
     */
    public function getStatsDetailedSummary(): string
    {
        if ($this->status === 'running') {
            return 'در حال اجرا...';
        }

        $parts = [];

        if ($this->total_processed > 0) {
            $parts[] = "کل: " . number_format($this->total_processed);
        }

        if ($this->total_success > 0) {
            $parts[] = "✅ جدید: " . number_format($this->total_success);
        }

        if ($this->total_enhanced > 0) {
            $parts[] = "🔧 بهبود: " . number_format($this->total_enhanced);
        }

        if ($this->total_duplicate > 0) {
            $parts[] = "🔄 تکراری: " . number_format($this->total_duplicate);
        }

        if ($this->total_failed > 0) {
            $parts[] = "❌ خطا: " . number_format($this->total_failed);
        }

        // اضافه کردن نرخ موفقیت واقعی
        if ($this->total_processed > 0) {
            $realSuccessRate = $this->real_success_rate;
            $parts[] = "📈 {$realSuccessRate}% موثر";
        }

        return empty($parts) ? 'بدون آمار' : implode(' | ', $parts);
    }

    /**
     * دریافت آمار عملکرد
     */
    public function getPerformanceStats(): array
    {
        $stats = [
            'total_processed' => $this->total_processed,
            'total_success' => $this->total_success,
            'total_enhanced' => $this->total_enhanced,
            'total_duplicate' => $this->total_duplicate,
            'total_failed' => $this->total_failed,
            'success_rate' => $this->success_rate,
            'real_success_rate' => $this->real_success_rate,
            'execution_time' => $this->execution_time
        ];

        // محاسبه آمار اضافی
        if ($this->total_processed > 0) {
            $stats['duplicate_rate'] = round(($this->total_duplicate / $this->total_processed) * 100, 1);
            $stats['enhancement_rate'] = round(($this->total_enhanced / $this->total_processed) * 100, 1);
            $stats['failure_rate'] = round(($this->total_failed / $this->total_processed) * 100, 1);
        }

        if ($this->execution_time > 0) {
            $stats['records_per_second'] = round($this->total_processed / $this->execution_time, 2);
            $stats['records_per_minute'] = round(($this->total_processed / $this->execution_time) * 60, 2);
        }

        return $stats;
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

    /**
     * اطلاعات کامل لاگ برای debugging
     */
    public function getDebugInfo(): array
    {
        return [
            'id' => $this->id,
            'execution_id' => $this->execution_id,
            'config_id' => $this->config_id,
            'status' => $this->status,
            'stats' => [
                'total_processed' => $this->total_processed,
                'total_success' => $this->total_success,
                'total_enhanced' => $this->total_enhanced,
                'total_duplicate' => $this->total_duplicate,
                'total_failed' => $this->total_failed,
                'success_rate' => $this->success_rate,
                'real_success_rate' => $this->real_success_rate,
            ],
            'timing' => [
                'started_at' => $this->started_at?->toISOString(),
                'finished_at' => $this->finished_at?->toISOString(),
                'last_activity_at' => $this->last_activity_at?->toISOString(),
                'execution_time' => $this->execution_time,
                'correct_execution_time' => $this->getCorrectExecutionTime(),
            ],
            'errors' => [
                'error_message' => $this->error_message,
                'stop_reason' => $this->stop_reason,
            ],
            'log_entries_count' => is_array($this->log_details) ? count($this->log_details) : 0,
        ];
    }

    /**
     * صادرات آمار برای گزارش
     */
    public function exportStats(): array
    {
        $executionTime = $this->getCorrectExecutionTime();

        return [
            'execution_info' => [
                'id' => $this->execution_id,
                'config_name' => $this->config?->name,
                'started_at' => $this->started_at?->format('Y-m-d H:i:s'),
                'finished_at' => $this->finished_at?->format('Y-m-d H:i:s'),
                'duration_seconds' => $executionTime,
                'status' => $this->status_text,
            ],
            'processing_stats' => [
                'total_processed' => $this->total_processed,
                'total_success' => $this->total_success,
                'total_enhanced' => $this->total_enhanced,
                'total_duplicate' => $this->total_duplicate,
                'total_failed' => $this->total_failed,
            ],
            'performance_metrics' => [
                'success_rate_percent' => $this->success_rate,
                'real_success_rate_percent' => $this->real_success_rate,
                'records_per_second' => $executionTime > 0 ? round($this->total_processed / $executionTime, 2) : 0,
                'efficiency_score' => $this->calculateEfficiencyScore(),
            ]
        ];
    }

    /**
     * محاسبه امتیاز کارایی
     */
    private function calculateEfficiencyScore(): float
    {
        if ($this->total_processed <= 0) return 0;

        $realSuccess = $this->total_success + $this->total_enhanced;
        $realSuccessRate = ($realSuccess / $this->total_processed) * 100;
        $failureRate = ($this->total_failed / $this->total_processed) * 100;

        // امتیاز بر اساس موفقیت واقعی و کمینه کردن خطا
        $score = $realSuccessRate - ($failureRate * 0.5);

        return round(max(0, min(100, $score)), 1);
    }

    /**
     * بررسی سلامت اجرا
     */
    public function getHealthStatus(): array
    {
        $health = [
            'overall' => 'good',
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];

        // بررسی نرخ خطا
        if ($this->total_processed > 0) {
            $failureRate = ($this->total_failed / $this->total_processed) * 100;

            if ($failureRate > 50) {
                $health['overall'] = 'critical';
                $health['issues'][] = "نرخ خطای بالا: {$failureRate}%";
            } elseif ($failureRate > 20) {
                $health['overall'] = 'warning';
                $health['warnings'][] = "نرخ خطای متوسط: {$failureRate}%";
            }
        }

        // بررسی زمان اجرا
        $executionTime = $this->getCorrectExecutionTime();
        if ($executionTime > 3600) { // بیش از 1 ساعت
            $health['warnings'][] = "زمان اجرای طولانی: " . gmdate('H:i:s', $executionTime);
            $health['recommendations'][] = "تنظیمات delay و batch size را بررسی کنید";
        }

        // بررسی وضعیت نامتعارف
        if ($this->status === 'running' && $this->last_activity_at < now()->subMinutes(30)) {
            $health['overall'] = 'critical';
            $health['issues'][] = "اجرا ممکن است متوقف شده باشد (آخرین فعالیت: " . $this->last_activity_at?->diffForHumans() . ")";
        }

        // بررسی نرخ تأثیرگذاری
        if ($this->total_processed > 0) {
            $impactRate = $this->real_success_rate;
            if ($impactRate < 10) {
                $health['warnings'][] = "نرخ تأثیرگذاری پایین: {$impactRate}%";
                $health['recommendations'][] = "تنظیمات منبع داده و API را بررسی کنید";
            }
        }

        return $health;
    }

    /**
     * خلاصه اجرا برای نمایش سریع
     */
    public function getQuickSummary(): string
    {
        if ($this->status === 'running') {
            $runtime = $this->started_at ? now()->diffInMinutes($this->started_at) : 0;
            return "🔄 در حال اجرا ({$runtime} دقیقه) - {$this->total_processed} پردازش شده";
        }

        $executionTime = $this->getCorrectExecutionTime();
        $timeText = $executionTime > 60 ? round($executionTime / 60, 1) . 'دقیقه' : $executionTime . 'ثانیه';

        return "{$this->status_text} در {$timeText} - {$this->total_processed} پردازش، {$this->real_success_rate}% موثر";
    }
}
