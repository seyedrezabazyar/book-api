<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Config;

class DebugConfigCommand extends Command
{
    protected $signature = 'config:debug {config_id}';
    protected $description = 'Debug کانفیگ و نمایش وضعیت start_page';

    public function handle(): int
    {
        $configId = $this->argument('config_id');
        $config = Config::find($configId);

        if (!$config) {
            $this->error("❌ کانفیگ با ID {$configId} یافت نشد!");
            return Command::FAILURE;
        }

        $this->info("🔍 Debug کانفیگ: {$config->name} (ID: {$config->id})");
        $this->newLine();

        $this->displayBasicInfo($config);
        $this->displayStartPageInfo($config);
        $this->displaySmartCalculations($config);
        $this->displayStatistics($config);
        $this->displayBookSourcesStats($config);
        $this->displayStatusAnalysis($config);
        $this->displayRecommendations($config);

        return Command::SUCCESS;
    }

    private function displayBasicInfo(Config $config): void
    {
        $this->info("📊 اطلاعات اصلی:");
        $this->line("   • نام: {$config->name}");
        $this->line("   • منبع: {$config->source_name}");
        $this->line("   • وضعیت: " . ($config->is_running ? 'در حال اجرا' : 'متوقف'));
        $this->newLine();
    }

    private function displayStartPageInfo(Config $config): void
    {
        $this->info("🎯 اطلاعات start_page:");
        $this->line("   • start_page در دیتابیس: " . ($config->start_page ?? 'null'));
        $this->line("   • نوع start_page: " . gettype($config->start_page));
        $this->line("   • آیا توسط کاربر مشخص شده: " . ($config->hasUserDefinedStartPage() ? 'بله' : 'خیر'));
        $this->line("   • مقدار برای فرم: " . ($config->getStartPageForForm() ?? 'null'));
        $this->newLine();
    }

    private function displaySmartCalculations(Config $config): void
    {
        $lastIdFromSources = $config->getLastSourceIdFromBookSources();
        $smartStartPage = $config->getSmartStartPage();

        $this->info("🧠 محاسبات هوشمند:");
        $this->line("   • آخرین ID در book_sources: " . ($lastIdFromSources ?: 'هیچ'));
        $this->line("   • Smart Start Page: {$smartStartPage}");
        $this->line("   • last_source_id در کانفیگ: " . ($config->last_source_id ?? 'null'));
        $this->line("   • auto_resume: " . ($config->auto_resume ? 'فعال' : 'غیرفعال'));
        $this->newLine();
    }

    private function displayStatistics(Config $config): void
    {
        $this->info("📈 آمار کانفیگ:");
        $this->line("   • کل پردازش شده: " . number_format($config->total_processed ?? 0));
        $this->line("   • موفق: " . number_format($config->total_success ?? 0));
        $this->line("   • ناموفق: " . number_format($config->total_failed ?? 0));
        $this->newLine();
    }

    private function displayBookSourcesStats(Config $config): void
    {
        $sourceRecordsCount = \App\Models\BookSource::where('source_name', $config->source_name)->count();

        $this->info("📚 آمار book_sources:");
        $this->line("   • کل رکوردهای منبع: " . number_format($sourceRecordsCount));

        if ($sourceRecordsCount > 0) {
            // استفاده صحیح از DB::raw()
            $minId = \App\Models\BookSource::where('source_name', $config->source_name)
                ->whereRaw('source_id REGEXP "^[0-9]+$"')
                ->min(DB::raw('CAST(source_id AS UNSIGNED)'));

            $maxId = \App\Models\BookSource::where('source_name', $config->source_name)
                ->whereRaw('source_id REGEXP "^[0-9]+$"')
                ->max(DB::raw('CAST(source_id AS UNSIGNED)'));

            $this->line("   • محدوده ID ها: {$minId} تا {$maxId}");

            $latestRecords = \App\Models\BookSource::where('source_name', $config->source_name)
                ->whereRaw('source_id REGEXP "^[0-9]+$"')
                ->orderByRaw('CAST(source_id AS UNSIGNED) DESC')
                ->limit(5)
                ->pluck('source_id')
                ->toArray();

            if (!empty($latestRecords)) {
                $this->line("   • آخرین 5 ID: " . implode(', ', $latestRecords));
            }
        }
        $this->newLine();
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
    }

    private function displayRecommendations(Config $config): void
    {
        $lastIdFromSources = $config->getLastSourceIdFromBookSources();

        $this->newLine();
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
    }
}
