<?php

namespace App\Services;

use App\Models\Config;
use App\Models\ExecutionLog;
use Illuminate\Console\Command;

class CommandStatsTracker
{
    private array $totalStats = [
        'total_processed' => 0,
        'total_success' => 0,
        'total_enhanced' => 0,
        'total_failed' => 0,
        'total_duplicate' => 0,
    ];

    private float $startTime;
    private Command $command;

    public function __construct(Command $command)
    {
        $this->command = $command;
        $this->startTime = microtime(true);
    }

    public function createExecutionLog(Config $config): ExecutionLog
    {
        $executionId = 'crawl_' . time() . '_' . $config->id;

        return ExecutionLog::create([
            'config_id' => $config->id,
            'execution_id' => $executionId,
            'status' => 'running',
            'started_at' => now(),
            'log_details' => []
        ]);
    }

    public function updateStats(array $pageResult): void
    {
        $stats = $pageResult['stats'] ?? [];

        foreach (['total_processed', 'total_success', 'total_enhanced', 'total_failed', 'total_duplicate'] as $key) {
            $this->totalStats[$key] += $stats[$key] ?? 0;
        }
    }

    public function getCurrentStats(): array
    {
        return $this->totalStats;
    }

    public function completeConfigExecution(Config $config, ExecutionLog $executionLog): void
    {
        $executionTime = microtime(true) - $this->startTime;

        $config->syncStatsFromLogs();

        $finalStats = array_merge($this->totalStats, [
            'execution_time' => $executionTime
        ]);

        $executionLog->markCompleted($finalStats);

        $this->displayConfigSummary($config, $executionTime);
    }

    public function displayFinalSummary(): void
    {
        $totalTime = microtime(true) - $this->startTime;
        $totalImpactful = $this->totalStats['total_success'] + $this->totalStats['total_enhanced'];
        $overallImpactRate = $this->totalStats['total_processed'] > 0
            ? round(($totalImpactful / $this->totalStats['total_processed']) * 100, 1)
            : 0;

        $this->command->info("🎉 خلاصه نهایی کرال هوشمند:");
        $this->command->info("=" . str_repeat("=", 50));
        $this->command->line("📊 آمار کلی:");
        $this->command->line("   • کل رکوردهای پردازش شده: " . number_format($this->totalStats['total_processed']));
        $this->command->line("   • کتاب‌های جدید ایجاد شده: " . number_format($this->totalStats['total_success']));
        $this->command->line("   • کتاب‌های بهبود یافته: " . number_format($this->totalStats['total_enhanced']));
        $this->command->line("   • کل تأثیرگذار: " . number_format($totalImpactful) . " ({$overallImpactRate}%)");
        $this->command->line("   • رکوردهای ناموفق: " . number_format($this->totalStats['total_failed']));
        $this->command->line("   • رکوردهای تکراری: " . number_format($this->totalStats['total_duplicate']));
        $this->command->newLine();

        $this->command->line("⏱️ عملکرد:");
        $this->command->line("   • کل زمان اجرا: " . gmdate('H:i:s', (int)$totalTime));
        if ($this->totalStats['total_processed'] > 0) {
            $recordsPerSecond = round($this->totalStats['total_processed'] / $totalTime, 2);
            $this->command->line("   • سرعت پردازش: {$recordsPerSecond} رکورد/ثانیه");
        }

        $this->command->info("✨ کرال هوشمند با موفقیت تمام شد! ✨");
    }

    private function displayConfigSummary(Config $config, float $executionTime): void
    {
        $totalImpactful = $this->totalStats['total_success'] + $this->totalStats['total_enhanced'];
        $impactRate = $this->totalStats['total_processed'] > 0
            ? round(($totalImpactful / $this->totalStats['total_processed']) * 100, 1)
            : 0;

        $this->command->info("✅ تکمیل کانفیگ: {$config->source_name}");
        $this->command->info("📊 نتایج:");
        $this->command->line("   • کل پردازش شده: " . number_format($this->totalStats['total_processed']));
        $this->command->line("   • کتاب‌های جدید: " . number_format($this->totalStats['total_success']));
        $this->command->line("   • کتاب‌های بهبود یافته: " . number_format($this->totalStats['total_enhanced']));
        $this->command->line("   • کل تأثیرگذار: " . number_format($totalImpactful) . " ({$impactRate}%)");
        $this->command->line("   • خطا: " . number_format($this->totalStats['total_failed']));
        $this->command->line("   • تکراری: " . number_format($this->totalStats['total_duplicate']));
        $this->command->line("   • زمان اجرا: " . round($executionTime, 2) . " ثانیه");
        $this->command->newLine();
    }
}
