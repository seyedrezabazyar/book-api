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
    protected int $sourceId; // تغییر نام از pageNumber به sourceId

    public function __construct($config, int $sourceId, string $executionId)
    {
        // اگر Config object باشد، ID را استخراج کن
        $this->configId = is_object($config) ? $config->id : (int)$config;
        $this->executionId = $executionId;
        $this->sourceId = $sourceId; // حالا source ID است نه page number

        // تنظیم صف بر اساس اولویت
        $this->onQueue('default');
    }

    public function handle(): void
    {
        try {
            Log::info("🚀 شروع ProcessSinglePageJob برای source ID", [
                'config_id' => $this->configId,
                'execution_id' => $this->executionId,
                'source_id' => $this->sourceId,
                'job_id' => $this->job?->getJobId()
            ]);

            // 🔥 بررسی اولیه
            $config = Config::find($this->configId);
            if (!$config) {
                Log::error("❌ کانفیگ {$this->configId} یافت نشد");
                $this->delete();
                return;
            }

            // 🔥 اگر کانفیگ در حال اجرا نیست، Job را متوقف کن
            if (!$config->is_running) {
                Log::info("⏹️ کانفیگ {$this->configId} متوقف شده، Job لغو می‌شود", [
                    'execution_id' => $this->executionId,
                    'source_id' => $this->sourceId
                ]);
                $this->delete();
                return;
            }

            // دریافت ExecutionLog
            $executionLog = ExecutionLog::where('execution_id', $this->executionId)->first();
            if (!$executionLog) {
                Log::error("❌ ExecutionLog با شناسه {$this->executionId} یافت نشد");
                $this->delete();
                return;
            }

            // 🔥 بررسی وضعیت ExecutionLog
            if ($executionLog->status !== 'running') {
                Log::info("⏹️ ExecutionLog {$this->executionId} دیگر running نیست، Job متوقف می‌شود", [
                    'status' => $executionLog->status,
                    'source_id' => $this->sourceId
                ]);
                $this->delete();
                return;
            }

            // 🔥 بررسی دوباره قبل از پردازش
            $config->refresh();
            if (!$config->is_running) {
                Log::info("⏹️ Double Check: کانفیگ {$this->configId} متوقف شده، Job لغو می‌شود");
                $this->delete();
                return;
            }

            // ایجاد service و پردازش source ID
            $apiService = new ApiDataService($config);
            $result = $apiService->processSourceId($this->sourceId, $executionLog);

            // 🔥 بررسی نهایی قبل از ثبت نتایج
            $config->refresh();
            if (!$config->is_running) {
                Log::info("⏹️ کانفیگ حین پردازش متوقف شد، نتایج ثبت نمی‌شود");
                $this->delete();
                return;
            }

            Log::info("✅ ProcessSinglePageJob تمام شد", [
                'config_id' => $this->configId,
                'execution_id' => $this->executionId,
                'source_id' => $this->sourceId,
                'result' => $result
            ]);

            // برنامه‌ریزی source ID بعدی (اگر لازم باشد)
            $this->scheduleNextSourceIdIfNeeded($config, $executionLog, $result);
        } catch (\Exception $e) {
            Log::error("❌ خطا در ProcessSinglePageJob", [
                'config_id' => $this->configId,
                'execution_id' => $this->executionId,
                'source_id' => $this->sourceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // ثبت خطا در ExecutionLog
            $executionLog = ExecutionLog::where('execution_id', $this->executionId)->first();
            if ($executionLog) {
                $executionLog->addLogEntry("❌ خطای Job در source ID {$this->sourceId}", [
                    'error' => $e->getMessage(),
                    'job_attempt' => $this->attempts(),
                    'max_attempts' => $this->tries
                ]);
            }

            // اگر این آخرین تلاش است، اجرا را متوقف کن
            if ($this->attempts() >= $this->tries) {
                $this->stopExecutionOnFinalFailure($executionLog, $e);
            } else {
                // در غیر این صورت، Job را دوباره در صف قرار بده
                $this->release(30); // 30 ثانیه تاخیر
            }
        }
    }

    /**
     * برنامه‌ریزی source ID بعدی در صورت نیاز
     */
    private function scheduleNextSourceIdIfNeeded(Config $config, ExecutionLog $executionLog, array $result): void
    {
        // 🔥 بررسی وضعیت کانفیگ قبل از برنامه‌ریزی بعدی
        $config->refresh();
        if (!$config->is_running) {
            Log::info("⏹️ کانفیگ متوقف شده، source ID بعدی برنامه‌ریزی نمی‌شود");
            return;
        }

        // اگر این source ID موجود نبود، Job پایان اجرا را dispatch کن
        if (isset($result['action']) && $result['action'] === 'no_book_found') {
            // چند source ID پشت سر هم خالی بود؟
            $recentFailures = $this->countRecentFailures($config, $this->sourceId);

            if ($recentFailures >= 5) {
                Log::info("📄 {$recentFailures} source ID پشت سر هم خالی بود، اجرا تمام می‌شود", [
                    'config_id' => $this->configId,
                    'execution_id' => $this->executionId,
                    'last_source_id' => $this->sourceId
                ]);

                // Job پایان اجرا را dispatch کن
                ProcessSinglePageJob::dispatch($this->configId, -1, $this->executionId)
                    ->delay(now()->addSeconds(5));
                return;
            }
        }

        // بررسی محدودیت تعداد IDs
        $maxIds = $config->max_pages ?? 1000;
        $startId = $config->getSmartStartPage();
        $maxSourceId = $startId + $maxIds - 1;

        if ($this->sourceId >= $maxSourceId) {
            Log::info("📄 حداکثر source IDs ({$maxIds}) پردازش شد", [
                'config_id' => $this->configId,
                'execution_id' => $this->executionId,
                'last_id' => $this->sourceId,
                'max_id' => $maxSourceId
            ]);

            // Job پایان اجرا را dispatch کن
            ProcessSinglePageJob::dispatch($this->configId, -1, $this->executionId)
                ->delay(now()->addSeconds(5));
            return;
        }

        // برنامه‌ریزی source ID بعدی
        $nextSourceId = $this->sourceId + 1;
        $delay = $config->delay_seconds ?? 3;

        ProcessSinglePageJob::dispatch($this->configId, $nextSourceId, $this->executionId)
            ->delay(now()->addSeconds($delay));

        Log::info("📄 Source ID بعدی برنامه‌ریزی شد", [
            'config_id' => $this->configId,
            'execution_id' => $this->executionId,
            'current_source_id' => $this->sourceId,
            'next_source_id' => $nextSourceId,
            'delay' => $delay
        ]);
    }

    /**
     * شمارش شکست‌های اخیر
     */
    private function countRecentFailures(Config $config, int $currentSourceId): int
    {
        $failures = 0;
        for ($i = 1; $i <= 10; $i++) {
            $checkId = $currentSourceId - $i;
            if ($checkId < 1) break;

            $hasFailure = \App\Models\ScrapingFailure::where('config_id', $config->id)
                ->where('error_details->source_id', $checkId)
                ->exists();

            if ($hasFailure) {
                $failures++;
            } else {
                break; // اگر یکی موفق بود، شمارش را بشکن
            }
        }

        return $failures;
    }

    /**
     * متوقف کردن اجرا در صورت خطای نهایی
     */
    private function stopExecutionOnFinalFailure(?ExecutionLog $executionLog, \Exception $e): void
    {
        if (!$executionLog) {
            return;
        }

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
        $finalStats = [
            'total_processed_at_stop' => $config ? $config->total_processed : 0,
            'total_success_at_stop' => $config ? $config->total_success : 0,
            'total_failed_at_stop' => $config ? $config->total_failed : 0,
            'stopped_manually' => false,
            'stopped_due_to_error' => true,
            'final_error' => $e->getMessage(),
            'failed_source_id' => $this->sourceId,
            'stopped_at' => now()->toISOString()
        ];

        $executionLog->update([
            'status' => ExecutionLog::STATUS_FAILED,
            'error_message' => "خطای مکرر در source ID {$this->sourceId}: " . $e->getMessage(),
            'stop_reason' => 'خطای مکرر در Job',
            'finished_at' => now(),
            'execution_time' => $executionLog->started_at ? now()->diffInSeconds($executionLog->started_at) : 0
        ]);

        $executionLog->addLogEntry("💥 اجرا به دلیل خطای مکرر متوقف شد", $finalStats);

        // حذف Jobs باقی‌مانده
        $this->cleanupRemainingJobs();
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
        return "process_source_{$this->configId}_{$this->executionId}_{$this->sourceId}";
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
        Log::error("💥 ProcessSinglePageJob نهایتاً ناموفق شد", [
            'config_id' => $this->configId,
            'execution_id' => $this->executionId,
            'source_id' => $this->sourceId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // تلاش برای ثبت خطا در ExecutionLog
        try {
            $executionLog = ExecutionLog::where('execution_id', $this->executionId)->first();
            if ($executionLog) {
                $executionLog->addLogEntry("💥 Job نهایتاً ناموفق شد", [
                    'source_id' => $this->sourceId,
                    'error' => $exception->getMessage(),
                    'failed_at' => now()->toISOString()
                ]);
            }
        } catch (\Exception $e) {
            Log::error("❌ خطا در ثبت failed log", ['error' => $e->getMessage()]);
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
