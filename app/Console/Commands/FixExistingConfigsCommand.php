<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Config;
use Illuminate\Support\Facades\Log;

class FixExistingConfigsCommand extends Command
{
    protected $signature = 'config:fix-start-pages
                          {--dry-run : نمایش تغییرات بدون اعمال}
                          {--config-id= : ID کانفیگ خاص برای اصلاح}';

    protected $description = 'اصلاح start_page کانفیگ‌های موجود برای کارکرد صحیح getSmartStartPage';

    public function handle(): int
    {
        $this->info("🔧 شروع اصلاح کانفیگ‌های موجود");

        $dryRun = $this->option('dry-run');
        $configId = $this->option('config-id');

        try {
            $query = Config::query();

            if ($configId) {
                $query->where('id', $configId);
            }

            $configs = $query->get();

            if ($configs->isEmpty()) {
                $this->error("❌ هیچ کانفیگی یافت نشد!");
                return Command::FAILURE;
            }

            $this->info("📋 یافت شد: " . $configs->count() . " کانفیگ");

            if ($dryRun) {
                $this->warn("⚠️ حالت dry-run فعال - هیچ تغییری اعمال نمی‌شود");
            }

            $fixedCount = 0;
            $skippedCount = 0;

            foreach ($configs as $config) {
                $result = $this->processConfig($config, $dryRun);

                if ($result['fixed']) {
                    $fixedCount++;
                } else {
                    $skippedCount++;
                }
            }

            $this->newLine();
            $this->info("✅ اصلاح تمام شد:");
            $this->line("   • اصلاح شده: {$fixedCount}");
            $this->line("   • رد شده: {$skippedCount}");

            if ($dryRun && $fixedCount > 0) {
                $this->newLine();
                $this->warn("💡 برای اعمال تغییرات، دستور را بدون --dry-run اجرا کنید");
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ خطا در اصلاح کانفیگ‌ها: " . $e->getMessage());
            Log::error("خطا در FixExistingConfigsCommand", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    private function processConfig(Config $config, bool $dryRun): array
    {
        $this->info("🔍 بررسی کانفیگ: {$config->name} (ID: {$config->id})");

        try {
            $currentStartPage = $config->start_page;
            $lastIdFromSources = $config->getLastSourceIdFromBookSources();
            $smartStartPage = $config->getSmartStartPage();

            $this->line("   📊 وضعیت فعلی:");
            $this->line("      • start_page: " . ($currentStartPage ?? 'null'));
            $this->line("      • آخرین ID در book_sources: " . ($lastIdFromSources ?: 'هیچ'));
            $this->line("      • smart start page: {$smartStartPage}");
            $this->line("      • منبع: {$config->source_name}");

            // منطق اصلاح
            $needsFix = false;
            $newStartPage = null;
            $reason = '';

            if ($currentStartPage === 1 && $lastIdFromSources > 0) {
                // اگر start_page روی 1 است اما رکوردهایی در book_sources وجود دارد
                $needsFix = true;
                $newStartPage = null; // null می‌کنیم تا smart logic کار کند
                $reason = "start_page=1 اما {$lastIdFromSources} رکورد در book_sources وجود دارد";
            } elseif ($currentStartPage && $currentStartPage <= $lastIdFromSources) {
                // اگر start_page کمتر یا مساوی آخرین ID موجود است
                $needsFix = true;
                $newStartPage = null;
                $reason = "start_page={$currentStartPage} <= آخرین ID موجود ({$lastIdFromSources})";
            }

            if (!$needsFix) {
                $this->line("   ✅ نیازی به اصلاح ندارد");
                return ['fixed' => false, 'reason' => 'no_fix_needed'];
            }

            $this->line("   🔧 نیاز به اصلاح:");
            $this->line("      • دلیل: {$reason}");
            $this->line("      • start_page جدید: " . ($newStartPage ?? 'null (هوشمند)'));

            if (!$dryRun) {
                $config->update(['start_page' => $newStartPage]);

                // refresh برای دریافت smart start page جدید
                $config->refresh();
                $newSmartStartPage = $config->getSmartStartPage();

                $this->line("   ✅ اصلاح شد! smart start page جدید: {$newSmartStartPage}");

                Log::info("کانفیگ اصلاح شد", [
                    'config_id' => $config->id,
                    'config_name' => $config->name,
                    'old_start_page' => $currentStartPage,
                    'new_start_page' => $newStartPage,
                    'last_id_from_sources' => $lastIdFromSources,
                    'new_smart_start_page' => $newSmartStartPage,
                    'reason' => $reason
                ]);
            } else {
                $this->line("   📝 (dry-run) تغییر اعمال نشد");
            }

            return ['fixed' => true, 'reason' => $reason];

        } catch (\Exception $e) {
            $this->error("   ❌ خطا در پردازش کانفیگ {$config->id}: " . $e->getMessage());

            Log::error("خطا در پردازش کانفیگ", [
                'config_id' => $config->id,
                'error' => $e->getMessage()
            ]);

            return ['fixed' => false, 'reason' => 'error: ' . $e->getMessage()];
        }
    }
}
