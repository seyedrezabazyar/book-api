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
 * Job پردازش کانفیگ‌ها
 */
class ProcessConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 دقیقه
    public $tries = 1; // فقط یک بار تلاش
    public $maxExceptions = 1;

    private Config $config;
    private bool $force;

    /**
     * ایجاد instance جدید از job
     */
    public function __construct(Config $config, bool $force = false)
    {
        $this->config = $config;
        $this->force = $force;
    }

    /**
     * اجرای job
     */
    public function handle(): void
    {
        Log::info("شروع پردازش کانفیگ", [
            'config_name' => $this->config->name,
            'config_id' => $this->config->id,
            'force' => $this->force
        ]);

        // بررسی امکان ادامه
        $freshConfig = $this->config->fresh();
        if (!$this->force && (!$freshConfig->canStart() && !$freshConfig->isRunning())) {
            Log::info("کانفیگ متوقف شده یا غیرفعال", [
                'config_name' => $this->config->name,
                'status' => $freshConfig->status,
                'is_running' => $freshConfig->isRunning()
            ]);
            return;
        }

        // تنظیم قفل برای جلوگیری از اجرای همزمان
        $lockKey = "config_processing_{$this->config->id}";
        $lockDuration = 300; // 5 دقیقه

        if (Cache::has($lockKey) && !$this->force) {
            Log::info("کانفیگ در حال پردازش است", [
                'config_name' => $this->config->name,
                'lock_key' => $lockKey
            ]);
            return;
        }

        Cache::put($lockKey, now()->toDateTimeString(), $lockDuration);

        try {
            // شروع پردازش
            if (!$freshConfig->isRunning()) {
                $this->config->start();
            }

            // انتخاب سرویس مناسب
            $service = $this->getAppropriateService();

            // پردازش تعداد مشخص شده رکورد
            $this->processRecords($service);

            Log::info("پایان موفق پردازش کانفیگ", [
                'config_name' => $this->config->name
            ]);

        } catch (\Exception $e) {
            $this->handleProcessingError($e);
        } finally {
            // آزادسازی قفل
            Cache::forget($lockKey);
        }
    }

    /**
     * انتخاب سرویس مناسب براساس نوع کانفیگ
     */
    private function getAppropriateService()
    {
        if ($this->config->isApiSource()) {
            return new ApiDataService($this->config);
        } elseif ($this->config->isCrawlerSource()) {
            return new CrawlerDataService($this->config);
        } else {
            throw new \InvalidArgumentException("نوع کانفیگ پشتیبانی نشده: {$this->config->data_source_type}");
        }
    }

    /**
     * پردازش رکوردها
     */
    private function processRecords($service): void
    {
        $startTime = microtime(true);

        try {
            // اجرای سرویس
            if ($this->config->isApiSource()) {
                $stats = $service->fetchData();
            } else {
                $stats = $service->crawlData();
            }

            $executionTime = round(microtime(true) - $startTime, 2);

            // ذخیره آمار در cache
            $stats['execution_time'] = $executionTime;
            $stats['last_run'] = now()->toDateTimeString();

            Cache::put("config_stats_{$this->config->id}", $stats, 3600);

            Log::info("آمار پردازش کانفیگ", [
                'config_name' => $this->config->name,
                'stats' => $stats,
                'execution_time' => $executionTime
            ]);

            // به‌روزرسانی آمار کانفیگ
            $this->config->update([
                'total_processed' => $this->config->total_processed + $stats['total'],
                'total_success' => $this->config->total_success + $stats['success'],
                'total_failed' => $this->config->total_failed + $stats['failed'],
                'last_run_at' => now()
            ]);

            // برنامه‌ریزی اجرای بعدی اگر کانفیگ هنوز فعال است
            $this->scheduleNextRun();

        } catch (\Exception $e) {
            $executionTime = round(microtime(true) - $startTime, 2);

            Log::error("خطا در پردازش رکوردها", [
                'config_name' => $this->config->name,
                'error' => $e->getMessage(),
                'execution_time' => $executionTime
            ]);

            throw $e;
        }
    }

    /**
     * برنامه‌ریزی اجرای بعدی
     */
    private function scheduleNextRun(): void
    {
        $freshConfig = $this->config->fresh();

        if ($freshConfig->isRunning() && $freshConfig->isActive()) {
            // محاسبه زمان اجرای بعدی
            $nextRunDelay = $this->config->delay_seconds;

            Log::info("برنامه‌ریزی اجرای بعدی", [
                'config_name' => $this->config->name,
                'delay_seconds' => $nextRunDelay,
                'next_run_at' => now()->addSeconds($nextRunDelay)->toDateTimeString()
            ]);

            // اضافه کردن job جدید به صف با تاخیر
            static::dispatch($this->config, $this->force)
                ->delay(now()->addSeconds($nextRunDelay));
        } else {
            Log::info("کانفیگ متوقف شد، اجرای بعدی برنامه‌ریزی نشد", [
                'config_name' => $this->config->name,
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
        Log::error("خطا در اجرای کانفیگ", [
            'config_name' => $this->config->name,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

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
        $this->config->updateStats(false);

        // متوقف کردن کانفیگ در صورت خطای جدی
        if ($this->shouldStopOnError($e)) {
            $this->config->stop();
            Log::warning("کانفیگ به دلیل خطای جدی متوقف شد", [
                'config_name' => $this->config->name,
                'error_type' => get_class($e)
            ]);
        } else {
            // برنامه‌ریزی تلاش مجدد با تاخیر بیشتر
            $retryDelay = min($this->config->delay_seconds * 2, 300); // حداکثر 5 دقیقه

            Log::info("برنامه‌ریزی تلاش مجدد", [
                'config_name' => $this->config->name,
                'retry_delay' => $retryDelay
            ]);

            static::dispatch($this->config, $this->force)
                ->delay(now()->addSeconds($retryDelay));
        }
    }

    /**
     * تعیین اینکه آیا باید کانفیگ را در صورت خطا متوقف کرد
     */
    private function shouldStopOnError(\Exception $e): bool
    {
        // خطاهای جدی که باعث توقف می‌شوند
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

        // اگر در یک ساعت گذشته بیش از 10 خطا داشته، متوقف کن
        return $recentFailures > 10;
    }

    /**
     * مدیریت شکست job
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("شکست کامل Job پردازش کانفیگ", [
            'config_name' => $this->config->name,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // متوقف کردن کانفیگ
        $this->config->stop();

        // ثبت شکست
        ScrapingFailure::logFailure(
            $this->config->id,
            $this->config->current_url ?? $this->config->base_url,
            'شکست کامل Job: ' . $exception->getMessage(),
            [
                'trace' => $exception->getTraceAsString(),
                'job_class' => static::class
            ]
        );

        // ذخیره خطا در cache
        Cache::put("config_error_{$this->config->id}", [
            'message' => 'شکست کامل Job: ' . $exception->getMessage(),
            'time' => now()->toDateTimeString(),
            'details' => [
                'line' => $exception->getLine(),
                'file' => basename($exception->getFile())
            ]
        ], 86400);
    }

    /**
     * دریافت tags برای monitoring
     */
    public function tags(): array
    {
        return [
            'config:' . $this->config->id,
            'type:' . $this->config->data_source_type,
            'status:' . $this->config->status
        ];
    }
}
