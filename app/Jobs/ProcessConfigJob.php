<?php

namespace App\Jobs;

use App\Models\Config;
use App\Models\ScrapingFailure;
use App\Services\ApiDataService;
use App\Services\CrawlerDataService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Job بهبود شده برای اجرای کانفیگ‌ها با مدیریت بهتر خطا
 */
class ProcessConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 دقیقه
    public $tries = 2; // حداکثر 2 تلاش
    public $maxExceptions = 2;
    public $backoff = [60, 300]; // تاخیر 1 دقیقه، سپس 5 دقیقه

    private Config $config;
    private bool $force;

    public function __construct(Config $config, bool $force = false)
    {
        $this->config = $config;
        $this->force = $force;

        Log::info("🚀 ProcessConfigJob ایجاد شد", [
            'config_id' => $this->config->id,
            'config_name' => $this->config->name,
            'job_version' => 'ENHANCED_V2.0',
            'force' => $this->force
        ]);
    }

    /**
     * اجرای job
     */
    public function handle(): void
    {
        Log::info("⚡ شروع handle ProcessConfigJob", [
            'config_id' => $this->config->id,
            'config_name' => $this->config->name,
            'attempt' => $this->attempts(),
            'force' => $this->force
        ]);

        // بررسی امکان ادامه
        $freshConfig = $this->config->fresh();
        if (!$this->shouldProcess($freshConfig)) {
            Log::info("❌ کانفیگ قابل اجرا نیست", [
                'config_id' => $this->config->id,
                'status' => $freshConfig->status,
                'is_running' => $freshConfig->is_running,
                'force' => $this->force
            ]);
            return;
        }

        // تنظیم قفل برای جلوگیری از اجرای همزمان
        $lockKey = "config_processing_{$this->config->id}";
        $lockDuration = 600; // 10 دقیقه

        if (Cache::has($lockKey) && !$this->force) {
            Log::info("🔒 کانفیگ در حال پردازش", [
                'config_id' => $this->config->id,
                'lock_key' => $lockKey
            ]);
            return;
        }

        Cache::put($lockKey, [
            'started_at' => now()->toDateTimeString(),
            'job_id' => $this->job->getJobId() ?? 'unknown',
            'attempt' => $this->attempts()
        ], $lockDuration);

        try {
            // شروع پردازش
            if (!$freshConfig->isRunning()) {
                $freshConfig->start();
                Log::info("▶️ کانفیگ شروع شد", ['config_id' => $this->config->id]);
            }

            // انتخاب سرویس و پردازش
            $service = $this->getAppropriateService($freshConfig);
            $stats = $this->processWithService($service, $freshConfig);

            // ذخیره آمار موفقیت‌آمیز
            $this->saveSuccessfulStats($freshConfig, $stats);

            // برنامه‌ریزی اجرای بعدی فقط اگر کانفیگ هنوز فعال باشد
            $this->scheduleNextRunIfNeeded($freshConfig);

            Log::info("✅ پایان موفق ProcessConfigJob", [
                'config_id' => $this->config->id,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error("💥 خطا در ProcessConfigJob", [
                'config_id' => $this->config->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->handleProcessingError($e, $freshConfig ?? $this->config);

            // در صورت خطا، اگر تلاش‌های بیشتری باقی مانده، job را دوباره پرتاب کن
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        } finally {
            Cache::forget($lockKey);
            Log::info("🔓 قفل کانفیگ برداشته شد", ['config_id' => $this->config->id]);
        }
    }

    /**
     * بررسی اینکه آیا job باید اجرا شود یا نه
     */
    private function shouldProcess(Config $config): bool
    {
        if ($this->force) {
            return true;
        }

        if (!$config->isActive()) {
            return false;
        }

        // اگر کانفیگ در حال اجرا است یا می‌تواند شروع شود
        return $config->isRunning() || $config->canStart();
    }

    /**
     * انتخاب سرویس مناسب
     */
    private function getAppropriateService(Config $config)
    {
        if ($config->isApiSource()) {
            Log::info("📡 استفاده از ApiDataService", ['config_id' => $config->id]);
            return new ApiDataService($config);
        } elseif ($config->isCrawlerSource()) {
            Log::info("🕷️ استفاده از CrawlerDataService", ['config_id' => $config->id]);
            return new CrawlerDataService($config);
        } else {
            throw new \InvalidArgumentException("نوع کانفیگ پشتیبانی نشده: {$config->data_source_type}");
        }
    }

    /**
     * پردازش با سرویس
     */
    private function processWithService($service, Config $config): array
    {
        $startTime = microtime(true);

        try {
            Log::info("🔄 شروع پردازش با سرویس", [
                'config_id' => $config->id,
                'service_class' => get_class($service),
                'records_per_run' => $config->records_per_run
            ]);

            // اجرای سرویس
            if ($config->isApiSource()) {
                $stats = $service->fetchData();
            } else {
                $stats = $service->crawlData();
            }

            $executionTime = round(microtime(true) - $startTime, 2);
            $stats['execution_time'] = $executionTime;
            $stats['last_run'] = now()->toDateTimeString();

            Log::info("📊 آمار پردازش job", [
                'config_id' => $config->id,
                'stats' => $stats,
                'execution_time' => $executionTime
            ]);

            return $stats;

        } catch (\Exception $e) {
            Log::error("💥 خطا در پردازش سرویس", [
                'config_id' => $config->id,
                'service_class' => get_class($service),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw $e;
        }
    }

    /**
     * ذخیره آمار موفقیت‌آمیز
     */
    private function saveSuccessfulStats(Config $config, array $stats): void
    {
        try {
            // ذخیره آمار در cache
            Cache::put("config_stats_{$config->id}", $stats, 3600);

            // پاک کردن خطاهای قبلی از cache
            Cache::forget("config_error_{$config->id}");

            // بارگذاری مجدد کانفیگ از دیتابیس برای اطمینان از آخرین مقادیر
            $config->refresh();

            $oldProcessed = $config->total_processed;
            $oldSuccess = $config->total_success;
            $oldFailed = $config->total_failed;

            // به‌روزرسانی آمار کانفیگ
            $config->update([
                'total_processed' => $oldProcessed + $stats['total'],
                'total_success' => $oldSuccess + $stats['success'],
                'total_failed' => $oldFailed + $stats['failed'],
                'last_run_at' => now()
            ]);

            Log::info("💾 آمار کانفیگ به‌روزرسانی شد از Job", [
                'config_id' => $config->id,
                'old_processed' => $oldProcessed,
                'new_processed' => $oldProcessed + $stats['total'],
                'old_success' => $oldSuccess,
                'new_success' => $oldSuccess + $stats['success'],
                'old_failed' => $oldFailed,
                'new_failed' => $oldFailed + $stats['failed'],
                'current_run_stats' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error("❌ خطا در ذخیره آمار Job", [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    /**
     * برنامه‌ریزی اجرای بعدی
     */
    private function scheduleNextRunIfNeeded(Config $config): void
    {
        $freshConfig = $config->fresh();

        // فقط در صورتی که کانفیگ هنوز فعال و در حال اجرا باشد
        if ($freshConfig->isRunning() && $freshConfig->isActive()) {
            $nextRunDelay = max($config->delay_seconds, 5); // حداقل 5 ثانیه تاخیر

            Log::info("⏰ برنامه‌ریزی اجرای بعدی", [
                'config_id' => $config->id,
                'delay_seconds' => $nextRunDelay
            ]);

            // اضافه کردن job جدید با تاخیر
            static::dispatch($config, $this->force)
                ->delay(now()->addSeconds($nextRunDelay));
        } else {
            Log::info("⏹️ عدم برنامه‌ریزی اجرای بعدی", [
                'config_id' => $config->id,
                'is_running' => $freshConfig->isRunning(),
                'is_active' => $freshConfig->isActive()
            ]);
        }
    }

    /**
     * مدیریت خطاهای پردازش
     */
    private function handleProcessingError(\Exception $e, Config $config): void
    {
        // ثبت شکست در دیتابیس
        try {
            ScrapingFailure::logFailure(
                $config->id,
                $config->current_url ?? $config->base_url,
                $e->getMessage(),
                [
                    'line' => $e->getLine(),
                    'file' => basename($e->getFile()),
                    'class' => get_class($e),
                    'job_class' => static::class,
                    'attempt' => $this->attempts(),
                    'trace' => $e->getTraceAsString()
                ]
            );
        } catch (\Exception $logError) {
            Log::error("خطا در ثبت failure", [
                'config_id' => $config->id,
                'log_error' => $logError->getMessage()
            ]);
        }

        // ذخیره خطا در cache
        Cache::put("config_error_{$config->id}", [
            'message' => $e->getMessage(),
            'time' => now()->toDateTimeString(),
            'attempt' => $this->attempts(),
            'details' => [
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
                'class' => get_class($e)
            ]
        ], 86400);

        // به‌روزرسانی آمار خطا
        try {
            $config->increment('total_failed');
        } catch (\Exception $updateError) {
            Log::error("خطا در به‌روزرسانی آمار خطا", [
                'config_id' => $config->id,
                'error' => $updateError->getMessage()
            ]);
        }

        // متوقف کردن کانفیگ در صورت خطای جدی یا تلاش نهایی
        if ($this->shouldStopOnError($e) || $this->attempts() >= $this->tries) {
            try {
                $config->stop();
                Log::warning("⏹️ کانفیگ به دلیل خطای جدی یا پایان تلاش‌ها متوقف شد", [
                    'config_id' => $config->id,
                    'error' => $e->getMessage(),
                    'attempts' => $this->attempts(),
                    'max_tries' => $this->tries
                ]);
            } catch (\Exception $stopError) {
                Log::error("خطا در متوقف کردن کانفیگ", [
                    'config_id' => $config->id,
                    'error' => $stopError->getMessage()
                ]);
            }
        }
    }

    /**
     * تعیین متوقف کردن در صورت خطا
     */
    private function shouldStopOnError(\Exception $e): bool
    {
        $criticalErrors = [
            \InvalidArgumentException::class,
            \BadMethodCallException::class,
            \TypeError::class,
        ];

        foreach ($criticalErrors as $errorClass) {
            if ($e instanceof $errorClass) {
                return true;
            }
        }

        // بررسی تعداد خطاهای اخیر
        try {
            $recentFailures = ScrapingFailure::where('config_id', $this->config->id)
                ->where('created_at', '>=', now()->subHour())
                ->count();

            return $recentFailures > 10; // اگر در یک ساعت بیش از 10 خطا داشته
        } catch (\Exception $checkError) {
            Log::error("خطا در بررسی تعداد failures", [
                'config_id' => $this->config->id,
                'error' => $checkError->getMessage()
            ]);
            return false;
        }
    }

    /**
     * مدیریت شکست کامل job
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("💥 شکست کامل ProcessConfigJob", [
            'config_id' => $this->config->id,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine()
        ]);

        try {
            // متوقف کردن کانفیگ
            $freshConfig = $this->config->fresh();
            if ($freshConfig && $freshConfig->isRunning()) {
                $freshConfig->stop();
            }

            // ثبت شکست نهایی
            ScrapingFailure::logFailure(
                $this->config->id,
                $this->config->current_url ?? $this->config->base_url,
                'شکست کامل Job بعد از ' . $this->attempts() . ' تلاش: ' . $exception->getMessage(),
                [
                    'job_class' => static::class,
                    'file' => basename($exception->getFile()),
                    'line' => $exception->getLine(),
                    'attempts' => $this->attempts(),
                    'trace' => $exception->getTraceAsString()
                ]
            );

            // ذخیره خطای نهایی در cache
            Cache::put("config_error_{$this->config->id}", [
                'message' => 'شکست کامل: ' . $exception->getMessage(),
                'time' => now()->toDateTimeString(),
                'final_failure' => true,
                'attempts' => $this->attempts()
            ], 86400);

        } catch (\Exception $cleanupError) {
            Log::error("خطا در cleanup بعد از failed job", [
                'config_id' => $this->config->id,
                'cleanup_error' => $cleanupError->getMessage()
            ]);
        }
    }

    /**
     * تگ‌ها برای monitoring
     */
    public function tags(): array
    {
        return [
            'config:' . $this->config->id,
            'type:' . $this->config->data_source_type,
            'version:enhanced',
            'attempt:' . $this->attempts()
        ];
    }

    /**
     * محاسبه تاخیر بین تلاش‌ها
     */
    public function backoff(): array
    {
        return $this->backoff;
    }
}
