<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Config;
use App\Services\ApiDataService;
use App\Services\CommandStatsTracker;
use App\Console\Helpers\CommandDisplayHelper;
use Illuminate\Support\Facades\Log;

class CrawlBooksCommand extends Command
{
    protected $signature = 'crawl:books
                          {config? : ID کانفیگ برای اجرا (اختیاری)}
                          {--start-page=0 : صفحه شروع (0 = تشخیص خودکار)}
                          {--pages=0 : تعداد صفحات (0 = بدون محدودیت)}
                          {--force : اجرای اجباری حتی در صورت وجود اجرای فعال}
                          {--enhanced-only : فقط بروزرسانی کتاب‌های موجود}
                          {--force-update : آپدیت اجباری کتاب‌های موجود}
                          {--fill-missing : فقط کتاب‌هایی که فیلدهای خالی دارند آپدیت شوند}
                          {--reprocess-threshold=3 : حداقل امتیاز برای آپدیت مجدد (1-10)}
                          {--debug : نمایش اطلاعات تشخیصی بیشتر}';

    protected $description = 'اجرای کرال هوشمند با الگوی بهبود یافته درج و به‌روزرسانی کتاب‌ها';

    private CommandStatsTracker $statsTracker;
    private CommandDisplayHelper $displayHelper;

    public function __construct(CommandStatsTracker $statsTracker)
    {
        parent::__construct();
        $this->statsTracker = $statsTracker;
        $this->displayHelper = new CommandDisplayHelper($this);
    }

    public function handle(): int
    {
        $activeSettings = $this->getActiveSettings();
        $this->displayHelper->displayWelcomeMessage(
            'شروع کرال هوشمند',
            $activeSettings,
            $this->option('debug')
        );

        try {
            $configs = $this->determineConfigs();

            if ($configs->isEmpty()) {
                $this->error("❌ هیچ کانفیگ فعالی برای اجرا یافت نشد!");
                return Command::FAILURE;
            }

            $this->configureSettings($configs);
            $this->displayConfigsInfo($configs);

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

    private function getActiveSettings(): array
    {
        $settings = [];
        if ($this->option('force-update')) $settings[] = "Force Update";
        if ($this->option('fill-missing')) $settings[] = "Fill Missing Fields";
        if ($this->option('enhanced-only')) $settings[] = "Enhanced Only";
        if ($this->option('reprocess-threshold') != 3) {
            $settings[] = "Min Score: " . $this->option('reprocess-threshold');
        }
        return $settings;
    }

    private function configureSettings($configs): void
    {
        foreach ($configs as $config) {
            $generalSettings = $config->getGeneralSettings();

            if ($this->option('force-update')) {
                $generalSettings['force_reprocess'] = true;
            }

            if ($this->option('fill-missing')) {
                $config->fill_missing_fields = true;
            }

            if ($this->option('enhanced-only')) {
                $generalSettings['enhanced_only'] = true;
            }

            $threshold = (int)$this->option('reprocess-threshold');
            if ($threshold >= 1 && $threshold <= 10) {
                $generalSettings['reprocess_threshold'] = $threshold;
            }

            $configData = $config->config_data ?? [];
            $configData['general'] = $generalSettings;
            $config->config_data = $configData;
            $config->save();
        }
    }

    private function displayConfigsInfo($configs): void
    {
        $this->info("📋 تعداد کانفیگ‌های قابل اجرا: " . $configs->count());

        if ($this->option('debug')) {
            foreach ($configs as $config) {
                $this->displayHelper->displayConfigInfo($config, true);
            }
        }
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
        $startPage = (int)$this->option('start-page') ?: $config->getSmartStartPage();
        $pagesCount = (int)$this->option('pages') ?: $config->max_pages ?: 100;
        $generalSettings = $config->getGeneralSettings();

        return [
            'start_page' => $startPage,
            'pages_count' => $pagesCount,
            'enhanced_only' => $this->option('enhanced-only') || !empty($generalSettings['enhanced_only']),
            'force_update' => $this->option('force-update') || !empty($generalSettings['force_reprocess']),
            'fill_missing_only' => $this->option('fill-missing'),
            'reprocess_threshold' => $generalSettings['reprocess_threshold'] ?? 3,
            'debug_mode' => $this->option('debug')
        ];
    }

    private function displayCrawlSettings(array $settings): void
    {
        $this->displayHelper->displayStats([
            'صفحه شروع' => $settings['start_page'],
            'تعداد صفحات' => $settings['pages_count'],
            'فقط بهبود' => $settings['enhanced_only'] ? 'بله' : 'خیر',
            'آپدیت اجباری' => $settings['force_update'] ? 'بله' : 'خیر',
            'فقط فیلدهای خالی' => $settings['fill_missing_only'] ? 'بله' : 'خیر',
            'حداقل امتیاز آپدیت' => $settings['reprocess_threshold']
        ], 'تنظیمات اجرا');

        if ($settings['force_update']) {
            $this->warn("⚠️ حالت Force Update فعال است");
        }
    }

    private function performCrawl(Config $config, array $settings, $executionLog): void
    {
        $apiService = new ApiDataService($config);
        $currentPage = $settings['start_page'];
        $endPage = $currentPage + $settings['pages_count'] - 1;

        $this->info("📊 شروع پردازش از source ID {$currentPage} تا {$endPage}");

        $progressBar = $this->output->createProgressBar($settings['pages_count']);
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% | ID: %message% | 🆕:%created% 🔧:%enhanced% 📋:%duplicate%');

        $currentStats = ['created' => 0, 'enhanced' => 0, 'duplicate' => 0];

        for ($page = $currentPage; $page <= $endPage; $page++) {
            try {
                $progressBar->setMessage($page);

                $pageResult = $apiService->processSourceId($page, $executionLog);

                if ($pageResult) {
                    $this->statsTracker->updateStats($pageResult);
                    $this->updateProgressStats($currentStats, $pageResult, $progressBar);

                    if ($page % 25 === 0) {
                        $this->displayHelper->displayIntermediateProgress(
                            $page - $currentPage + 1,
                            $currentStats,
                            $settings['debug_mode']
                        );
                    }
                }

                $progressBar->advance();
                usleep(500000);

            } catch (\Exception $e) {
                $this->error("❌ خطا در پردازش صفحه {$page}: " . $e->getMessage());
                continue;
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->statsTracker->completeConfigExecution($config, $executionLog);
    }

    private function updateProgressStats(array &$currentStats, array $pageResult, $progressBar): void
    {
        $action = $pageResult['action'] ?? 'unknown';

        switch ($action) {
            case 'created':
                $currentStats['created']++;
                break;
            case 'enhanced':
            case 'enriched':
            case 'merged':
                $currentStats['enhanced']++;
                break;
            default:
                $currentStats['duplicate']++;
                break;
        }

        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% | ID: %message% | 🆕:' .
            $currentStats['created'] . ' 🔧:' . $currentStats['enhanced'] . ' 📋:' . $currentStats['duplicate']);
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
        return !$this->option('force') && $config->is_running;
    }
}
