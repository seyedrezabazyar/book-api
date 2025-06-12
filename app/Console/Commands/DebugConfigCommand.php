<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Config;
use App\Models\BookSource;
use App\Console\Helpers\CommandDisplayHelper;

class DebugConfigCommand extends Command
{
    protected $signature = 'config:debug
                          {config_id : ID کانفیگ}
                          {--last-id : نمایش جزئیات آخرین ID}
                          {--recommendations : نمایش پیشنهادات}';

    protected $description = 'Debug کامل کانفیگ شامل start_page و آخرین ID';

    private CommandDisplayHelper $displayHelper;

    public function __construct()
    {
        parent::__construct();
        $this->displayHelper = new CommandDisplayHelper($this);
    }

    public function handle(): int
    {
        $configId = $this->argument('config_id');
        $config = Config::find($configId);

        if (!$config) {
            $this->error("❌ کانفیگ با ID {$configId} یافت نشد!");
            return Command::FAILURE;
        }

        $this->displayHelper->displayWelcomeMessage("Debug کانفیگ: {$config->name}");

        $this->displayBasicInfo($config);
        $this->displayStartPageInfo($config);
        $this->displaySmartCalculations($config);
        $this->displayStatistics($config);
        $this->displayBookSourcesStats($config);

        if ($this->option('last-id')) {
            $this->displayLastIdDetails($config);
        }

        $this->displayStatusAnalysis($config);

        if ($this->option('recommendations')) {
            $this->displayRecommendations($config);
        }

        return Command::SUCCESS;
    }

    private function displayBasicInfo(Config $config): void
    {
        $this->displayHelper->displayStats([
            'نام' => $config->name,
            'منبع' => $config->source_name,
            'وضعیت' => $config->is_running ? 'در حال اجرا' : 'متوقف',
            'URL پایه' => $config->base_url ?? 'تعریف نشده',
            'فعال' => $config->is_active ? 'بله' : 'خیر'
        ], 'اطلاعات اصلی');
    }

    private function displayStartPageInfo(Config $config): void
    {
        $this->displayHelper->displayStats([
            'start_page در دیتابیس' => $config->start_page ?? 'null',
            'نوع start_page' => gettype($config->start_page),
            'توسط کاربر مشخص شده' => $config->hasUserDefinedStartPage() ? 'بله' : 'خیر',
            'مقدار برای فرم' => $config->getStartPageForForm() ?? 'null'
        ], 'اطلاعات start_page');
    }

    private function displaySmartCalculations(Config $config): void
    {
        $lastIdFromSources = $config->getLastSourceIdFromBookSources();
        $smartStartPage = $config->getSmartStartPage();

        $this->displayHelper->displayStats([
            'آخرین ID در book_sources' => $lastIdFromSources ?: 'هیچ',
            'Smart Start Page' => $smartStartPage,
            'last_source_id در کانفیگ' => $config->last_source_id ?? 'null',
            'auto_resume' => $config->auto_resume ? 'فعال' : 'غیرفعال'
        ], 'محاسبات هوشمند');
    }

    private function displayStatistics(Config $config): void
    {
        $this->displayHelper->displayStats([
            'کل پردازش شده' => number_format($config->total_processed ?? 0),
            'موفق' => number_format($config->total_success ?? 0),
            'ناموفق' => number_format($config->total_failed ?? 0),
            'آخرین اجرا' => $config->last_run_at ? $config->last_run_at->diffForHumans() : 'هرگز'
        ], 'آمار کانفیگ');
    }

