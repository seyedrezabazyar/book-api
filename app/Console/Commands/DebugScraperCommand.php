<?php

namespace App\Console\Commands;

use App\Models\Config;
use App\Models\Book;
use App\Services\ApiDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * کامند تشخیص مشکل اسکرپر
 */
class DebugScraperCommand extends Command
{
    protected $signature = 'scraper:debug {config_id?}';
    protected $description = 'تشخیص مشکلات اسکرپر و نمایش گزارش کامل';

    public function handle(): int
    {
        $this->info('🔍 شروع تشخیص مشکل اسکرپر...');
        $this->newLine();

        // انتخاب کانفیگ
        $configId = $this->argument('config_id');
        if (!$configId) {
            $configs = Config::where('status', 'active')->get();

            if ($configs->isEmpty()) {
                $this->error('❌ هیچ کانفیگ فعالی یافت نشد!');
                return Command::FAILURE;
            }

            if ($configs->count() === 1) {
                $config = $configs->first();
            } else {
                $choices = $configs->pluck('name', 'id')->toArray();
                $configId = $this->choice('کانفیگ مورد نظر را انتخاب کنید:', $choices);
                $config = Config::find($configId);
            }
        } else {
            $config = Config::find($configId);
            if (!$config) {
                $this->error("❌ کانفیگ با شناسه {$configId} یافت نشد!");
                return Command::FAILURE;
            }
        }

        $this->info("🎯 تشخیص مشکل برای کانفیگ: {$config->name}");
        $this->newLine();

        // مراحل تشخیص
        $this->checkBasicInfo($config);
        $this->checkDatabase();
        $this->checkQueue();
        $this->checkApiConnection($config);
        $this->checkDataExtraction($config);
        $this->checkLogs($config);
        $this->runTestFetch($config);

        return Command::SUCCESS;
    }

    /**
     * بررسی اطلاعات پایه
     */
    private function checkBasicInfo(Config $config): void
    {
        $this->info('📊 مرحله 1: بررسی اطلاعات پایه');
        $this->line("├─ نام: {$config->name}");
        $this->line("├─ وضعیت: {$config->status}");
        $this->line("├─ در حال اجرا: " . ($config->is_running ? '✅ بله' : '❌ خیر'));
        $this->line("├─ آدرس پایه: {$config->base_url}");
        $this->line("├─ تاخیر: {$config->delay_seconds} ثانیه");
        $this->line("├─ رکورد در هر اجرا: {$config->records_per_run}");
        $this->line("├─ کل پردازش شده: {$config->total_processed}");
        $this->line("├─ موفق: {$config->total_success}");
        $this->line("└─ خطا: {$config->total_failed}");
        $this->newLine();

        if (!$config->isActive()) {
            $this->warn('⚠️ هشدار: کانفیگ غیرفعال است!');
        }
    }

    /**
     * بررسی دیتابیس
     */
    private function checkDatabase(): void
    {
        $this->info('🗄️ مرحله 2: بررسی دیتابیس');

        try {
            $totalBooks = Book::count();
            $recentBooks = Book::where('created_at', '>=', now()->subDay())->count();
            $totalConfigs = Config::count();

            $this->line("├─ کل کتاب‌ها: {$totalBooks}");
            $this->line("├─ کتاب‌های امروز: {$recentBooks}");
            $this->line("├─ کل کانفیگ‌ها: {$totalConfigs}");

            // تست اتصال دیتابیس
            DB::connection()->getPdo();
            $this->line("└─ اتصال دیتابیس: ✅ موفق");

        } catch (\Exception $e) {
            $this->line("└─ اتصال دیتابیس: ❌ خطا - " . $e->getMessage());
        }
        $this->newLine();
    }

