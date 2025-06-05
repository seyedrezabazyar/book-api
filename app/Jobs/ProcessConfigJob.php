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
 * Job بهینه‌شده پردازش کانفیگ‌ها
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
    }

    /**
     * اجرای job
     */
    public function handle(): void
    {
        Log::info("شروع پردازش کانفیگ", [
            'config_name' => $this->config->name,
            'config_id' => $this->config->id
        ]);

        // بررسی امکان ادامه
        $freshConfig = $this->config->fresh();
        if (!$this->force && !$freshConfig->canStart() && !$freshConfig->isRunning()) {
            Log::info("کانفیگ غیرقابل اجرا", ['config' => $this->config->name]);
            return;
        }

        // تنظیم قفل
        $lockKey = "config_processing_{$this->config->id}";
        $lockDuration = 300; // 5 دقیقه

        if (Cache::has($lockKey) && !$this->force) {
            Log::info("کانفیگ در حال پردازش", ['config' => $this->config->name]);
            return;
        }

        Cache::put($lockKey, now()->toDateTimeString(), $lockDuration);

        try {
            // شروع پردازش
            if (!$freshConfig->isRunning()) {
                $this->config->start();
            }

            // انتخاب سرویس و پردازش
            $service = $this->getAppropriateService();
            $this->processWithService($service);

            // برنامه‌ریزی اجرای بعدی فقط اگر کانفیگ هنوز فعال باشد
            $this->scheduleNextRunIfNeeded();

            Log::info("پایان موفق پردازش کانفیگ", ['config' => $this->config->name]);

        } catch (\Exception $e) {
            $this->handleProcessingError($e);
        } finally {
            Cache::forget($lockKey);
        }
    }

    /**
     * انتخاب سرویس مناسب
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
     * پردازش با سرویس
     */
    private function processWithService($service): void
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
            $stats['execution_time'] = $executionTime;
            $stats['last_run'] = now()->toDateTimeString();

            // ذخیره آمار
            Cache::put("config_stats_{$this->config->id}", $stats, 3600);

            // به‌روزرسانی آمار کانفیگ
            $this->config->update([
                'total_processed' => $this->config->total_processed + $stats['total'],
                'total_success' => $this->config->total_success + $stats['success'],
                'total_failed' => $this->config->total_failed + $stats['failed'],
                'last_run_at' => now()
            ]);

            Log::info("آمار پردازش", [
                'config' => $this->config->name,
                'stats' => $stats,
                'execution_time' => $executionTime
            ]);

        } catch (\Exception $e) {
            Log::error("خطا در پردازش", [
                'config' => $this->config->name,
                'error' => $e->getMessage()
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

            Log::info("برنامه‌ریزی اجرای بعدی", [
                'config' => $this->config->name,
                'delay_seconds' => $nextRunDelay
            ]);

            // اضافه کردن job جدید با تاخیر
            static::dispatch($this->config, $this->force)
                ->delay(now()->addSeconds($nextRunDelay));
        } else {
            Log::info("عدم برنامه‌ریزی اجرای بعدی", [
                'config' => $this->config->name,
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
            'config' => $this->config->name,
            'error' => $e->getMessage()
        ]);

        // ثبت شکست
        ScrapingFailure::logFailure(
            $this->config->id,
            $this->config->current_url ?? $this->config->base_url,
            $e->getMessage(),
            [
                'line' => $e->getLine(),
                'file' => basename($e->getFile()),
                'class' => get_class($e)
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
            Log::warning("کانفیگ متوقف شد", ['config' => $this->config->name]);
        } else {
            // تلاش مجدد با تاخیر بیشتر
            $retryDelay = min($this->config->delay_seconds * 2, 300);

            Log::info("برنامه‌ریزی تلاش مجدد", [
                'config' => $this->config->name,
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

        return $recentFailures > 5; // کاهش از 10 به 5
    }

    /**
     * مدیریت شکست job
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("شکست کامل Job", [
            'config' => $this->config->name,
            'error' => $exception->getMessage()
        ]);

        $this->config->stop();

        ScrapingFailure::logFailure(
            $this->config->id,
            $this->config->current_url ?? $this->config->base_url,
            'شکست کامل Job: ' . $exception->getMessage(),
            ['job_class' => static::class]
        );
    }

    /**
     * تگ‌ها برای monitoring
     */
    public function tags(): array
    {
        return [
            'config:' . $this->config->id,
            'type:' . $this->config->data_source_type
        ];
    }
}
