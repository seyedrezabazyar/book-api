<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Config;
use App\Console\Helpers\CommandDisplayHelper;
use Illuminate\Support\Facades\Log;

class SetStartPageCommand extends Command
{
    protected $signature = 'config:set-start-page
                          {config_id : ID کانفیگ}
                          {start_page? : شماره start_page (خالی برای null)}
                          {--clear : پاک کردن start_page (تنظیم null)}
                          {--smart : فعال‌سازی حالت هوشمند}
                          {--test : فقط نمایش وضعیت فعلی بدون تغییر}';

    protected $description = 'تنظیم و تست start_page کانفیگ';

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

        $this->displayHelper->displayWelcomeMessage("تنظیم start_page برای کانفیگ: {$config->name}");

        // نمایش وضعیت فعلی
        $this->displayCurrentStatus($config);

        // اگر فقط تست است
        if ($this->option('test')) {
            return Command::SUCCESS;
        }

        // تعیین عملیات
        if ($this->option('clear') || $this->option('smart')) {
            return $this->clearStartPage($config);
        }

        $startPage = $this->argument('start_page');
        if ($startPage !== null) {
            return $this->setStartPage($config, $startPage);
        }

        // سوال از کاربر
        return $this->interactiveSetup($config);
    }

    private function displayCurrentStatus(Config $config): void
    {
        $lastIdFromSources = $config->getLastSourceIdFromBookSources();
        $smartStartPage = $config->getSmartStartPage();
        $hasUserDefined = $config->hasUserDefinedStartPage();
        $formValue = $config->getStartPageForForm();

        $this->displayHelper->displayStats([
            'start_page در دیتابیس' => $config->start_page ?? 'NULL',
            'آخرین ID در book_sources' => $lastIdFromSources ?: 'هیچ',
            'Smart Start Page' => $smartStartPage,
            'Has User Defined' => $hasUserDefined ? 'بله' : 'خیر',
            'Form Value' => $formValue ?? 'خالی',
            'منبع' => $config->source_name,
            'آخرین اجرا' => $config->last_run_at ? $config->last_run_at->diffForHumans() : 'هرگز'
        ], 'وضعیت فعلی');

        // تحلیل وضعیت
        $this->info("🧠 تحلیل منطق:");
        if ($hasUserDefined) {
            $this->line("   ✅ حالت دستی فعال: اجرای بعدی از ID {$config->start_page} شروع می‌شود");
            if ($config->start_page <= $lastIdFromSources) {
                $this->line("   ⚠️ هشدار: ID {$config->start_page} قبلاً پردازش شده!");
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
    }

    private function interactiveSetup(Config $config): int
    {
        $this->info("💡 گزینه‌های موجود:");
        $this->line("1. حالت هوشمند (ادامه از آخرین ID)");
        $this->line("2. شروع از ID مشخص");

        $choice = $this->choice('کدام گزینه را انتخاب می‌کنید؟', [
            '1' => 'حالت هوشمند',
            '2' => 'شروع از ID مشخص'
        ]);

        if ($choice === '1') {
            return $this->clearStartPage($config);
        } else {
            $inputStartPage = $this->ask('شماره start_page را وارد کنید');
            if (!is_numeric($inputStartPage) || (int)$inputStartPage <= 0) {
                $this->error("❌ شماره نامعتبر!");
                return Command::FAILURE;
            }
            return $this->setStartPage($config, $inputStartPage);
        }
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
                'command_executed_by' => 'SetStartPageCommand'
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
                'command_executed_by' => 'SetStartPageCommand'
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ خطا در پاک کردن start_page: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
