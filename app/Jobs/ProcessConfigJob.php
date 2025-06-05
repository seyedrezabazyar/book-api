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
 * Job تعمیر شده برای اجرای کانفیگ‌ها از صفحه وب
 */
class ProcessConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 دقیقه
    public $tries = 1; // فقط یک بار
    public $maxExceptions = 1;

    private Config $config;
    private bool $force;

    public function __construct(Config $config, bool $force = false)
    {
        $this->config = $config;
        $this->force = $force;

        Log::info("🚀 ProcessConfigJob ایجاد شد", [
            'config_id' => $this->config->id,
            'config_name' => $this->config->name,
            'job_version' => 'FIXED_V1.0'
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
            'force' => $this->force
        ]);

        // بررسی امکان ادامه
        $freshConfig = $this->config->fresh();
        if (!$this->force && !$freshConfig->canStart() && !$freshConfig->isRunning()) {
            Log::info("❌ کانفیگ غیرقابل اجرا", [
                'config_id' => $this->config->id,
                'can_start' => $freshConfig->canStart(),
                'is_running' => $freshConfig->isRunning()
            ]);
            return;
        }

        // تنظیم قفل برای جلوگیری از اجرای همزمان
        $lockKey = "config_processing_{$this->config->id}";
        $lockDuration = 300; // 5 دقیقه

        if (Cache::has($lockKey) && !$this->force) {
            Log::info("🔒 کانفیگ در حال پردازش", [
                'config_id' => $this->config->id,
                'lock_key' => $lockKey
            ]);
            return;
        }

        Cache::put($lockKey, now()->toDateTimeString(), $lockDuration);

        try {
            // شروع پردازش
            if (!$freshConfig->isRunning()) {
                $this->config->start();
                Log::info("▶️ کانفیگ شروع شد", ['config_id' => $this->config->id]);
            }

            // انتخاب سرویس و پردازش
            $service = $this->getAppropriateService();
            $this->processWithService($service);

            // برنامه‌ریزی اجرای بعدی فقط اگر کانفیگ هنوز فعال باشد
            $this->scheduleNextRunIfNeeded();

            Log::info("✅ پایان موفق ProcessConfigJob", [
                'config_id' => $this->config->id
            ]);

        } catch (\Exception $e) {
            Log::error("💥 خطا در ProcessConfigJob", [
                'config_id' => $this->config->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $this->handleProcessingError($e);
        } finally {
            Cache::forget($lockKey);
            Log::info("🔓 قفل کانفیگ برداشته شد", ['config_id' => $this->config->id]);
        }
    }

    /**
     * انتخاب سرویس مناسب
     */
    private function getAppropriateService()
    {
        if ($this->config->isApiSource()) {
            Log::info("📡 استفاده از ApiDataService", ['config_id' => $this->config->id]);
            return new ApiDataService($this->config);
        } elseif ($this->config->isCrawlerSource()) {
            Log::info("🕷️ استفاده از CrawlerDataService", ['config_id' => $this->config->id]);
            return new CrawlerDataService($this->config);
        } else {
            throw new \InvalidArgumentException("نوع کانفیگ پشتیبانی نشده: {$this->config->data_source_type}");
        }
    }

    /**
     * پردازش با سرویس
     */
    private function processWithService($service): void
    {
        $startTime = microtime(true);

        try {
            Log::info("🔄 شروع پردازش با سرویس", [
                'config_id' => $this->config->id,
                'service_class' => get_class($service)
            ]);

            // اجرای سرویس
            if ($this->config->isApiSource()) {
                $stats = $service->fetchData();
            } else {
                $stats = $service->crawlData();
            }

            $executionTime = round(microtime(true) - $startTime, 2);
            $stats['execution_time'] = $executionTime;
            $stats['last_run'] = now()->toDateTimeString();

            Log::info("📊 آمار پردازش job", [
                'config_id' => $this->config->id,
                'stats' => $stats,
                'execution_time' => $executionTime
            ]);

            // ذخیره آمار در cache
            Cache::put("config_stats_{$this->config->id}", $stats, 3600);

            // به‌روزرسانی آمار کانفیگ
            $this->config->update([
                'total_processed' => $this->config->total_processed + $stats['total'],
                'total_success' => $this->config->total_success + $stats['success'],
                'total_failed' => $this->config->total_failed + $stats['failed'],
                'last_run_at' => now()
            ]);

            Log::info("💾 آمار کانفیگ به‌روزرسانی شد", [
                'config_id' => $this->config->id,
                'total_processed' => $this->config->total_processed + $stats['total'],
                'total_success' => $this->config->total_success + $stats['success']
            ]);

        } catch (\Exception $e) {
            Log::error("💥 خطا در پردازش سرویس", [
                'config_id' => $this->config->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw $e;
        }
    }

    /**
     * برنامه‌ریزی اجرای بعدی
     */
    private function scheduleNextRunIfNeeded(): void
    {
        $freshConfig = $this->config->fresh();

        // فقط در صورتی که کانفیگ هنوز فعال و در حال اجرا باشد
        if ($freshConfig->isRunning() && $freshConfig->isActive()) {
            $nextRunDelay = $this->config->delay_seconds;

            Log::info("⏰ برنامه‌ریزی اجرای بعدی", [
                'config_id' => $this->config->id,
                'delay_seconds' => $nextRunDelay
            ]);

            // اضافه کردن job جدید با تاخیر
            static::dispatch($this->config, $this->force)
                ->delay(now()->addSeconds($nextRunDelay));
        } else {
            Log::info("⏹️ عدم برنامه‌ریزی اجرای بعدی", [
                'config_id' => $this->config->id,
                'is_running' => $freshConfig->isRunning(),
                'is_active' => $freshConfig->isActive()
            ]);
        }
    }

    /**
     * مدیریت خطاهای پردازش
     */
    private function handleProcessingError(\Exception $e): void
    {
        // ثبت شکست
        ScrapingFailure::logFailure(
            $this->config->id,
            $this->config->current_url ?? $this->config->base_url,
            $e->getMessage(),
            [
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
                'class' => get_class($e),
                'job_class' => static::class
            ]
        );

        // ذخیره خطا در cache
        Cache::put("config_error_{$this->config->id}", [
            'message' => $e->getMessage(),
            'time' => now()->toDateTimeString(),
            'details' => [
                'line' => $e->getLine(),
                'file' => basename($e->getFile())
            ]
        ], 86400);

        // به‌روزرسانی آمار خطا
        $this->config->increment('total_failed');

        // متوقف کردن کانفیگ در صورت خطای جدی
        if ($this->shouldStopOnError($e)) {
            $this->config->stop();
            Log::warning("⏹️ کانفیگ به دلیل خطای جدی متوقف شد", [
                'config_id' => $this->config->id,
                'error' => $e->getMessage()
            ]);
        } else {
            // تلاش مجدد با تاخیر بیشتر
            $retryDelay = min($this->config->delay_seconds * 2, 300);

            Log::info("🔄 برنامه‌ریزی تلاش مجدد", [
                'config_id' => $this->config->id,
                'retry_delay' => $retryDelay
            ]);

            static::dispatch($this->config, $this->force)
                ->delay(now()->addSeconds($retryDelay));
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
        ];

        foreach ($criticalErrors as $errorClass) {
            if ($e instanceof $errorClass) {
                return true;
            }
        }

        // بررسی تعداد خطاهای اخیر
        $recentFailures = ScrapingFailure::where('config_id', $this->config->id)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        return $recentFailures > 5;
    }

    /**
     * مدیریت شکست job
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("💥 شکست کامل ProcessConfigJob", [
            'config_id' => $this->config->id,
            'error' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine()
        ]);

        $this->config->stop();

        ScrapingFailure::logFailure(
            $this->config->id,
            $this->config->current_url ?? $this->config->base_url,
            'شکست کامل Job: ' . $exception->getMessage(),
            [
                'job_class' => static::class,
                'file' => basename($exception->getFile()),
                'line' => $exception->getLine()
            ]
        );
    }

    /**
     * تگ‌ها برای monitoring
     */
    public function tags(): array
    {
        return [
            'config:' . $this->config->id,
            'type:' . $this->config->data_source_type,
            'version:fixed'
        ];
    }
}
