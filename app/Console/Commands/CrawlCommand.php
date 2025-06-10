<?php
// در فایل app/Console/Commands/CrawlCommand.php یا مشابه - بهبود کامل

use Illuminate\Console\Command;
use App\Models\Config;
use App\Services\ApiDataService;
use App\Models\ExecutionLog;
use Illuminate\Support\Facades\Log;

class CrawlBooksCommand extends Command
{
    protected $signature = 'crawl:books
                          {config? : ID کانفیگ برای اجرا (اختیاری)}
                          {--start-page=0 : صفحه شروع (0 = تشخیص خودکار)}
                          {--pages=0 : تعداد صفحات (0 = بدون محدودیت)}
                          {--force : اجرای اجباری حتی در صورت وجود اجرای فعال}
                          {--enhanced-only : فقط بروزرسانی کتاب‌های موجود}';

    protected $description = 'اجرای کرال هوشمند با قابلیت بروزرسانی پیشرفته کتاب‌ها';

    private $executionLog;
    private $totalStats;
    private $currentConfig;
    private $startTime;

    public function __construct()
    {
        parent::__construct();
        $this->totalStats = [
            'total_processed' => 0,
            'total_success' => 0,
            'total_enhanced' => 0,
            'total_failed' => 0,
            'total_duplicate' => 0,
        ];
        $this->startTime = microtime(true);
    }

