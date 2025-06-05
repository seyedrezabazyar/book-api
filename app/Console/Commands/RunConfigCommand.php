<?php

namespace App\Console\Commands;

use App\Models\Config;
use App\Jobs\ProcessConfigJob;
use App\Services\ApiDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * کامند بهینه‌شده اجرای کانفیگ‌ها
 */
class RunConfigCommand extends Command
{
    /**
     * نام و امضای کامند
     */
    protected $signature = 'config:run
                            {--id= : شناسه کانفیگ خاص}
                            {--name= : نام کانفیگ خاص}
                            {--sync : اجرای همزمان به جای استفاده از Queue}
                            {--limit=10 : تعداد رکورد برای پردازش}
                            {--all : اجرای تمام کانفیگ‌های فعال}';

    /**
     * توضیحات کامند
     */
    protected $description = 'اجرای بهینه‌شده کانفیگ‌ها برای دریافت اطلاعات';

    /**
     * اجرای کامند
     */
    public function handle(): int
    {
        $this->info('🚀 شروع اجرای کانفیگ‌ها...');

        try {
            // دریافت کانفیگ‌های هدف
            $configs = $this->getTargetConfigs();

            if ($configs->isEmpty()) {
                $this->warn('❌ هیچ کانفیگی برای اجرا یافت نشد.');
                return Command::FAILURE;
            }

            $this->info("📋 تعداد {$configs->count()} کانفیگ برای اجرا یافت شد.");

            // نمایش لیست کانفیگ‌ها
            $this->displayConfigsList($configs);

            // اجرای کانفیگ‌ها
            $results = $this->executeConfigs($configs);

            // نمایش نتایج
            $this->displayResults($results);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ خطا در اجرای کامند: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * دریافت کانفیگ‌های هدف
     */
    private function getTargetConfigs()
    {
        $query = Config::query();

        // فیلتر براساس شناسه
        if ($configId = $this->option('id')) {
            return $query->where('id', $configId)->get();
        }

        // فیلتر براساس نام
        if ($configName = $this->option('name')) {
            return $query->where('name', 'like', "%{$configName}%")->get();
        }

        // اجرای همه کانفیگ‌ها
        if ($this->option('all')) {
            return $query->active()->get();
        }

        // پیش‌فرض: فقط کانفیگ‌های فعال
        return $query->active()->limit(5)->get();
    }

    /**
     * نمایش لیست کانفیگ‌ها
     */
    private function displayConfigsList($configs): void
    {
        $tableData = [];

        foreach ($configs as $config) {
            $lastRun = $config->last_run_at ? $config->last_run_at->diffForHumans() : 'هرگز';

            $tableData[] = [
                $config->id,
                $config->name,
                $config->data_source_type_text,
                $config->status_text,
                $lastRun,
                Str::limit($config->base_url, 50)
            ];
        }

        $this->table(
            ['شناسه', 'نام', 'نوع', 'وضعیت', 'آخرین اجرا', 'آدرس پایه'],
            $tableData
        );
    }

    /**
     * اجرای کانفیگ‌ها
     */
    private function executeConfigs($configs): array
    {
        $results = [];
        $useSync = $this->option('sync');
        $limit = (int) $this->option('limit');

        $progressBar = $this->output->createProgressBar($configs->count());
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
        $progressBar->setMessage('آماده‌سازی...');
        $progressBar->start();

        foreach ($configs as $config) {
            $progressBar->setMessage("پردازش: {$config->name}");

            try {
                // بررسی قفل
                $lockKey = "config_processing_{$config->id}";
                if (Cache::has($lockKey)) {
                    $results[] = [
                        'config' => $config,
                        'status' => 'skipped',
                        'message' => 'در حال پردازش',
                        'stats' => null
                    ];
                    continue;
                }

                if ($useSync) {
                    // اجرای همزمان
                    $stats = $this->runConfigSync($config, $limit);
                    $results[] = [
                        'config' => $config,
                        'status' => 'completed',
                        'message' => 'موفق',
                        'stats' => $stats
                    ];
                } else {
                    // اضافه کردن به صف
                    ProcessConfigJob::dispatch($config);
                    $results[] = [
                        'config' => $config,
                        'status' => 'queued',
                        'message' => 'اضافه شد به صف',
                        'stats' => null
                    ];
                }

            } catch (\Exception $e) {
                $results[] = [
                    'config' => $config,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                    'stats' => null
                ];
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        return $results;
    }

    /**
     * اجرای همزمان کانفیگ
     */
    private function runConfigSync(Config $config, int $limit): array
    {
        if ($config->isApiSource()) {
            $service = new ApiDataService($config);

            // تنظیم محدودیت رکورد موقت
            $originalLimit = $config->records_per_run;
            $config->records_per_run = $limit;

            try {
                $stats = $service->fetchData();

                // بازگرداندن تنظیم اصلی
                $config->records_per_run = $originalLimit;

                return $stats;
            } catch (\Exception $e) {
                $config->records_per_run = $originalLimit;
                throw $e;
            }
        }

        throw new \InvalidArgumentException("نوع کانفیگ پشتیبانی نشده: {$config->data_source_type}");
    }

    /**
     * نمایش نتایج
     */
    private function displayResults(array $results): void
    {
        $this->info('📊 نتایج اجرا:');
        $this->newLine();

        $tableData = [];
        $totalStats = ['total' => 0, 'success' => 0, 'failed' => 0, 'duplicate' => 0];

        foreach ($results as $result) {
            $config = $result['config'];
            $stats = $result['stats'];

            if ($stats) {
                $statsText = "کل: {$stats['total']}, موفق: {$stats['success']}, خطا: {$stats['failed']}, تکراری: {$stats['duplicate']}";

                // جمع آمار کلی
                $totalStats['total'] += $stats['total'];
                $totalStats['success'] += $stats['success'];
                $totalStats['failed'] += $stats['failed'];
                $totalStats['duplicate'] += $stats['duplicate'];
            } else {
                $statsText = '-';
            }

            $statusIcon = match($result['status']) {
                'completed' => '✅',
                'queued' => '⏳',
                'skipped' => '⏭️',
                'failed' => '❌',
                default => '❓'
            };

            $tableData[] = [
                $config->name,
                $statusIcon . ' ' . $result['message'],
                $statsText
            ];
        }

        $this->table(['کانفیگ', 'وضعیت', 'آمار'], $tableData);

        // نمایش آمار کلی
        if ($totalStats['total'] > 0) {
            $this->newLine();
            $this->info('📈 آمار کلی:');
            $this->info("   🎯 کل رکوردهای پردازش شده: {$totalStats['total']}");
            $this->info("   ✅ موفق: {$totalStats['success']}");
            $this->info("   ❌ خطا: {$totalStats['failed']}");
            $this->info("   🔄 تکراری: {$totalStats['duplicate']}");
        }

        $this->newLine();
        $this->info('🎉 اجرا به پایان رسید!');
    }
}