    private function displayBookSourcesStats(Config $config): void
    {
        $sourceRecordsCount = BookSource::where('source_name', $config->source_name)->count();

        $stats = [
            'کل رکوردهای منبع' => number_format($sourceRecordsCount)
        ];

        if ($sourceRecordsCount > 0) {
            $minId = BookSource::where('source_name', $config->source_name)
                ->whereRaw('source_id REGEXP "^[0-9]+$"')
                ->min(DB::raw('CAST(source_id AS UNSIGNED)'));

            $maxId = BookSource::where('source_name', $config->source_name)
                ->whereRaw('source_id REGEXP "^[0-9]+$"')
                ->max(DB::raw('CAST(source_id AS UNSIGNED)'));

            $stats['محدوده ID ها'] = "{$minId} تا {$maxId}";

            $latestRecords = BookSource::where('source_name', $config->source_name)
                ->whereRaw('source_id REGEXP "^[0-9]+$"')
                ->orderByRaw('CAST(source_id AS UNSIGNED) DESC')
                ->limit(5)
                ->pluck('source_id')
                ->toArray();

            if (!empty($latestRecords)) {
                $stats['آخرین 5 ID'] = implode(', ', $latestRecords);
            }
        }

        $this->displayHelper->displayStats($stats, 'آمار book_sources');
    }

    private function displayLastIdDetails(Config $config): void
    {
        $this->info("🔍 جزئیات آخرین ID:");

        // نمایش آخرین 10 source_id
        $allSourceIds = BookSource::where('source_name', $config->source_name)
            ->whereRaw('source_id REGEXP "^[0-9]+$"')
            ->orderByRaw('CAST(source_id AS UNSIGNED) DESC')
            ->limit(10)
            ->pluck('source_id')
            ->toArray();

        $this->line("🔢 آخرین 10 source_id (مرتب‌سازی صحیح):");
        foreach ($allSourceIds as $sourceId) {
            $this->line("   • {$sourceId}");
        }
        $this->newLine();

        // مقایسه روش‌های مختلف
        $method1 = $config->getLastSourceIdFromBookSources();
        $method2 = BookSource::where('source_name', $config->source_name)
            ->whereRaw('source_id REGEXP "^[0-9]+$"')
            ->orderByRaw('CAST(source_id AS UNSIGNED) DESC')
            ->value('source_id');
        $method3 = BookSource::where('source_name', $config->source_name)
            ->whereRaw('source_id REGEXP "^[0-9]+$"')
            ->max(DB::raw('CAST(source_id AS UNSIGNED)'));

        $this->displayHelper->displayStats([
            'متد کانفیگ' => $method1,
            'Query مستقیم' => $method2 ?: 'null',
            'Max query' => $method3 ?: 'null',
            'Smart start page' => $config->getSmartStartPage(),
            'ID بعدی پیشنهادی' => $method1 + 1
        ], 'مقایسه روش‌های محاسبه');
    }

    private function displayStatusAnalysis(Config $config): void
    {
        $lastIdFromSources = $config->getLastSourceIdFromBookSources();

        $this->info("🔬 تحلیل وضعیت:");

        if ($config->start_page === null) {
            $this->line("   ✅ حالت هوشمند فعال (start_page = null)");
            if ($lastIdFromSources > 0) {
                $this->line("   📍 ادامه از ID " . ($lastIdFromSources + 1));
            } else {
                $this->line("   🆕 شروع جدید از ID 1");
            }
        } else {
            $this->line("   ⚙️ حالت دستی فعال (start_page = {$config->start_page})");
            $this->line("   📍 شروع از ID {$config->start_page}");

            if ($config->start_page <= $lastIdFromSources) {
                $this->line("   ⚠️ هشدار: این ID قبلاً پردازش شده!");
            }
        }
        $this->newLine();
    }

    private function displayRecommendations(Config $config): void
    {
        $lastIdFromSources = $config->getLastSourceIdFromBookSources();

        $this->info("💡 پیشنهادات:");

        if ($config->start_page && $config->start_page <= $lastIdFromSources) {
            $this->line("   • برای ادامه هوشمند: start_page را null کنید");
            $this->line("   • دستور: php artisan config:set-start-page {$config->id} --clear");
        }

        if ($config->start_page === null && $lastIdFromSources > 0) {
            $this->line("   • حالت هوشمند فعال - از ID " . ($lastIdFromSources + 1) . " ادامه خواهد یافت");
        }

        if ($config->start_page === 1 && $lastIdFromSources > 0) {
            $this->line("   • برای شروع مجدد از 1: درست تنظیم شده");
            $this->line("   • ⚠️ ID های 1 تا {$lastIdFromSources} دوباره پردازش خواهند شد");
        }

        $this->newLine();
    }
}