    public function handle()
    {
        $this->info("🚀 شروع کرال هوشمند کتاب‌ها با قابلیت بروزرسانی پیشرفته");
        $this->info("⏰ زمان شروع: " . now()->format('Y-m-d H:i:s'));

        try {
            // تعیین کانفیگ(های) اجرا
            $configs = $this->determineConfigs();

            if ($configs->isEmpty()) {
                $this->error("❌ هیچ کانفیگ فعالی برای اجرا یافت نشد!");
                return Command::FAILURE;
            }

            $this->info("📋 تعداد کانفیگ‌های قابل اجرا: " . $configs->count());

            foreach ($configs as $config) {
                if ($this->shouldSkipConfig($config)) {
                    $this->warn("⏭️ رد شدن کانفیگ {$config->id} ({$config->source_name})");
                    continue;
                }

                $this->info("🔄 شروع پردازش کانفیگ: {$config->source_name} (ID: {$config->id})");
                $this->processConfig($config);
            }

            $this->displayFinalSummary();
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ خطای کلی در اجرای کرال: " . $e->getMessage());
            Log::error("خطای کلی در CrawlBooksCommand", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    private function determineConfigs()
    {
        $configId = $this->argument('config');

        if ($configId) {
            $config = Config::find($configId);
            if (!$config) {
                $this->error("❌ کانفیگ با ID {$configId} یافت نشد!");
                return collect([]);
            }
            return collect([$config]);
        }

        // همه کانفیگ‌های فعال
        return Config::where('is_active', true)->get();
    }

    private function shouldSkipConfig(Config $config): bool
    {
        // بررسی اجرای موازی
        if (!$this->option('force')) {
            $runningExecution = ExecutionLog::where('config_id', $config->id)
                ->where('status', 'running')
                ->exists();

            if ($runningExecution) {
                $this->warn("⚠️ کانفیگ {$config->id} در حال اجرا است. از --force استفاده کنید برای اجرای اجباری.");
                return true;
            }
        }

        return false;
    }

    private function processConfig(Config $config)
    {
        $this->currentConfig = $config;

        try {
            // شروع ExecutionLog
            $this->executionLog = $this->createExecutionLog($config);

            // تعیین تنظیمات اجرا
            $crawlSettings = $this->determineCrawlSettings($config);
            $this->displayCrawlSettings($crawlSettings);

            // اجرای کرال
            $this->performCrawl($config, $crawlSettings);

        } catch (\Exception $e) {
            $this->error("❌ خطا در پردازش کانفیگ {$config->id}: " . $e->getMessage());

            if ($this->executionLog) {
                $this->executionLog->markFailed($e->getMessage());
            }

            Log::error("خطا در پردازش کانفیگ", [
                'config_id' => $config->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function createExecutionLog(Config $config): ExecutionLog
    {
        $executionId = 'crawl_' . time() . '_' . $config->id;

        return ExecutionLog::create([
            'config_id' => $config->id,
            'execution_id' => $executionId,
            'status' => 'running',
            'started_at' => now(),
            'log_details' => [
                'command_options' => [
                    'start_page' => $this->option('start-page'),
                    'pages' => $this->option('pages'),
                    'force' => $this->option('force'),
                    'enhanced_only' => $this->option('enhanced-only')
                ]
            ]
        ]);
    }

    private function determineCrawlSettings(Config $config): array
    {
        $startPage = (int) $this->option('start-page');
        $pagesCount = (int) $this->option('pages');

        // تشخیص خودکار نقطه شروع
        if ($startPage <= 0) {
            $startPage = $config->getSmartStartPage();
            $this->info("🎯 تشخیص خودکار نقطه شروع: {$startPage}");
        }

        // تنظیمات پیش‌فرض تعداد صفحات
        if ($pagesCount <= 0) {
            $pagesCount = $config->pages_per_execution ?: 100;
        }

        return [
            'start_page' => $startPage,
            'pages_count' => $pagesCount,
            'enhanced_only' => $this->option('enhanced-only'),
            'smart_update_enabled' => true, // همیشه فعال
            'batch_size' => 50, // اندازه دسته برای پردازش
        ];
    }

    private function displayCrawlSettings(array $settings)
    {
        $this->info("⚙️ تنظیمات اجرا:");
        $this->line("   • صفحه شروع: {$settings['start_page']}");
        $this->line("   • تعداد صفحات: {$settings['pages_count']}");
        $this->line("   • حالت فقط بهبود: " . ($settings['enhanced_only'] ? 'بله' : 'خیر'));
        $this->line("   • بروزرسانی هوشمند: " . ($settings['smart_update_enabled'] ? 'فعال' : 'غیرفعال'));
        $this->line("   • اندازه دسته: {$settings['batch_size']}");
    }

    private function performCrawl(Config $config, array $settings)
    {
        try {
            $apiService = new ApiDataService($config);

            $currentPage = $settings['start_page'];
            $endPage = $currentPage + $settings['pages_count'] - 1;
            $processedInConfig = 0;
            $configStats = [
                'total_processed' => 0,
                'total_success' => 0,
                'total_enhanced' => 0,
                'total_failed' => 0,
                'total_duplicate' => 0,
            ];

            $this->info("📊 شروع پردازش از صفحه {$currentPage} تا {$endPage}");

            // نوار پیشرفت
            $progressBar = $this->output->createProgressBar($settings['pages_count']);
            $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% | صفحه: %message%');
            $progressBar->setMessage($currentPage);

            for ($page = $currentPage; $page <= $endPage; $page++) {
                try {
                    $progressBar->setMessage($page);

                    // پردازش صفحه
                    $pageResult = $apiService->processPage($page, $this->executionLog);

                    if ($pageResult) {
                        // بروزرسانی آمار
                        $this->updateStats($configStats, $pageResult);
                        $processedInConfig++;

                        // نمایش پیشرفت هر 10 صفحه
                        if ($page % 10 === 0) {
                            $this->displayProgress($page, $configStats);
                        }
                    } else {
                        $this->warn("⚠️ صفحه {$page} پردازش نشد");
                    }

                    $progressBar->advance();

                    // توقف کوتاه برای جلوگیری از فشار زیاد به سرور
                    usleep(500000); // 0.5 ثانیه

                } catch (\Exception $e) {
                    $this->error("❌ خطا در پردازش صفحه {$page}: " . $e->getMessage());
                    $configStats['total_failed']++;

                    // ادامه به صفحه بعدی
                    continue;
                }
            }

            $progressBar->finish();
            $this->newLine(2);

            // تکمیل اجرا
            $this->completeConfigExecution($config, $configStats);

        } catch (\Exception $e) {
            throw new \Exception("خطا در اجرای کرال: " . $e->getMessage(), 0, $e);
        }
    }

    private function updateStats(array &$configStats, array $pageResult)
    {
        foreach (['total_processed', 'total_success', 'total_enhanced', 'total_failed', 'total_duplicate'] as $key) {
            $configStats[$key] += $pageResult[$key] ?? 0;
            $this->totalStats[$key] += $pageResult[$key] ?? 0;
        }
    }

    private function displayProgress(int $page, array $stats)
    {
        $totalImpactful = $stats['total_success'] + $stats['total_enhanced'];
        $impactRate = $stats['total_processed'] > 0
            ? round(($totalImpactful / $stats['total_processed']) * 100, 1)
            : 0;

        $this->info("📈 صفحه {$page} | پردازش: {$stats['total_processed']} | تأثیرگذار: {$totalImpactful} ({$impactRate}%) | جدید: {$stats['total_success']} | بهبود: {$stats['total_enhanced']}");
    }

    private function completeConfigExecution(Config $config, array $stats)
    {
        $executionTime = microtime(true) - $this->startTime;

        // بروزرسانی آمار کانفیگ
        $config->updateProgress($config->last_source_id, $stats);

        // تکمیل ExecutionLog
        $finalStats = array_merge($stats, [
            'execution_time' => $executionTime
        ]);

        $this->executionLog->markCompleted($finalStats);

        $this->displayConfigSummary($config, $stats, $executionTime);
    }

    private function displayConfigSummary(Config $config, array $stats, float $executionTime)
    {
        $totalImpactful = $stats['total_success'] + $stats['total_enhanced'];
        $impactRate = $stats['total_processed'] > 0
            ? round(($totalImpactful / $stats['total_processed']) * 100, 1)
            : 0;

        $this->info("✅ تکمیل کانفیگ: {$config->source_name}");
        $this->info("📊 نتایج:");
        $this->line("   • کل پردازش شده: " . number_format($stats['total_processed']));
        $this->line("   • کتاب‌های جدید: " . number_format($stats['total_success']));
        $this->line("   • کتاب‌های بهبود یافته: " . number_format($stats['total_enhanced']));
        $this->line("   • کل تأثیرگذار: " . number_format($totalImpactful) . " ({$impactRate}%)");
        $this->line("   • خطا: " . number_format($stats['total_failed']));
        $this->line("   • تکراری: " . number_format($stats['total_duplicate']));
        $this->line("   • زمان اجرا: " . round($executionTime, 2) . " ثانیه");
        $this->newLine();
    }

    private function displayFinalSummary()
    {
        $totalTime = microtime(true) - $this->startTime;
        $totalImpactful = $this->totalStats['total_success'] + $this->totalStats['total_enhanced'];
        $overallImpactRate = $this->totalStats['total_processed'] > 0
            ? round(($totalImpactful / $this->totalStats['total_processed']) * 100, 1)
            : 0;

        $this->info("🎉 خلاصه نهایی کرال هوشمند:");
        $this->info("=" . str_repeat("=", 50));
        $this->line("📊 آمار کلی:");
        $this->line("   • کل رکوردهای پردازش شده: " . number_format($this->totalStats['total_processed']));
        $this->line("   • کتاب‌های جدید ایجاد شده: " . number_format($this->totalStats['total_success']));
        $this->line("   • کتاب‌های بهبود یافته: " . number_format($this->totalStats['total_enhanced']));
        $this->line("   • کل تأثیرگذار: " . number_format($totalImpactful) . " ({$overallImpactRate}%)");
        $this->line("   • رکوردهای ناموفق: " . number_format($this->totalStats['total_failed']));
        $this->line("   • رکوردهای تکراری: " . number_format($this->totalStats['total_duplicate']));
        $this->newLine();

        $this->line("⏱️ عملکرد:");
        $this->line("   • کل زمان اجرا: " . gmdate('H:i:s', (int)$totalTime));
        if ($this->totalStats['total_processed'] > 0) {
            $recordsPerSecond = round($this->totalStats['total_processed'] / $totalTime, 2);
            $this->line("   • سرعت پردازش: {$recordsPerSecond} رکورد/ثانیه");
        }
        $this->newLine();

        $this->line("🧠 ویژگی‌های بروزرسانی هوشمند:");
        $this->line("   ✅ تشخیص و تکمیل فیلدهای خالی");
        $this->line("   ✅ بهبود توضیحات ناقص");
        $this->line("   ✅ ادغام ISBN و نویسندگان جدید");
        $this->line("   ✅ بروزرسانی هش‌ها و تصاویر");
        $this->line("   ✅ محاسبه دقیق نرخ تأثیر");

        $this->info("✨ کرال هوشمند با موفقیت تمام شد! ✨");
    }
}
