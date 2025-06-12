<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Config;
use App\Console\Helpers\CommandDisplayHelper;
use Illuminate\Support\Facades\Log;

class FixExistingConfigsCommand extends Command
{
    protected $signature = 'config:fix-start-pages
                          {--dry-run : نمایش تغییرات بدون اعمال}
                          {--config-id= : ID کانفیگ خاص برای اصلاح}';

    protected $description = 'اصلاح start_page کانفیگ‌های موجود برای کارکرد صحیح getSmartStartPage';

    private CommandDisplayHelper $displayHelper;

    public function __construct()
    {
        parent::__construct();
        $this->displayHelper = new CommandDisplayHelper($this);
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $configId = $this->option('config-id');

        $activeSettings = [];
        if ($dryRun) $activeSettings[] = "Dry Run";
        if ($configId) $activeSettings[] = "Config ID: {$configId}";

        $this->displayHelper->displayWelcomeMessage(
            'اصلاح کانفیگ‌های موجود',
            $activeSettings
        );

        try {
            $configs = $this->getConfigs($configId);

            if ($configs->isEmpty()) {
                $this->error("❌ هیچ کانفیگی یافت نشد!");
                return Command::FAILURE;
            }

            $this->info("📋 یافت شد: " . $configs->count() . " کانفیگ");

            $results = $this->processConfigs($configs, $dryRun);

            $this->displayResults($results, $dryRun);

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

    private function getConfigs(?string $configId)
    {
        $query = Config::query();

        if ($configId) {
            $query->where('id', $configId);
        }

        return $query->get();
    }

    private function processConfigs($configs, bool $dryRun): array
    {
        $fixedCount = 0;
        $skippedCount = 0;
        $details = [];

        foreach ($configs as $config) {
            $result = $this->processConfig($config, $dryRun);

            if ($result['fixed']) {
                $fixedCount++;
            } else {
                $skippedCount++;
            }

            $details[] = [
                'config' => $config,
                'result' => $result
            ];
        }

        return [
            'fixed_count' => $fixedCount,
            'skipped_count' => $skippedCount,
            'details' => $details
        ];
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
            $fixResult = $this->determineFix($currentStartPage, $lastIdFromSources);

            if (!$fixResult['needs_fix']) {
                $this->line("   ✅ نیازی به اصلاح ندارد");
                return ['fixed' => false, 'reason' => 'no_fix_needed'];
            }

            $this->line("   🔧 نیاز به اصلاح:");
            $this->line("      • دلیل: {$fixResult['reason']}");
            $this->line("      • start_page جدید: " . ($fixResult['new_start_page'] ?? 'null (هوشمند)'));

            if (!$dryRun) {
                $this->applyFix($config, $fixResult);
            } else {
                $this->line("   📝 (dry-run) تغییر اعمال نشد");
            }

            return [
                'fixed' => true,
                'reason' => $fixResult['reason'],
                'old_start_page' => $currentStartPage,
                'new_start_page' => $fixResult['new_start_page']
            ];

        } catch (\Exception $e) {
            $this->error("   ❌ خطا در پردازش کانفیگ {$config->id}: " . $e->getMessage());

            Log::error("خطا در پردازش کانفیگ", [
                'config_id' => $config->id,
                'error' => $e->getMessage()
            ]);

            return ['fixed' => false, 'reason' => 'error: ' . $e->getMessage()];
        }
    }

    private function determineFix($currentStartPage, $lastIdFromSources): array
    {
        $needsFix = false;
        $newStartPage = null;
        $reason = '';

        if ($currentStartPage === 1 && $lastIdFromSources > 0) {
            $needsFix = true;
            $newStartPage = null;
            $reason = "start_page=1 اما {$lastIdFromSources} رکورد در book_sources وجود دارد";
        } elseif ($currentStartPage && $currentStartPage <= $lastIdFromSources) {
            $needsFix = true;
            $newStartPage = null;
            $reason = "start_page={$currentStartPage} <= آخرین ID موجود ({$lastIdFromSources})";
        }

        return [
            'needs_fix' => $needsFix,
            'new_start_page' => $newStartPage,
            'reason' => $reason
        ];
    }

    private function applyFix(Config $config, array $fixResult): void
    {
        $config->update(['start_page' => $fixResult['new_start_page']]);

        $config->refresh();
        $newSmartStartPage = $config->getSmartStartPage();

        $this->line("   ✅ اصلاح شد! smart start page جدید: {$newSmartStartPage}");

        Log::info("کانفیگ اصلاح شد", [
            'config_id' => $config->id,
            'config_name' => $config->name,
            'old_start_page' => $fixResult['old_start_page'] ?? null,
            'new_start_page' => $fixResult['new_start_page'],
            'new_smart_start_page' => $newSmartStartPage,
            'reason' => $fixResult['reason']
        ]);
    }

    private function displayResults(array $results, bool $dryRun): void
    {
        $this->newLine();
        $this->displayHelper->displayStats([
            'اصلاح شده' => $results['fixed_count'],
            'رد شده' => $results['skipped_count']
        ], 'نتایج اصلاح');

        if ($dryRun && $results['fixed_count'] > 0) {
            $this->newLine();
            $this->warn("💡 برای اعمال تغییرات، دستور را بدون --dry-run اجرا کنید");
        }

        // نمایش جزئیات اگر debug mode باشد
        if ($this->output->isVerbose()) {
            $this->displayDetailedResults($results['details']);
        }
    }

    private function displayDetailedResults(array $details): void
    {
        $this->newLine();
        $this->info("📋 جزئیات کامل:");

        foreach ($details as $detail) {
            $config = $detail['config'];
            $result = $detail['result'];

            $status = $result['fixed'] ? '✅ اصلاح شد' : '⏭️ رد شد';
            $this->line("• کانفیگ {$config->id} ({$config->name}): {$status}");

            if (isset($result['reason'])) {
                $this->line("  دلیل: {$result['reason']}");
            }
        }
    }
}
