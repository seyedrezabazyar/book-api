<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Config;
use App\Services\ApiDataService;
use App\Services\CommandStatsTracker;
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

    public function __construct(private CommandStatsTracker $statsTracker)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info("🚀 شروع کرال هوشمند کتاب‌ها");
        $this->info("⏰ زمان شروع: " . now()->format('Y-m-d H:i:s'));

        try {
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

                $this->processConfig($config);
            }

            $this->statsTracker->displayFinalSummary();
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

        return Config::where('is_active', true)->get();
    }

    private function shouldSkipConfig(Config $config): bool
    {
        if (!$this->option('force')) {
            if ($config->is_running) {
                $this->warn("⚠️ کانفیگ {$config->id} در حال اجرا است.");
                return true;
            }
        }

        return false;
    }

    private function processConfig(Config $config): void
    {
        $this->info("🔄 شروع پردازش کانفیگ: {$config->source_name} (ID: {$config->id})");

        try {
            $executionLog = $this->statsTracker->createExecutionLog($config);
            $crawlSettings = $this->determineCrawlSettings($config);

            $this->displayCrawlSettings($crawlSettings);
            $this->performCrawl($config, $crawlSettings, $executionLog);

        } catch (\Exception $e) {
            $this->error("❌ خطا در پردازش کانفیگ {$config->id}: " . $e->getMessage());
            Log::error("خطا در پردازش کانفیگ", [
                'config_id' => $config->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function determineCrawlSettings(Config $config): array
    {
        $startPage = (int)$this->option('start-page');
        $pagesCount = (int)$this->option('pages');

        if ($startPage <= 0) {
            $startPage = $config->getSmartStartPage();
        }

        if ($pagesCount <= 0) {
            $pagesCount = $config->max_pages ?: 100;
        }

        return [
            'start_page' => $startPage,
            'pages_count' => $pagesCount,
            'enhanced_only' => $this->option('enhanced-only'),
            'smart_update_enabled' => true,
            'batch_size' => 50,
        ];
    }

    private function displayCrawlSettings(array $settings): void
    {
        $this->info("⚙️ تنظیمات اجرا:");
        $this->line("   • صفحه شروع: {$settings['start_page']}");
        $this->line("   • تعداد صفحات: {$settings['pages_count']}");
        $this->line("   • حالت فقط بهبود: " . ($settings['enhanced_only'] ? 'بله' : 'خیر'));
        $this->line("   • بروزرسانی هوشمند: فعال");
    }

    private function performCrawl(Config $config, array $settings, $executionLog): void
    {
        $apiService = new ApiDataService($config);
        $currentPage = $settings['start_page'];
        $endPage = $currentPage + $settings['pages_count'] - 1;

        $progressBar = $this->output->createProgressBar($settings['pages_count']);
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% | صفحه: %message%');

        for ($page = $currentPage; $page <= $endPage; $page++) {
            try {
                $progressBar->setMessage($page);

                $pageResult = $apiService->processSourceId($page, $executionLog);

                if ($pageResult) {
                    $this->statsTracker->updateStats($pageResult);

                    if ($page % 10 === 0) {
                        $this->displayProgress($page);
                    }
                }

                $progressBar->advance();
                usleep(500000); // 0.5 ثانیه تاخیر

            } catch (\Exception $e) {
                $this->error("❌ خطا در پردازش صفحه {$page}: " . $e->getMessage());
                continue;
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->statsTracker->completeConfigExecution($config, $executionLog);
    }

    private function displayProgress(int $page): void
    {
        $stats = $this->statsTracker->getCurrentStats();
        $impactRate = $stats['total_processed'] > 0
            ? round((($stats['total_success'] + $stats['total_enhanced']) / $stats['total_processed']) * 100, 1)
            : 0;

        $this->info("📈 صفحه {$page} | پردازش: {$stats['total_processed']} | تأثیرگذار: {$impactRate}% | جدید: {$stats['total_success']} | بهبود: {$stats['total_enhanced']}");
    }
}
