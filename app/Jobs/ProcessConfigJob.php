<?php

namespace App\Jobs;

use App\Models\Config;
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
 * Job بهبود یافته پردازش کانفیگ و دریافت اطلاعات
 */
class ProcessConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 ساعت timeout
    public $tries = 3; // تعداد تلاش‌های مجدد

    private Config $config;
    private bool $force;

    /**
     * ایجاد نمونه جدید از Job
     */
    public function __construct(Config $config, bool $force = false)
    {
        $this->config = $config;
        $this->force = $force;
    }

    /**
     * اجرای Job
     */
    public function handle(): void
    {
        // بررسی وضعیت فعال بودن کانفیگ
        if (!$this->config->isActive() && !$this->force) {
            Log::info("کانفیگ غیرفعال رد شد", [
                'config_id' => $this->config->id,
                'config_name' => $this->config->name,
                'status' => $this->config->status
            ]);
            return;
        }

        // بررسی قفل برای جلوگیری از اجرای همزمان
        $lockKey = "config_processing_{$this->config->id}";

        if (Cache::has($lockKey) && !$this->force) {
            Log::info("کانفیگ در حال پردازش است", [
                'config_id' => $this->config->id,
                'config_name' => $this->config->name
            ]);
            return;
        }

        // قفل کردن پردازش برای 1 ساعت
        Cache::put($lockKey, [
            'started_at' => now()->toDateTimeString(),
            'attempt' => $this->attempts(),
            'job_id' => $this->job?->getJobId()
        ], 3600);

        $startTime = microtime(true);

        try {
            Log::info("شروع پردازش کانفیگ", [
                'config_id' => $this->config->id,
                'config_name' => $this->config->name,
                'data_source_type' => $this->config->data_source_type,
                'base_url' => $this->config->base_url,
                'attempt' => $this->attempts(),
                'force' => $this->force
            ]);

            // پاک کردن خطاهای قبلی
            $this->clearPreviousErrors();

            // به‌روزرسانی زمان آخرین اجرا
            $this->updateLastRun();

            $stats = [];

            // انتخاب سرویس مناسب براساس نوع کانفیگ
            if ($this->config->isApiSource()) {
                Log::info("استفاده از ApiDataService");
                $service = new ApiDataService($this->config);
                $stats = $service->fetchData();
            } elseif ($this->config->isCrawlerSource()) {
                Log::info("استفاده از CrawlerDataService");
                $service = new CrawlerDataService($this->config);
                $stats = $service->crawlData();
            } else {
                throw new \InvalidArgumentException("نوع کانفیگ پشتیبانی نشده: {$this->config->data_source_type}");
            }

            $executionTime = microtime(true) - $startTime;

            // ذخیره آمار در cache
            $this->saveStats($stats, $executionTime);

            // به‌روزرسانی شمارنده‌های مدل‌ها
            $this->updateCounters();

            Log::info("پایان موفق پردازش کانفیگ", [
                'config_id' => $this->config->id,
                'config_name' => $this->config->name,
                'stats' => $stats,
                'execution_time' => round($executionTime, 2) . ' ثانیه',
                'memory_usage' => $this->formatBytes(memory_get_peak_usage(true))
            ]);

        } catch (\Exception $e) {
            $executionTime = microtime(true) - $startTime;

            Log::error("خطا در پردازش کانفیگ", [
                'config_id' => $this->config->id,
                'config_name' => $this->config->name,
                'error_message' => $e->getMessage(),
                'error_line' => $e->getLine(),
                'error_file' => $e->getFile(),
                'execution_time' => round($executionTime, 2) . ' ثانیه',
                'attempt' => $this->attempts(),
                'trace' => $e->getTraceAsString()
            ]);

            // ذخیره خطا در cache با جزئیات بیشتر
            Cache::put("config_error_{$this->config->id}", [
                'message' => $e->getMessage(),
                'time' => now()->toDateTimeString(),
                'attempt' => $this->attempts(),
                'execution_time' => round($executionTime, 2),
                'details' => [
                    'line' => $e->getLine(),
                    'file' => basename($e->getFile()),
                    'class' => get_class($e)
                ]
            ], 86400); // 24 ساعت

            throw $e;

        } finally {
            // آزاد کردن قفل
            Cache::forget($lockKey);
        }
    }

    /**
     * پاک کردن خطاهای قبلی
     */
    private function clearPreviousErrors(): void
    {
        $errorKeys = [
            "config_error_{$this->config->id}",
            "config_final_error_{$this->config->id}"
        ];

        foreach ($errorKeys as $key) {
            Cache::forget($key);
        }
    }

    /**
     * به‌روزرسانی زمان آخرین اجرا
     */
    private function updateLastRun(): void
    {
        try {
            $configData = $this->config->config_data;
            $configData['last_run'] = now()->toDateTimeString();

            $this->config->update(['config_data' => $configData]);

            Log::debug("زمان آخرین اجرا به‌روزرسانی شد", [
                'config_id' => $this->config->id
            ]);

        } catch (\Exception $e) {
            Log::warning("خطا در به‌روزرسانی زمان آخرین اجرا", [
                'config_id' => $this->config->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * ذخیره آمار در cache
     */
    private function saveStats(array $stats, float $executionTime): void
    {
        $cacheKey = "config_stats_{$this->config->id}";

        $statsData = array_merge($stats, [
            'config_id' => $this->config->id,
            'config_name' => $this->config->name,
            'data_source_type' => $this->config->data_source_type,
            'last_run' => now()->toDateTimeString(),
            'execution_time' => round($executionTime, 2),
            'memory_usage' => memory_get_peak_usage(true),
            'attempt' => $this->attempts()
        ]);

        Cache::put($cacheKey, $statsData, 86400); // 24 ساعت

        Log::debug("آمار در cache ذخیره شد", [
            'config_id' => $this->config->id,
            'cache_key' => $cacheKey
        ]);
    }

    /**
     * به‌روزرسانی شمارنده‌های مدل‌ها
     */
    private function updateCounters(): void
    {
        try {
            Log::debug("شروع به‌روزرسانی شمارنده‌ها");

            // به‌روزرسانی تعداد کتاب‌های دسته‌بندی‌ها
            \App\Models\Category::whereHas('books')->chunk(50, function ($categories) {
                foreach ($categories as $category) {
                    $count = \App\Models\Book::where('category_id', $category->id)->count();
                    $category->update(['books_count' => $count]);
                }
            });

            // به‌روزرسانی تعداد کتاب‌های نویسندگان
            \App\Models\Author::whereHas('books')->chunk(50, function ($authors) {
                foreach ($authors as $author) {
                    $count = \DB::table('book_author')->where('author_id', $author->id)->count();
                    $author->update(['books_count' => $count]);
                }
            });

            // به‌روزرسانی تعداد کتاب‌های ناشران
            \App\Models\Publisher::whereHas('books')->chunk(50, function ($publishers) {
                foreach ($publishers as $publisher) {
                    $count = \App\Models\Book::where('publisher_id', $publisher->id)->count();
                    $publisher->update(['books_count' => $count]);
                }
            });

            Log::debug("به‌روزرسانی شمارنده‌ها انجام شد");

        } catch (\Exception $e) {
            Log::warning("خطا در به‌روزرسانی شمارنده‌ها", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * فرمت کردن bytes به واحد قابل خواندن
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * مدیریت خطا در Job
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("شکست نهایی در پردازش کانفیگ", [
            'config_id' => $this->config->id,
            'config_name' => $this->config->name,
            'error_message' => $exception->getMessage(),
            'error_line' => $exception->getLine(),
            'error_file' => $exception->getFile(),
            'attempts' => $this->attempts(),
            'exception_class' => get_class($exception),
            'trace' => $exception->getTraceAsString()
        ]);

        // ذخیره خطای نهایی
        Cache::put("config_final_error_{$this->config->id}", [
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'time' => now()->toDateTimeString(),
            'details' => [
                'line' => $exception->getLine(),
                'file' => basename($exception->getFile()),
                'class' => get_class($exception)
            ]
        ], 86400);

        // آزاد کردن قفل در صورت خطا
        $lockKey = "config_processing_{$this->config->id}";
        Cache::forget($lockKey);

        // ارسال اعلان (در صورت نیاز)
        // event(new ConfigProcessingFailed($this->config, $exception));
    }
}