    /**
     * بررسی Queue
     */
    private function checkQueue(): void
    {
        $this->info('⚡ مرحله 3: بررسی Queue');

        try {
            // بررسی تعداد job های pending
            $pendingJobs = DB::table('jobs')->count();
            $failedJobs = DB::table('failed_jobs')->count();

            $this->line("├─ Job های در انتظار: {$pendingJobs}");
            $this->line("├─ Job های ناموفق: {$failedJobs}");

            // بررسی آخرین job
            $lastJob = DB::table('jobs')->latest('created_at')->first();
            if ($lastJob) {
                $payload = json_decode($lastJob->payload, true);
                $jobClass = $payload['displayName'] ?? 'نامشخص';
                $this->line("└─ آخرین Job: {$jobClass}");
            } else {
                $this->line("└─ آخرین Job: هیچ");
            }

        } catch (\Exception $e) {
            $this->line("└─ خطا در بررسی Queue: " . $e->getMessage());
        }
        $this->newLine();
    }

    /**
     * بررسی اتصال API
     */
    private function checkApiConnection(Config $config): void
    {
        $this->info('🌐 مرحله 4: بررسی اتصال API');

        if (!$config->isApiSource()) {
            $this->line("└─ این کانفیگ از نوع API نیست");
            $this->newLine();
            return;
        }

        try {
            $apiSettings = $config->getApiSettings();
            $generalSettings = $config->getGeneralSettings();

            // ساخت URL تست
            $baseUrl = rtrim($config->base_url, '/');
            $endpoint = $apiSettings['endpoint'] ?? '';
            $testUrl = $baseUrl . ($endpoint ? '/' . ltrim($endpoint, '/') : '');
            $testUrl .= '?limit=1'; // فقط یک رکورد برای تست

            $this->line("├─ URL تست: {$testUrl}");

            // ارسال درخواست تست
            $httpClient = Http::timeout($config->timeout);

            if (!empty($generalSettings['user_agent'])) {
                $httpClient = $httpClient->withUserAgent($generalSettings['user_agent']);
            }

            $startTime = microtime(true);
            $response = $httpClient->get($testUrl);
            $responseTime = round((microtime(true) - $startTime) * 1000);

            $this->line("├─ زمان پاسخ: {$responseTime} میلی‌ثانیه");
            $this->line("├─ کد HTTP: {$response->status()}");

            if ($response->successful()) {
                $data = $response->json();
                $this->line("├─ نوع پاسخ: " . (is_array($data) ? 'Array' : gettype($data)));

                if (is_array($data)) {
                    $this->line("├─ کلیدهای اصلی: " . implode(', ', array_keys($data)));

                    // بررسی ساختار
                    if (isset($data['status']) && isset($data['data']['books'])) {
                        $bookCount = count($data['data']['books']);
                        $this->line("└─ تعداد کتاب‌ها در پاسخ: ✅ {$bookCount}");
                    } else {
                        $this->line("└─ ساختار پاسخ: ⚠️ نامشخص");
                    }
                } else {
                    $this->line("└─ پاسخ: ❌ نامعتبر");
                }

            } else {
                $this->line("└─ خطای HTTP: ❌ {$response->status()} - {$response->reason()}");
            }

        } catch (\Exception $e) {
            $this->line("└─ خطا در اتصال: ❌ " . $e->getMessage());
        }
        $this->newLine();
    }

    /**
     * بررسی استخراج داده
     */
    private function checkDataExtraction(Config $config): void
    {
        $this->info('📋 مرحله 5: بررسی استخراج داده');

        if (!$config->isApiSource()) {
            $this->line("└─ این کانفیگ از نوع API نیست");
            $this->newLine();
            return;
        }

        try {
            $apiSettings = $config->getApiSettings();
            $fieldMapping = $apiSettings['field_mapping'] ?? [];

            $this->line("├─ تعداد فیلدهای نقشه‌برداری: " . count($fieldMapping));

            if (empty($fieldMapping)) {
                $this->line("├─ نقشه‌برداری: ⚠️ خالی (استفاده از پیش‌فرض)");
                $fieldMapping = [
                    'title' => 'title',
                    'description' => 'description_en',
                    'author' => 'authors'
                ];
            } else {
                $this->line("├─ نقشه‌برداری: ✅ تعریف شده");
            }

            // نمایش نقشه‌برداری مهم
            foreach (['title', 'author', 'description'] as $field) {
                if (isset($fieldMapping[$field])) {
                    $this->line("│  ├─ {$field}: {$fieldMapping[$field]}");
                }
            }

            $this->line("└─ فیلدهای کلیدی: " . (isset($fieldMapping['title']) ? '✅' : '❌') . " موجود");

        } catch (\Exception $e) {
            $this->line("└─ خطا در بررسی نقشه‌برداری: " . $e->getMessage());
        }
        $this->newLine();
    }

