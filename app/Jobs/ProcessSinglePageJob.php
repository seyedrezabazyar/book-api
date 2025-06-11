<?php

namespace App\Jobs;

use App\Models\Config;
use App\Models\ExecutionLog;
use App\Services\ApiDataService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProcessSinglePageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 دقیقه
    public $tries = 2;
    public $maxExceptions = 2;

    protected int $configId;
    protected string $executionId;
    protected int $sourceId;

    public function __construct($config, int $sourceId, string $executionId)
    {
        // اگر Config object باشد، ID را استخراج کن
        $this->configId = is_object($config) ? $config->id : (int)$config;
        $this->executionId = $executionId;
        $this->sourceId = $sourceId;

        // تنظیم صف بر اساس اولویت
        $this->onQueue('default');
    }

    public function handle(): void
    {
        try {
            Log::info("🚀 شروع Job پردازش هوشمند", [
                'config_id' => $this->configId,
                'execution_id' => $this->executionId,
                'source_id' => $this->sourceId,
                'job_id' => $this->job?->getJobId(),
                'processing_mode' => 'intelligent_md5_based'
            ]);

            // 1. بررسی‌های اولیه
            if (!$this->performInitialChecks()) {
                return;
            }

            // 2. دریافت کانفیگ و execution log
            $config = Config::find($this->configId);
            $executionLog = ExecutionLog::where('execution_id', $this->executionId)->first();

            if (!$config || !$executionLog) {
                Log::error("❌ کانفیگ یا ExecutionLog یافت نشد", [
                    'config_id' => $this->configId,
                    'execution_id' => $this->executionId
                ]);
                $this->delete();
                return;
            }

            // 3. بررسی‌های وضعیت
            if (!$this->checkExecutionStatus($config, $executionLog)) {
                return;
            }

            // 4. پردازش source ID با منطق بهبود یافته
            $result = $this->processSourceIdIntelligently($config, $executionLog);

            // 5. برنامه‌ریزی source ID بعدی
            $this->scheduleNextSourceIdIfNeeded($config, $executionLog, $result);

            Log::info("✅ Job پردازش هوشمند تمام شد", [
                'config_id' => $this->configId,
                'execution_id' => $this->executionId,
                'source_id' => $this->sourceId,
                'result_action' => $result['action'] ?? 'unknown',
                'result_stats' => $result['stats'] ?? []
            ]);

        } catch (\Exception $e) {
            $this->handleJobException($e);
        }
    }

    /**
     * بررسی‌های اولیه
     */
    private function performInitialChecks(): bool
    {
        // بررسی اعتبار source ID
        if ($this->sourceId <= 0) {
            Log::warning("❌ Source ID نامعتبر", [
                'source_id' => $this->sourceId,
                'execution_id' => $this->executionId
            ]);
            $this->delete();
            return false;
        }

        return true;
    }

    /**
     * بررسی وضعیت اجرا
     */
    private function checkExecutionStatus(Config $config, ExecutionLog $executionLog): bool
    {
        // بررسی وضعیت کانفیگ
        if (!$config->is_running) {
            Log::info("⏹️ کانفیگ متوقف شده، Job لغو می‌شود", [
                'config_id' => $this->configId,
                'execution_id' => $this->executionId,
                'source_id' => $this->sourceId
            ]);
            $this->delete();
            return false;
        }

        // بررسی وضعیت ExecutionLog
        if ($executionLog->status !== 'running') {
            Log::info("⏹️ ExecutionLog دیگر running نیست، Job متوقف می‌شود", [
                'status' => $executionLog->status,
                'execution_id' => $this->executionId,
                'source_id' => $this->sourceId
            ]);
            $this->delete();
            return false;
        }

        // بررسی دوباره وضعیت کانفیگ (double check)
        $config->refresh();
        if (!$config->is_running) {
            Log::info("⏹️ Double Check: کانفیگ متوقف شده، Job لغو می‌شود", [
                'config_id' => $this->configId
            ]);
            $this->delete();
            return false;
        }

        return true;
    }

    /**
     * پردازش source ID با منطق هوشمند
     */
    private function processSourceIdIntelligently(Config $config, ExecutionLog $executionLog): array
    {
        try {
            $apiService = new ApiDataService($config);

            Log::debug("🔄 شروع پردازش هوشمند source ID", [
                'source_id' => $this->sourceId,
                'config_name' => $config->source_name,
                'processing_features' => [
                    'md5_based_deduplication' => true,
                    'intelligent_field_updates' => true,
                    'author_isbn_merging' => true,
                    'hash_enhancement' => true,
                    'source_tracking' => true
                ]
            ]);

            $result = $apiService->processSourceId($this->sourceId, $executionLog);

            // بررسی نهایی وضعیت قبل از ثبت نتایج
            $config->refresh();
            if (!$config->is_running) {
                Log::info("⏹️ کانفیگ حین پردازش متوقف شد، نتایج ثبت نمی‌شود", [
                    'source_id' => $this->sourceId
                ]);
                $this->delete();
                return ['action' => 'cancelled', 'stats' => []];
            }

            // لاگ نتیجه تفصیلی
            $this->logDetailedResult($result);

            return $result;

        } catch (\Exception $e) {
            Log::error("❌ خطا در پردازش هوشمند source ID", [
                'config_id' => $this->configId,
                'execution_id' => $this->executionId,
                'source_id' => $this->sourceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // ثبت خطا در ExecutionLog
            $executionLog->addLogEntry("❌ خطای Job در source ID {$this->sourceId}", [
                'error' => $e->getMessage(),
                'job_attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
                'source_id' => $this->sourceId,
                'processing_mode' => 'intelligent'
            ]);

            // بروزرسانی آمار خطا
            $errorStats = [
                'total_processed' => 1,
                'total_success' => 0,
                'total_failed' => 1,
                'total_duplicate' => 0,
                'total_enhanced' => 0
            ];

            try {
                $executionLog->updateProgress($errorStats);
            } catch (\Exception $updateError) {
                Log::error("❌ خطا در بروزرسانی آمار خطا", [
                    'execution_id' => $this->executionId,
                    'update_error' => $updateError->getMessage()
                ]);
            }

            return ['action' => 'failed', 'stats' => $errorStats];
        }
    }

    /**
     * لاگ نتیجه تفصیلی
     */
    private function logDetailedResult(array $result): void
    {
        $action = $result['action'] ?? 'unknown';
        $stats = $result['stats'] ?? [];

        $actionDetails = [
            'created' => '🆕 کتاب جدید ایجاد شد',
            'enhanced' => '🔧 کتاب موجود بهبود یافت (فیلدهای خالی)',
            'enriched' => '💎 کتاب موجود غنی‌سازی شد (توضیحات بهتر)',
            'merged' => '🔗 کتاب موجود ادغام شد (نویسندگان/ISBN جدید)',
            'source_added' => '📌 منبع جدید به کتاب موجود اضافه شد',
            'already_processed' => '📋 Source قبلاً پردازش شده بود',
            'no_changes' => '⚪ کتاب موجود بدون تغییر',
            'failed' => '❌ پردازش ناموفق',
            'api_failed' => '🌐 خطای API',
            'no_book_found' => '📭 کتاب در API یافت نشد'
        ];

        $actionDescription = $actionDetails[$action] ?? "❓ عملیات نامشخص: {$action}";

        Log::info($actionDescription, [
            'source_id' => $this->sourceId,
            'config_id' => $this->configId,
            'action' => $action,
            'stats' => $stats,
            'book_id' => $result['book_id'] ?? null,
            'title' => isset($result['title']) ? substr($result['title'], 0, 50) . '...' : null
        ]);
    }

    /**
     * برنامه‌ریزی source ID بعدی در صورت نیاز
     */
    private function scheduleNextSourceIdIfNeeded(Config $config, ExecutionLog $executionLog, array $result): void
    {
        // بررسی وضعیت کانفیگ قبل از برنامه‌ریزی بعدی
        $config->refresh();
        if (!$config->is_running) {
            Log::info("⏹️ کانفیگ متوقف شده، source ID بعدی برنامه‌ریزی نمی‌شود", [
                'config_id' => $this->configId
            ]);
            return;
        }

        // بررسی محدودیت تعداد IDs
        $maxIds = $config->max_pages ?? 1000;
        $startId = $config->getSmartStartPage();
        $maxSourceId = $startId + $maxIds - 1;

        if ($this->sourceId >= $maxSourceId) {
            Log::info("📄 حداکثر source IDs پردازش شد", [
                'config_id' => $this->configId,
                'execution_id' => $this->executionId,
                'last_id' => $this->sourceId,
                'max_id' => $maxSourceId,
                'processing_completed' => true
            ]);

            // Job پایان اجرا را dispatch کن
            self::dispatch($this->configId, -1, $this->executionId)
                ->delay(now()->addSeconds(5));
            return;
        }

        // بررسی پیاپی ناموفق بودن زیاد
        $action = $result['action'] ?? 'unknown';
        if (in_array($action, ['max_retries_reached', 'api_failed', 'no_book_found'])) {
            $recentFailures = $this->countRecentFailures($config, $this->sourceId);

            if ($recentFailures >= 10) {
                Log::warning("⚠️ {$recentFailures} source ID پشت سر هم ناموفق، اجرا متوقف می‌شود", [
                    'config_id' => $this->configId,
                    'execution_id' => $this->executionId,
                    'last_source_id' => $this->sourceId,
                    'failure_type' => $action
                ]);

                // Job پایان اجرا را dispatch کن
                self::dispatch($this->configId, -1, $this->executionId)
                    ->delay(now()->addSeconds(5));
                return;
            }
        }

        // برنامه‌ریزی source ID بعدی
        $nextSourceId = $this->sourceId + 1;
        $delay = $config->delay_seconds ?? 3;

        self::dispatch($this->configId, $nextSourceId, $this->executionId)
            ->delay(now()->addSeconds($delay));

        Log::debug("📄 Source ID بعدی برنامه‌ریزی شد", [
            'config_id' => $this->configId,
            'execution_id' => $this->executionId,
            'current_source_id' => $this->sourceId,
            'next_source_id' => $nextSourceId,
            'delay_seconds' => $delay,
            'scheduling_mode' => 'intelligent'
        ]);
    }

    /**
     * شمارش شکست‌های اخیر
     */
    private function countRecentFailures(Config $config, int $currentSourceId): int
    {
        $failures = 0;

        // بررسی آخرین 10 source ID
        for ($i = 1; $i <= 10; $i++) {
            $checkId = $currentSourceId - $i;
            if ($checkId < 1) break;

            try {
                // بررسی در FailedRequest
                $hasFailure = \App\Models\FailedRequest::where('config_id', $config->id)
                    ->where('source_name', $config->source_name)
                    ->where('source_id', (string)$checkId)
                    ->where('is_resolved', false)
                    ->exists();

                if ($hasFailure) {
                    $failures++;
                } else {
                    break; // اگر یکی موفق بود، شمارش را بشکن
                }
            } catch (\Exception $e) {
                Log::error("❌ خطا در بررسی شکست‌های اخیر", [
                    'check_id' => $checkId,
                    'error' => $e->getMessage()
                ]);
                break;
            }
        }

        return $failures;
    }

    /**
     * مدیریت خطای Job
     */
    private function handleJobException(\Exception $e): void
    {
        Log::error("❌ خطا در ProcessSinglePageJob هوشمند", [
            'config_id' => $this->configId,
            'execution_id' => $this->executionId,
            'source_id' => $this->sourceId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'attempt' => $this->attempts(),
            'max_attempts' => $this->tries
        ]);

        // ثبت خطا در ExecutionLog
        try {
            $executionLog = ExecutionLog::where('execution_id', $this->executionId)->first();
            if ($executionLog) {
                $executionLog->addLogEntry("💥 خطای Job در source ID {$this->sourceId}", [
                    'error' => $e->getMessage(),
                    'job_attempt' => $this->attempts(),
                    'max_attempts' => $this->tries,
                    'source_id' => $this->sourceId,
                    'error_type' => get_class($e)
                ]);

                // بروزرسانی آمار خطا
                $executionLog->updateProgress([
                    'total_processed' => 1,
                    'total_success' => 0,
                    'total_failed' => 1,
                    'total_duplicate' => 0,
                    'total_enhanced' => 0
                ]);
            }
        } catch (\Exception $logError) {
            Log::error("❌ خطا در ثبت خطای Job", [
                'execution_id' => $this->executionId,
                'log_error' => $logError->getMessage()
            ]);
        }

        // اگر این آخرین تلاش است، اجرا را متوقف کن
        if ($this->attempts() >= $this->tries) {
            $this->stopExecutionOnFinalFailure($e);
        } else {
            // در غیر این صورت، Job را دوباره در صف قرار بده
            $this->release(30); // 30 ثانیه تاخیر
        }
    }

    /**
     * متوقف کردن اجرا در صورت خطای نهایی
     */
    private function stopExecutionOnFinalFailure(\Exception $e): void
    {
        try {
            Log::error("💥 اجرا به دلیل خطای مکرر متوقف می‌شود", [
                'config_id' => $this->configId,
                'execution_id' => $this->executionId,
                'source_id' => $this->sourceId,
                'error' => $e->getMessage()
            ]);

            // متوقف کردن کانفیگ
            $config = Config::find($this->configId);
            if ($config) {
                $config->update(['is_running' => false]);
            }

            // متوقف کردن ExecutionLog با خطا
            $executionLog = ExecutionLog::where('execution_id', $this->executionId)->first();
            if ($executionLog) {
                $finalStats = [
                    'stopped_manually' => false,
                    'stopped_due_to_error' => true,
                    'final_error' => $e->getMessage(),
                    'failed_source_id' => $this->sourceId,
                    'stopped_at' => now()->toISOString(),
                    'intelligent_processing' => true
                ];

                $executionLog->update([
                    'status' => ExecutionLog::STATUS_FAILED,
                    'error_message' => "خطای مکرر در source ID {$this->sourceId}: " . $e->getMessage(),
                    'stop_reason' => 'خطای مکرر در Job هوشمند',
                    'finished_at' => now(),
                    'execution_time' => $executionLog->started_at ? now()->diffInSeconds($executionLog->started_at) : 0
                ]);

                $executionLog->addLogEntry("💥 اجرای هوشمند به دلیل خطای مکرر متوقف شد", $finalStats);
            }

            // حذف Jobs باقی‌مانده
            $this->cleanupRemainingJobs();

        } catch (\Exception $stopError) {
            Log::error("❌ خطا در متوقف کردن اجرای هوشمند", [
                'config_id' => $this->configId,
                'execution_id' => $this->executionId,
                'stop_error' => $stopError->getMessage()
            ]);
        }
    }

    /**
     * حذف Jobs باقی‌مانده مرتبط با این اجرا
     */
    private function cleanupRemainingJobs(): void
    {
        try {
            $deletedJobs = DB::table('jobs')
                ->where('payload', 'like', '%"configId":' . $this->configId . '%')
                ->where('payload', 'like', '%"executionId":"' . $this->executionId . '"%')
                ->delete();

            if ($deletedJobs > 0) {
                Log::info("🗑️ {$deletedJobs} Job باقی‌مانده حذف شد", [
                    'config_id' => $this->configId,
                    'execution_id' => $this->executionId
                ]);
            }
        } catch (\Exception $e) {
            Log::error("❌ خطا در حذف Jobs باقی‌مانده", [
                'config_id' => $this->configId,
                'execution_id' => $this->executionId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * تعریف شناسه منحصر به فرد برای Job
     */
    public function uniqueId(): string
    {
        return "intelligent_process_{$this->configId}_{$this->executionId}_{$this->sourceId}";
    }

    /**
     * تعیین اینکه آیا Job باید منحصر به فرد باشد
     */
    public function shouldBeUnique(): bool
    {
        return true;
    }

    /**
     * مدت زمان نگهداری منحصر به فرد بودن (ثانیه)
     */
    public function uniqueFor(): int
    {
        return 300; // 5 دقیقه
    }

    /**
     * چه اتفاقی بیفتد اگر Job نتواند اجرا شود
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("💥 ProcessSinglePageJob هوشمند نهایتاً ناموفق شد", [
            'config_id' => $this->configId,
            'execution_id' => $this->executionId,
            'source_id' => $this->sourceId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'processing_mode' => 'intelligent_md5_based'
        ]);

        // تلاش برای ثبت خطا در ExecutionLog
        try {
            $executionLog = ExecutionLog::where('execution_id', $this->executionId)->first();
            if ($executionLog) {
                $executionLog->addLogEntry("💥 Job هوشمند نهایتاً ناموفق شد", [
                    'source_id' => $this->sourceId,
                    'error' => $exception->getMessage(),
                    'failed_at' => now()->toISOString(),
                    'processing_mode' => 'intelligent'
                ]);

                // بروزرسانی آمار خطا
                $executionLog->updateProgress([
                    'total_processed' => 1,
                    'total_success' => 0,
                    'total_failed' => 1,
                    'total_duplicate' => 0,
                    'total_enhanced' => 0
                ]);
            }
        } catch (\Exception $e) {
            Log::error("❌ خطا در ثبت failed log", [
                'execution_id' => $this->executionId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * تعیین تاخیر بین تلاش‌های مجدد
     */
    public function backoff(): array
    {
        return [30, 60]; // 30 ثانیه، سپس 60 ثانیه
    }
}
