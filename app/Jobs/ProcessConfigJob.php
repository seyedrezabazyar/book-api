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
 * Job پردازش کانفیگ و دریافت اطلاعات
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
            Log::info("کانفیگ غیرفعال رد شد: {$this->config->name}");
            return;
        }

        // بررسی قفل برای جلوگیری از اجرای همزمان
        $lockKey = "config_processing_{$this->config->id}";

        if (Cache::has($lockKey) && !$this->force) {
            Log::info("کانفیگ در حال پردازش است: {$this->config->name}");
            return;
        }

        // قفل کردن پردازش برای 1 ساعت
        Cache::put($lockKey, true, 3600);

        try {
            Log::info("شروع پردازش کانفیگ: {$this->config->name}");

            // به‌روزرسانی زمان آخرین اجرا
            $this->updateLastRun();

            $stats = [];

            // انتخاب سرویس مناسب براساس نوع کانفیگ
            if ($this->config->isApiSource()) {
                $service = new ApiDataService($this->config);
                $stats = $service->fetchData();
            } elseif ($this->config->isCrawlerSource()) {
                $service = new CrawlerDataService($this->config);
                $stats = $service->crawlData();
            } else {
                throw new \InvalidArgumentException("نوع کانفیگ پشتیبانی نشده: {$this->config->data_source_type}");
            }

            // ذخیره آمار در cache
            $this->saveStats($stats);

            // به‌روزرسانی شمارنده‌های مدل‌ها
            $this->updateCounters();

            Log::info("پایان موفق پردازش کانفیگ: {$this->config->name}", $stats);

        } catch (\Exception $e) {
            Log::error("خطا در پردازش کانفیگ {$this->config->name}: " . $e->getMessage(), [
                'config_id' => $this->config->id,
                'exception' => $e->getTraceAsString()
            ]);

            // ذخیره خطا در cache
            Cache::put("config_error_{$this->config->id}", [
                'message' => $e->getMessage(),
                'time' => now()->toDateTimeString()
            ], 86400); // 24 ساعت

            throw $e;

        } finally {
            // آزاد کردن قفل
            Cache::forget($lockKey);
        }
    }

    /**
     * به‌روزرسانی زمان آخرین اجرا
     */
    private function updateLastRun(): void
    {
        $configData = $this->config->config_data;
        $configData['last_run'] = now()->toDateTimeString();

        $this->config->update(['config_data' => $configData]);
    }

    /**
     * ذخیره آمار در cache
     */
    private function saveStats(array $stats): void
    {
        $cacheKey = "config_stats_{$this->config->id}";

        $statsData = array_merge($stats, [
            'config_id' => $this->config->id,
            'config_name' => $this->config->name,
            'data_source_type' => $this->config->data_source_type,
            'last_run' => now()->toDateTimeString(),
            'execution_time' => $this->getExecutionTime()
        ]);

        Cache::put($cacheKey, $statsData, 86400); // 24 ساعت
    }

    /**
     * محاسبه زمان اجرا
     */
    private function getExecutionTime(): int
    {
        return intval(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']);
    }

    /**
     * به‌روزرسانی شمارنده‌های مدل‌ها
     */
    private function updateCounters(): void
    {
        try {
            // به‌روزرسانی تعداد کتاب‌های دسته‌بندی‌ها
            \App\Models\Category::all()->each(function($category) {
                $count = \App\Models\Book::where('category_id', $category->id)->count();
                $category->update(['books_count' => $count]);
            });

            // به‌روزرسانی تعداد کتاب‌های نویسندگان
            \App\Models\Author::all()->each(function($author) {
                $count = \DB::table('book_author')->where('author_id', $author->id)->count();
                $author->update(['books_count' => $count]);
            });

            // به‌روزرسانی تعداد کتاب‌های ناشران
            \App\Models\Publisher::all()->each(function($publisher) {
                $count = \App\Models\Book::where('publisher_id', $publisher->id)->count();
                $publisher->update(['books_count' => $count]);
            });

        } catch (\Exception $e) {
            Log::warning("خطا در به‌روزرسانی شمارنده‌ها: " . $e->getMessage());
        }
    }

    /**
     * مدیریت خطا در Job
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("شکست نهایی در پردازش کانفیگ {$this->config->name}: " . $exception->getMessage(), [
            'config_id' => $this->config->id,
            'attempts' => $this->attempts(),
            'exception' => $exception->getTraceAsString()
        ]);

        // ذخیره خطای نهایی
        Cache::put("config_final_error_{$this->config->id}", [
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'time' => now()->toDateTimeString()
        ], 86400);

        // آزاد کردن قفل در صورت خطا
        Cache::forget("config_processing_{$this->config->id}");
    }
}