    /**
     * بررسی لاگ‌ها
     */
    private function checkLogs(Config $config): void
    {
        $this->info('📝 مرحله 6: بررسی لاگ‌ها');

        try {
            $logFile = storage_path('logs/laravel.log');

            if (!file_exists($logFile)) {
                $this->line("└─ فایل لاگ یافت نشد");
                $this->newLine();
                return;
            }

            // خواندن آخرین خطوط لاگ
            $lines = array_slice(file($logFile), -50);
            $configLogs = array_filter($lines, function($line) use ($config) {
                return str_contains($line, $config->name) ||
                    str_contains($line, "config:{$config->id}") ||
                    str_contains($line, 'ProcessConfigJob');
            });

            $this->line("├─ کل خطوط لاگ اخیر: " . count($lines));
            $this->line("├─ لاگ‌های مربوط به این کانفیگ: " . count($configLogs));

            if (!empty($configLogs)) {
                $this->line("└─ آخرین لاگ:");
                $lastLog = array_slice($configLogs, -1)[0];
                $this->line("   " . trim($lastLog));
            } else {
                $this->line("└─ هیچ لاگی برای این کانفیگ یافت نشد");
            }

        } catch (\Exception $e) {
            $this->line("└─ خطا در خواندن لاگ: " . $e->getMessage());
        }
        $this->newLine();
    }

    /**
     * اجرای تست واقعی
     */
    private function runTestFetch(Config $config): void
    {
        $this->info('🧪 مرحله 7: تست واقعی دریافت داده');

        if (!$config->isApiSource()) {
            $this->line("└─ این کانفیگ از نوع API نیست");
            return;
        }

        try {
            $this->line("├─ شروع تست...");

            // ایجاد سرویس
            $service = new ApiDataService($config);

            // تنظیم موقت برای تست
            $originalRecords = $config->records_per_run;
            $config->records_per_run = 2; // فقط 2 رکورد برای تست

            $startTime = microtime(true);
            $stats = $service->fetchData();
            $duration = round(microtime(true) - $startTime, 2);

            // بازگرداندن تنظیم اصلی
            $config->records_per_run = $originalRecords;

            $this->line("├─ مدت زمان: {$duration} ثانیه");
            $this->line("├─ کل پردازش شده: {$stats['total']}");
            $this->line("├─ موفق: {$stats['success']}");
            $this->line("├─ خطا: {$stats['failed']}");
            $this->line("└─ تکراری: {$stats['duplicate']}");

            if ($stats['success'] > 0) {
                $this->line("✅ تست موفق: داده‌ها با موفقیت دریافت شدند!");

                // نمایش آخرین کتاب ایجاد شده
                $lastBook = Book::latest()->first();
                if ($lastBook) {
                    $this->line("📚 آخرین کتاب: {$lastBook->title}");
                }
            } else {
                $this->line("❌ تست ناموفق: هیچ داده‌ای دریافت نشد");
            }

        } catch (\Exception $e) {
            $this->line("└─ خطا در تست: ❌ " . $e->getMessage());
            $this->line("   فایل: " . basename($e->getFile()) . ":" . $e->getLine());
        }

        $this->newLine();
    }
}
