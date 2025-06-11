<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Config;
use Illuminate\Support\Facades\Log;

class SetStartPageCommand extends Command
{
    protected $signature = 'config:set-start-page
                          {config_id : ID کانفیگ}
                          {start_page? : شماره start_page (خالی برای null)}
                          {--clear : پاک کردن start_page (تنظیم null)}
                          {--smart : فعال‌سازی حالت هوشمند}';

    protected $description = 'تنظیم start_page کانفیگ';

    public function handle(): int
    {
        $configId = $this->argument('config_id');
        $startPage = $this->argument('start_page');
        $clear = $this->option('clear');
        $smart = $this->option('smart');

        $config = Config::find($configId);

        if (!$config) {
            $this->error("❌ کانفیگ با ID {$configId} یافت نشد!");
            return Command::FAILURE;
        }

        $this->info("🔧 تنظیم start_page برای کانفیگ: {$config->name}");

        // بررسی وضعیت فعلی
        $oldStartPage = $config->start_page;
        $lastIdFromSources = $config->getLastSourceIdFromBookSources();

        $this->line("📊 وضعیت فعلی:");
        $this->line("   • start_page فعلی: " . ($oldStartPage ?? 'null'));
        $this->line("   • آخرین ID در book_sources: " . ($lastIdFromSources ?: 'هیچ'));
        $this->line("   • smart start page فعلی: " . $config->getSmartStartPage());
        $this->newLine();

        // تعیین مقدار جدید
        $newStartPage = null;

        if ($clear || $smart) {
            $newStartPage = null;
            $this->info("✅ حالت هوشمند فعال می‌شود (start_page = null)");
        } elseif ($startPage !== null) {
            if (!is_numeric($startPage) || (int)$startPage <= 0) {
                $this->error("❌ start_page باید عدد مثبت باشد!");
                return Command::FAILURE;
            }
            $newStartPage = (int)$startPage;
            $this->info("⚙️ حالت دستی: start_page = {$newStartPage}");
        } else {
            // اگر هیچ پارامتری داده نشده، سوال بپرس
            $this->info("💡 گزینه‌های موجود:");
            $this->line("1. حالت هوشمند (ادامه از آخرین ID)");
            $this->line("2. شروع از ID مشخص");

            $choice = $this->choice('کدام گزینه را انتخاب می‌کنید؟', [
                '1' => 'حالت هوشمند',
                '2' => 'شروع از ID مشخص'
            ]);

            if ($choice === '1') {
                $newStartPage = null;
                $this->info("✅ حالت هوشمند انتخاب شد");
            } else {
                $inputStartPage = $this->ask('شماره start_page را وارد کنید');
                if (!is_numeric($inputStartPage) || (int)$inputStartPage <= 0) {
                    $this->error("❌ شماره نامعتبر!");
                    return Command::FAILURE;
                }
                $newStartPage = (int)$inputStartPage;
                $this->info("⚙️ start_page = {$newStartPage} تنظیم شد");
            }
        }

        // هشدارها
        if ($newStartPage && $newStartPage <= $lastIdFromSources) {
            $this->warn("⚠️ هشدار: ID {$newStartPage} قبلاً پردازش شده! ID های تکراری پردازش خواهند شد.");
            if (!$this->confirm('آیا ادامه می‌دهید؟')) {
                $this->info("عملیات لغو شد.");
                return Command::SUCCESS;
            }
        }

        // اعمال تغییر
        try {
            $config->update(['start_page' => $newStartPage]);

            // refresh و نمایش نتیجه
            $config->refresh();
            $newSmartStartPage = $config->getSmartStartPage();

            $this->newLine();
            $this->info("✅ تغییرات اعمال شد!");
            $this->line("📋 نتیجه:");
            $this->line("   • start_page قدیم: " . ($oldStartPage ?? 'null'));
            $this->line("   • start_page جدید: " . ($newStartPage ?? 'null'));
            $this->line("   • smart start page جدید: {$newSmartStartPage}");

            if ($newStartPage === null) {
                $this->line("   ✅ حالت هوشمند فعال - از ID {$newSmartStartPage} ادامه خواهد یافت");
            } else {
                $this->line("   ⚙️ حالت دستی فعال - از ID {$newStartPage} شروع خواهد شد");
            }

            Log::info("start_page کانفیگ تغییر کرد", [
                'config_id' => $config->id,
                'config_name' => $config->name,
                'old_start_page' => $oldStartPage,
                'new_start_page' => $newStartPage,
                'new_smart_start_page' => $newSmartStartPage,
                'last_id_from_sources' => $lastIdFromSources,
                'changed_via_command' => true
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ خطا در اعمال تغییرات: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
