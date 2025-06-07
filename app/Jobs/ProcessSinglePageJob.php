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
    protected int $pageNumber;

    public function __construct($config, int $pageNumber, string $executionId)
    {
        // اگر Config object باشد، ID را استخراج کن
        $this->configId = is_object($config) ? $config->id : (int)$config;
        $this->executionId = $executionId;
        $this->pageNumber = $pageNumber;

        // تنظیم صف بر اساس اولویت
        $this->onQueue('default');
    }

    public function handle(): void
    {
        try {
            Log::info("🚀 شروع ProcessSinglePageJob", [
                'config_id' => $this->configId,
                'execution_id' => $this->executionId,
                'page' => $this->pageNumber,
                'job_id' => $this->job?->getJobId()
            ]);

            // دریافت کانفیگ و بررسی وضعیت
            $config = Config::find($this->configId);
            if (!$config) {
                Log::error("❌ کانفیگ {$this->configId} یافت نشد");
                return;
            }

            // بررسی اینکه آیا کانفیگ هنوز در حال اجرا است
            if (!$config->is_running) {
                Log::info("⏹️ کانفیگ {$this->configId} دیگر در حال اجرا نیست، Job متوقف می‌شود", [
                    'execution_id' => $this->executionId,
                    'page' => $this->pageNumber
                ]);
                return;
            }

            // دریافت ExecutionLog
            $executionLog = ExecutionLog::where('execution_id', $this->executionId)->first();
            if (!$executionLog) {
                Log::error("❌ ExecutionLog با شناسه {$this->executionId} یافت نشد");
                return;
            }

            // بررسی وضعیت ExecutionLog
            if ($executionLog->status !== 'running') {
                Log::info("⏹️ ExecutionLog {$this->executionId} دیگر running نیست، Job متوقف می‌شود", [
                    'status' => $executionLog->status,
                    'page' => $this->pageNumber
                ]);
                return;
            }

            // بررسی تکراری نبودن پردازش همین صفحه
            $this->checkDuplicateProcessing();

            // ایجاد service و پردازش صفحه
            $apiService = new ApiDataService($config);
            $result = $apiService->processPage($this->pageNumber, $executionLog);

            Log::info("✅ ProcessSinglePageJob تمام شد", [
                'config_id' => $this->configId,
                'execution_id' => $this->executionId,
                'page' => $this->pageNumber,
                'result' => $result
            ]);

            // برنامه‌ریزی صفحه بعدی (اگر لازم باشد)
            $this->scheduleNextPageIfNeeded($config, $executionLog, $result);

        } catch (\Exception $e) {
            Log::error("❌ خطا در ProcessSinglePageJob", [
                'config_id' => $this->configId,
                'execution_id' => $this->executionId,
                'page' => $this->pageNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // ثبت خطا در ExecutionLog
            $executionLog = ExecutionLog::where('execution_id', $this->executionId)->first();
            if ($executionLog) {
                $executionLog->addLogEntry("❌ خطای Job در صفحه {$this->pageNumber}", [
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
     * بررسی تکراری نبودن پردازش
     */
    private function checkDuplicateProcessing(): void
    {
        // بررسی اینکه آیا Job مشابهی در صف وجود دارد
        $duplicateJobs = DB::table('jobs')
            ->where('payload', 'like', '%"configId":' . $this->configId . '%')
            ->where('payload', 'like', '%"pageNumber":' . $this->pageNumber . '%')
            ->where('payload', 'like', '%"executionId":"' . $this->executionId . '"%')
            ->count();

        if ($duplicateJobs > 1) {
            Log::warning("⚠️ Job تکراری شناسایی شد", [
                'config_id' => $this->configId,
                'execution_id' => $this->executionId,
                'page' => $this->pageNumber,
                'duplicate_count' => $duplicateJobs
            ]);
        }
    }

    /**
     * برنامه‌ریزی صفحه بعدی در صورت نیاز
     */
    private function scheduleNextPageIfNeeded(Config $config, ExecutionLog $executionLog, array $result): void
    {
        // اگر داده‌ای در این صفحه نبود، اجرا را تمام کن
        if (isset($result['action']) && $result['action'] === 'no_more_data') {
            Log::info("📄 صفحه {$this->pageNumber} خالی بود، اجرا تمام می‌شود", [
                'config_id' => $this->configId,
                'execution_id' => $this->executionId
            ]);

            // Job پایان اجرا را dispatch کن - اصلاح شده
            ProcessSinglePageJob::dispatch($this->configId, -1, $this->executionId)
                ->delay(now()->addSeconds(5));
            return;
        }

        // بررسی محدودیت تعداد صفحات
        $maxPages = $config->max_pages ?? 999999;
        if ($this->pageNumber >= $maxPages) {
            Log::info("📄 حداکثر صفحات ({$maxPages}) پردازش شد", [
                'config_id' => $this->configId,
                'execution_id' => $this->executionId
            ]);

            // Job پایان اجرا را dispatch کن - اصلاح شده
            ProcessSinglePageJob::dispatch($this->configId, -1, $this->executionId)
                ->delay(now()->addSeconds(5));
            return;
        }

        // برنامه‌ریزی صفحه بعدی
        $nextPage = $this->pageNumber + 1;
        $delay = $config->delay_seconds ?? 3;

        // اصلاح شده: ارسال ID به جای object
        ProcessSinglePageJob::dispatch($this->configId, $nextPage, $this->executionId)
            ->delay(now()->addSeconds($delay));

        Log::info("📄 صفحه بعدی برنامه‌ریزی شد", [
            'config_id' => $this->configId,
            'execution_id' => $this->executionId,
            'current_page' => $this->pageNumber,
            'next_page' => $nextPage,
            'delay' => $delay
        ]);
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
            'page' => $this->pageNumber,
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
            'failed_page' => $this->pageNumber,
            'stopped_at' => now()->toISOString()
        ];

        $executionLog->update([
            'status' => ExecutionLog::STATUS_FAILED,
            'error_message' => "خطای مکرر در صفحه {$this->pageNumber}: " . $e->getMessage(),
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
        return "process_page_{$this->configId}_{$this->executionId}_{$this->pageNumber}";
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
            'page' => $this->pageNumber,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // تلاش برای ثبت خطا در ExecutionLog
        try {
            $executionLog = ExecutionLog::where('execution_id', $this->executionId)->first();
            if ($executionLog) {
                $executionLog->addLogEntry("💥 Job نهایتاً ناموفق شد", [
                    'page' => $this->pageNumber,
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
