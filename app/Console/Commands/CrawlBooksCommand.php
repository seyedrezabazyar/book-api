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
                          {--enhanced-only : فقط بروزرسانی کتاب‌های موجود}
                          {--force-update : آپدیت اجباری کتاب‌های موجود حتی اگر source قبلاً ثبت شده}
                          {--fill-missing : فقط کتاب‌هایی که فیلدهای خالی دارند آپدیت شوند}
                          {--reprocess-threshold=3 : حداقل امتیاز برای آپدیت مجدد (1-10)}
                          {--debug : نمایش اطلاعات تشخیصی بیشتر}';

    protected $description = 'اجرای کرال هوشمند با الگوی بهبود یافته درج و به‌روزرسانی کتاب‌ها بر اساس MD5';

    public function __construct(private CommandStatsTracker $statsTracker)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->displayWelcomeMessage();

        try {
            $configs = $this->determineConfigs();

            if ($configs->isEmpty()) {
                $this->error("❌ هیچ کانفیگ فعالی برای اجرا یافت نشد!");
                return Command::FAILURE;
            }

            // تنظیم force update بر اساس options
            $this->configureForceUpdateSettings($configs);

            $this->displayConfigsInfo($configs);

            foreach ($configs as $config) {
                if ($this->shouldSkipConfig($config)) {
                    $this->warn("⏭️ رد شدن کانفیگ {$config->id} ({$config->source_name})");
                    continue;
                }

                $this->processConfigWithNewLogic($config);
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

    private function displayWelcomeMessage(): void
    {
        $this->info("🚀 شروع کرال هوشمند با الگوی بهبود یافته");
        $this->info("⏰ زمان شروع: " . now()->format('Y-m-d H:i:s'));
        $this->newLine();

        // نمایش تنظیمات فعال
        $activeSettings = [];
        if ($this->option('force-update')) $activeSettings[] = "Force Update";
        if ($this->option('fill-missing')) $activeSettings[] = "Fill Missing Fields";
        if ($this->option('enhanced-only')) $activeSettings[] = "Enhanced Only";

        if (!empty($activeSettings)) {
            $this->info("🔧 تنظیمات فعال: " . implode(', ', $activeSettings));
        }

        if ($this->option('reprocess-threshold') != 3) {
            $this->info("🎯 حداقل امتیاز آپدیت: " . $this->option('reprocess-threshold'));
        }

        if (!$this->option('debug')) return;

        $this->line("🧠 ویژگی‌های منطق جدید:");
        $this->line("   ✨ شناسایی کتاب‌ها بر اساس MD5 منحصر‌به‌فرد");
        $this->line("   🔄 به‌روزرسانی هوشمند کتاب‌های موجود");
        $this->line("   📚 اضافه کردن نویسندگان و ISBN های جدید بدون حذف قدیمی‌ها");
        $this->line("   🔗 ثبت منابع متعدد برای هر کتاب");
        $this->line("   💎 بهبود توضیحات و تکمیل فیلدهای خالی");
        $this->line("   🔄 پردازش مجدد source های موجود در صورت نیاز");
        $this->line("   📊 آمارگیری دقیق شامل نرخ بهبود (Enhancement Rate)");
        $this->newLine();
    }

    /**
     * پیکربندی تنظیمات force update
     */
    private function configureForceUpdateSettings($configs): void
    {
        foreach ($configs as $config) {
            $generalSettings = $config->getGeneralSettings();

            // تنظیم force_reprocess
            if ($this->option('force-update')) {
                $generalSettings['force_reprocess'] = true;
                $this->info("✅ Force Update فعال شد برای کانفیگ {$config->id}");
            }

            // تنظیم fill_missing_fields
            if ($this->option('fill-missing')) {
                $config->fill_missing_fields = true;
                $this->info("✅ Fill Missing Fields فعال شد برای کانفیگ {$config->id}");
            }

            // تنظیم enhanced_only
            if ($this->option('enhanced-only')) {
                $generalSettings['enhanced_only'] = true;
                $this->info("✅ Enhanced Only فعال شد برای کانفیگ {$config->id}");
            }

            // تنظیم reprocess threshold
            $threshold = (int)$this->option('reprocess-threshold');
            if ($threshold >= 1 && $threshold <= 10) {
                $generalSettings['reprocess_threshold'] = $threshold;
            }

            // ذخیره تنظیمات
            $configData = $config->config_data ?? [];
            $configData['general'] = $generalSettings;
            $config->config_data = $configData;
            $config->save();
        }
    }

    private function displayConfigsInfo($configs): void
    {
        $this->info("📋 تعداد کانفیگ‌های قابل اجرا: " . $configs->count());

        if (!$this->option('debug')) return;

        $this->newLine();
        $this->line("🔧 جزئیات کانفیگ‌ها:");

        foreach ($configs as $config) {
            $lastId = $config->getLastSourceIdFromBookSources();
            $smartStart = $config->getSmartStartPage();
            $generalSettings = $config->getGeneralSettings();

            $this->line("   • {$config->source_name} (ID: {$config->id})");
            $this->line("     - آخرین ID در منابع: " . ($lastId ?: 'هیچ'));
            $this->line("     - شروع هوشمند: {$smartStart}");
            $this->line("     - start_page کاربر: " . ($config->start_page ?: 'خودکار'));

            // نمایش تنظیمات force update
            $forceSettings = [];
            if (!empty($generalSettings['force_reprocess'])) $forceSettings[] = "Force Reprocess";
            if ($config->fill_missing_fields) $forceSettings[] = "Fill Missing";
            if ($config->update_descriptions) $forceSettings[] = "Update Descriptions";
            if (!empty($generalSettings['enhanced_only'])) $forceSettings[] = "Enhanced Only";

            if (!empty($forceSettings)) {
                $this->line("     - تنظیمات فعال: " . implode(', ', $forceSettings));
            }

            if (!empty($generalSettings['reprocess_threshold'])) {
                $this->line("     - حداقل امتیاز آپدیت: " . $generalSettings['reprocess_threshold']);
            }
        }
        $this->newLine();
    }

    private function processConfigWithNewLogic(Config $config): void
    {
        $this->info("🔄 شروع پردازش کانفیگ: {$config->source_name} (ID: {$config->id})");

        try {
            $executionLog = $this->statsTracker->createExecutionLog($config);
            $crawlSettings = $this->determineCrawlSettings($config);

            $this->displayCrawlSettings($crawlSettings, $config);
            $this->performIntelligentCrawl($config, $crawlSettings, $executionLog);

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

        if ($this->option('debug') && !(int)$this->option('start-page')) {
            $lastId = $config->getLastSourceIdFromBookSources();
            $this->line("🎯 تشخیص خودکار:");
            $this->line("   • آخرین ID در book_sources: " . ($lastId ?: 'هیچ'));
            $this->line("   • start_page کاربر: " . ($config->start_page ?: 'خودکار'));
            $this->line("   • نقطه شروع نهایی: {$startPage}");
        }

        return [
            'start_page' => $startPage,
            'pages_count' => $pagesCount,
            'enhanced_only' => $this->option('enhanced-only') || !empty($generalSettings['enhanced_only']),
            'force_update' => $this->option('force-update') || !empty($generalSettings['force_reprocess']),
            'fill_missing_only' => $this->option('fill-missing'),
            'reprocess_threshold' => $generalSettings['reprocess_threshold'] ?? 3,
            'intelligent_update_enabled' => true,
            'md5_based_processing' => true,
            'batch_size' => 50,
            'debug_mode' => $this->option('debug')
        ];
    }

    private function displayCrawlSettings(array $settings, Config $config): void
    {
        $this->info("⚙️ تنظیمات اجرا:");
        $this->line("   • صفحه شروع: {$settings['start_page']}");
        $this->line("   • تعداد صفحات: {$settings['pages_count']}");
        $this->line("   • حالت فقط بهبود: " . ($settings['enhanced_only'] ? 'بله' : 'خیر'));
        $this->line("   • آپدیت اجباری: " . ($settings['force_update'] ? 'بله' : 'خیر'));
        $this->line("   • فقط فیلدهای خالی: " . ($settings['fill_missing_only'] ? 'بله' : 'خیر'));
        $this->line("   • حداقل امتیاز آپدیت: {$settings['reprocess_threshold']}");
        $this->line("   • بروزرسانی هوشمند: فعال");
        $this->line("   • پردازش مبتنی بر MD5: فعال");

        if ($settings['debug_mode']) {
            $this->line("   • حالت debug: فعال");
            $this->line("   • منبع: {$config->source_name}");
            $this->line("   • URL پایه: {$config->base_url}");
        }

        // هشدار برای force update
        if ($settings['force_update']) {
            $this->warn("⚠️ حالت Force Update فعال است - تمام source های موجود مجدداً پردازش خواهند شد");
        }

        $this->newLine();
    }

    private function performIntelligentCrawl(Config $config, array $settings, $executionLog): void
    {
        $apiService = new ApiDataService($config);
        $currentPage = $settings['start_page'];
        $endPage = $currentPage + $settings['pages_count'] - 1;

        $this->info("📊 شروع پردازش از source ID {$currentPage} تا {$endPage}");

        if ($settings['debug_mode']) {
            $this->line("🔍 منطق پردازش:");
            $this->line("   1️⃣ محاسبه MD5 برای هر کتاب");
            $this->line("   2️⃣ جستجوی کتاب با MD5 در دیتابیس");
            $this->line("   3️⃣ اگر وجود نداشت: ثبت کامل");
            $this->line("   4️⃣ اگر وجود داشت: مقایسه هوشمند و بهبود");
            $this->line("   5️⃣ ثبت منبع جدید در هر حالت");

            if ($settings['force_update']) {
                $this->line("   🔄 حالت Force Update: حتی source های موجود نیز پردازش می‌شوند");
            }
        }

        $progressBar = $this->output->createProgressBar($settings['pages_count']);
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% | ID: %message% | 🆕:%created% 🔧:%enhanced% 📋:%duplicate% 🔄:%reprocessed%');

        $currentStats = ['created' => 0, 'enhanced' => 0, 'duplicate' => 0, 'reprocessed' => 0];

        for ($page = $currentPage; $page <= $endPage; $page++) {
            try {
                $progressBar->setMessage($page);

                $pageResult = $apiService->processSourceId($page, $executionLog);

                if ($pageResult) {
                    $this->statsTracker->updateStats($pageResult);
                    $this->updateProgressStats($currentStats, $pageResult, $progressBar);

                    if ($page % 10 === 0) {
                        $this->displayDetailedProgress($page, $settings);
                    }

                    if ($settings['debug_mode'] && in_array($pageResult['action'] ?? '', [
                            'created', 'enhanced', 'enriched', 'merged', 'reprocess_for_update', 'force_reprocess'
                        ])) {
                        $this->displayDebugInfo($page, $pageResult);
                    }
                }

                $progressBar->advance();
                usleep(500000);

            } catch (\Exception $e) {
                $this->error("❌ خطا در پردازش صفحه {$page}: " . $e->getMessage());

                if ($settings['debug_mode']) {
                    $this->line("🔍 جزئیات خطا: " . $e->getFile() . ':' . $e->getLine());
                }

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
            case 'reprocess_for_update':
            case 'reprocess_for_minor_update':
            case 'force_reprocess':
                $currentStats['reprocessed']++;
                break;
            default:
                $currentStats['duplicate']++;
                break;
        }

        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% | ID: %message% | 🆕:' .
            $currentStats['created'] . ' 🔧:' . $currentStats['enhanced'] .
            ' 📋:' . $currentStats['duplicate'] . ' 🔄:' . $currentStats['reprocessed']);
    }

    private function displayDetailedProgress(int $page, array $settings): void
    {
        $stats = $this->statsTracker->getCurrentStats();

        $totalImpactful = $stats['total_success'] + $stats['total_enhanced'];
        $impactRate = $stats['total_processed'] > 0
            ? round(($totalImpactful / $stats['total_processed']) * 100, 1)
            : 0;

        $enhancementRate = $stats['total_processed'] > 0
            ? round(($stats['total_enhanced'] / $stats['total_processed']) * 100, 1)
            : 0;

        $reprocessRate = isset($stats['total_reprocessed']) && $stats['total_processed'] > 0
            ? round(($stats['total_reprocessed'] / $stats['total_processed']) * 100, 1)
            : 0;

        $this->info("📈 صفحه {$page} | کل: {$stats['total_processed']} | تأثیرگذار: {$totalImpactful} ({$impactRate}%) | " .
            "جدید: {$stats['total_success']} | بهبود: {$stats['total_enhanced']} ({$enhancementRate}%) | " .
            "پردازش مجدد: " . ($stats['total_reprocessed'] ?? 0) . " ({$reprocessRate}%)");
    }

    private function displayDebugInfo(int $page, array $pageResult): void
    {
        $action = $pageResult['action'] ?? 'unknown';
        $title = isset($pageResult['title']) ? \Illuminate\Support\Str::limit($pageResult['title'], 40) : 'N/A';

        $actionEmojis = [
            'created' => '🆕',
            'enhanced' => '🔧',
            'enriched' => '💎',
            'merged' => '🔗',
            'reprocess_for_update' => '🔄',
            'reprocess_for_minor_update' => '🔄',
            'force_reprocess' => '💪'
        ];

        $emoji = $actionEmojis[$action] ?? '❓';
        $this->line("\n{$emoji} ID {$page}: {$action} - {$title}");
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
