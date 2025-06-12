<?php

namespace App\Console\Helpers;

use Illuminate\Console\Command;
use App\Models\Config;

class CommandDisplayHelper
{
    private Command $command;

    public function __construct(Command $command)
    {
        $this->command = $command;
    }

    public function displayWelcomeMessage(string $title, array $activeSettings = [], bool $debug = false): void
    {
        $this->command->info("🚀 {$title}");
        $this->command->info("⏰ زمان شروع: " . now()->format('Y-m-d H:i:s'));
        $this->command->newLine();

        if (!empty($activeSettings)) {
            $this->command->info("🔧 تنظیمات فعال: " . implode(', ', $activeSettings));
        }

        if ($debug) {
            $this->command->warn("🔍 حالت Debug فعال است");
        }

        $this->command->newLine();
    }

    public function displayIntermediateProgress(int $processed, array $stats, bool $debug = false): void
    {
        if (!$debug) return;

        $this->command->newLine();
        $this->command->info("📊 پیشرفت تا کنون:");
        $this->command->line("   • پردازش شده: {$processed}");

        foreach ($stats as $key => $value) {
            $this->command->line("   • {$key}: {$value}");
        }

        if ($processed > 0 && isset($stats['enhanced'])) {
            $successRate = round(($stats['enhanced'] / $processed) * 100, 1);
            $this->command->line("   • نرخ بهبود: {$successRate}%");
        }
    }

    public function displayFinalResults(int $total, array $stats, string $operation = "عملیات"): void
    {
        $this->command->info("🎉 {$operation} تمام شد!");
        $this->command->line("=" . str_repeat("=", 50));

        $this->command->info("📊 نتایج نهایی:");
        $this->command->line("   • کل پردازش شده: " . number_format($total));

        foreach ($stats as $label => $value) {
            $this->command->line("   • {$label}: " . number_format($value));
        }

        if ($total > 0 && isset($stats['enhanced'])) {
            $successRate = round(($stats['enhanced'] / $total) * 100, 1);
            $this->command->line("   • نرخ بهبود: {$successRate}%");
        }
    }

    public function confirmOperation(Config $config, array $details = [], bool $force = false): bool
    {
        if ($force) return true;

        $this->command->newLine();
        $this->command->warn("⚠️ این عملیات تغییراتی در دیتابیس ایجاد خواهد کرد!");
        $this->command->line("کانفیگ: {$config->name}");
        $this->command->line("منبع: {$config->source_name}");

        foreach ($details as $key => $value) {
            $this->command->line("{$key}: {$value}");
        }

        return $this->command->confirm('آیا می‌خواهید ادامه دهید؟');
    }

    public function displayConfigInfo(Config $config, bool $detailed = false): void
    {
        $this->command->info("📊 اطلاعات کانفیگ:");
        $this->command->line("   • نام: {$config->name}");
        $this->command->line("   • منبع: {$config->source_name}");
        $this->command->line("   • وضعیت: " . ($config->is_running ? 'در حال اجرا' : 'متوقف'));

        if ($detailed) {
            $lastId = $config->getLastSourceIdFromBookSources();
            $smartStart = $config->getSmartStartPage();

            $this->command->line("   • start_page: " . ($config->start_page ?? 'null (هوشمند)'));
            $this->command->line("   • آخرین ID: " . ($lastId ?: 'هیچ'));
            $this->command->line("   • Smart start: {$smartStart}");
        }

        $this->command->newLine();
    }

    public function displayStats(array $stats, string $title = "آمار"): void
    {
        $this->command->info("📈 {$title}:");

        $tableData = [];
        foreach ($stats as $key => $value) {
            $tableData[] = [$key, is_numeric($value) ? number_format($value) : $value];
        }

        $this->command->table(['مورد', 'مقدار'], $tableData);
        $this->command->newLine();
    }
}
