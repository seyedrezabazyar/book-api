<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Config;
use Illuminate\Support\Facades\Log;

class TestStartPageCommand extends Command
{
    protected $signature = 'config:test-start-page
                          {config_id : ID کانفیگ}
                          {--set-start= : مقدار جدید برای start_page}
                          {--clear : پاک کردن start_page (فعال‌سازی حالت هوشمند)}';

    protected $description = 'تست و تنظیم start_page کانفیگ';

    public function handle(): int
    {
        $configId = $this->argument('config_id');
        $config = Config::find($configId);

        if (!$config) {
            $this->error("❌ کانفیگ با ID {$configId} یافت نشد!");
            return Command::FAILURE;
        }

        $this->info("🔍 تست کانفیگ: {$config->name} (ID: {$config->id})");
        $this->newLine();

        // نمایش وضعیت فعلی
        $this->displayCurrentStatus($config);

        // اگر باید start_page تنظیم شود
        if ($this->option('set-start')) {
            return $this->setStartPage($config, $this->option('set-start'));
        }

        // اگر باید start_page پاک شود
        if ($this->option('clear')) {
            return $this->clearStartPage($config);
        }

        // فقط نمایش وضعیت
        return Command::SUCCESS;
    }

    private function displayCurrentStatus(Config $config): void
    {
        $this->info("📊 وضعیت فعلی:");

        $lastIdFromSources = $config->getLastSourceIdFromBookSources();
        $smartStartPage = $config->getSmartStartPage();
        $hasUserDefined = $config->hasUserDefinedStartPage();
        $formValue = $config->getStartPageForForm();

        $this->table(
            ['ویژگی', 'مقدار', 'توضیحات'],
            [
                ['start_page در دیتابیس', $config->start_page ?? 'NULL', $config->start_page ? 'مشخص شده توسط کاربر' : 'حالت هوشمند'],
                ['آخرین ID در book_sources', $lastIdFromSources ?: 'هیچ', 'آخرین ID ثبت شده'],
                ['Smart Start Page', $smartStartPage, 'ID که اجرای بعدی از آن شروع می‌شود'],
                ['Has User Defined', $hasUserDefined ? 'بله' : 'خیر', 'آیا کاربر start_page مشخص کرده؟'],
                ['Form Value', $formValue ?? 'خالی', 'مقدار نمایشی در فرم'],
                ['منبع', $config->source_name, 'نام منبع'],
                ['آخرین اجرا', $config->last_run_at ? $config->last_run_at->diffForHumans() : 'هرگز', 'زمان آخرین اجرا'],
            ]
        );

        $this->newLine();

        // تحلیل وضعیت
        $this->info("🧠 تحلیل منطق:");
        if ($hasUserDefined) {
            $this->line("   ✅ حالت دستی فعال: اجرای بعدی از ID {$config->start_page} شروع می‌شود");
            if ($config->start_page <= $lastIdFromSources) {
                $this->line("   ⚠️ هشدار: ID {$config->start_page} قبلاً پردازش شده! (ID های تکراری پردازش خواهند شد)");
            }
        } else {
            $this->line("   🧠 حالت هوشمند فعال: اجرای بعدی از ID {$smartStartPage} شروع می‌شود");
            if ($lastIdFromSources > 0) {
                $this->line("   📈 ادامه از آخرین ID ثبت شده");
            } else {
                $this->line("   🆕 شروع جدید از ID 1");
            }
        }

        $this->newLine();

        // آمار کلی
        $this->info("📈 آمار:");
        $sourceRecordsCount = \App\Models\BookSource::where('source_name', $config->source_name)->count();
        $totalProcessed = $config->total_processed ?? 0;
        $totalSuccess = $config->total_success ?? 0;
        $successRate = $totalProcessed > 0 ? round(($totalSuccess / $totalProcessed) * 100, 1) : 0;

        $this->line("   • کل رکوردهای منبع در book_sources: " . number_format($sourceRecordsCount));
        $this->line("   • کل پردازش شده توسط کانفیگ: " . number_format($totalProcessed));
        $this->line("   • کل موفق: " . number_format($totalSuccess));
        $this->line("   • نرخ موفقیت: {$successRate}%");

        $this->newLine();
    }

    private function setStartPage(Config $config, string $newValue): int
    {
        if (!is_numeric($newValue) || (int)$newValue <= 0) {
            $this->error("❌ مقدار start_page باید عدد مثبت باشد!");
            return Command::FAILURE;
        }

        $newStartPage = (int)$newValue;
        $lastIdFromSources = $config->getLastSourceIdFromBookSources();

        $this->info("🔧 تنظیم start_page به {$newStartPage}");

        if ($newStartPage <= $lastIdFromSources) {
            $this->warn("⚠️ هشدار: ID {$newStartPage} قبلاً پردازش شده!");
            $this->warn("   • آخرین ID پردازش شده: {$lastIdFromSources}");
            $this->warn("   • این باعث پردازش مجدد ID های تکراری خواهد شد");

            if (!$this->confirm('آیا می‌خواهید ادامه دهید؟')) {
                $this->info("عملیات لغو شد.");
                return Command::SUCCESS;
            }
        }

        try {
            $oldStartPage = $config->start_page;
            $config->update(['start_page' => $newStartPage]);

            $this->info("✅ start_page با موفقیت تغییر کرد!");
            $this->line("   • قدیم: " . ($oldStartPage ?? 'NULL'));
            $this->line("   • جدید: {$newStartPage}");
            $this->line("   • Smart Start Page جدید: " . $config->getSmartStartPage());

            Log::info("start_page از طریق command تغییر کرد", [
                'config_id' => $config->id,
                'old_start_page' => $oldStartPage,
                'new_start_page' => $newStartPage,
                'command_executed_by' => 'TestStartPageCommand'
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ خطا در تنظیم start_page: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function clearStartPage(Config $config): int
    {
        $this->info("🧹 پاک کردن start_page (فعال‌سازی حالت هوشمند)");

        if ($config->start_page === null) {
            $this->info("✅ حالت هوشمند قبلاً فعال است!");
            return Command::SUCCESS;
        }

        try {
            $oldStartPage = $config->start_page;
            $config->update(['start_page' => null]);

            $this->info("✅ حالت هوشمند فعال شد!");
            $this->line("   • قدیم: {$oldStartPage}");
            $this->line("   • جدید: NULL (هوشمند)");
            $this->line("   • Smart Start Page جدید: " . $config->getSmartStartPage());

            Log::info("start_page پاک شد و حالت هوشمند فعال شد", [
                'config_id' => $config->id,
                'old_start_page' => $oldStartPage,
                'new_start_page' => null,
                'command_executed_by' => 'TestStartPageCommand'
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ خطا در پاک کردن start_page: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
