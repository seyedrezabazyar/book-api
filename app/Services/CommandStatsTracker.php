<?php

namespace App\Services;

use App\Models\Config;
use App\Models\ExecutionLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
            'last_activity_at' => now(),
            'log_details' => [],
            'total_processed' => 0,
            'total_success' => 0,
            'total_failed' => 0,
            'total_duplicate' => 0,
            'total_enhanced' => 0,
            'execution_time' => 0,
            'success_rate' => 0,
        ]);
    }

    public function updateStats(array $pageResult): void
    {
        // استخراج آمار از نتیجه صفحه
        $stats = $pageResult['stats'] ?? [];

        // آمار سنتی
        $this->totalStats['total_processed'] += $this->extractStatValue($stats, ['total_processed', 'total']);
        $this->totalStats['total_success'] += $this->extractStatValue($stats, ['total_success', 'success']);
        $this->totalStats['total_failed'] += $this->extractStatValue($stats, ['total_failed', 'failed']);
        $this->totalStats['total_duplicate'] += $this->extractStatValue($stats, ['total_duplicate', 'duplicate']);

        // آمار جدید
        $this->totalStats['total_enhanced'] += $this->extractStatValue($stats, ['total_enhanced', 'enhanced']);

        Log::debug("📊 آمار کامند بروزرسانی شد", [
            'page_result_action' => $pageResult['action'] ?? 'unknown',
            'page_stats' => $stats,
            'cumulative_stats' => $this->totalStats
        ]);
    }

    /**
     * استخراج مقدار آمار با کلیدهای مختلف
     */
    private function extractStatValue(array $stats, array $possibleKeys): int
    {
        foreach ($possibleKeys as $key) {
            if (isset($stats[$key]) && is_numeric($stats[$key])) {
                return (int)$stats[$key];
            }
        }
        return 0;
    }

    public function getCurrentStats(): array
    {
        return $this->totalStats;
    }

    public function completeConfigExecution(Config $config, ExecutionLog $executionLog): void
    {
        $executionTime = microtime(true) - $this->startTime;

        try {
            // همگام‌سازی آمار کانفیگ از لاگ‌ها
            $config->syncStatsFromLogs();

            // تکمیل ExecutionLog با آمار نهایی
            $finalStats = [
                'total_processed' => $this->totalStats['total_processed'],
                'total_success' => $this->totalStats['total_success'],
                'total_failed' => $this->totalStats['total_failed'],
                'total_duplicate' => $this->totalStats['total_duplicate'],
                'total_enhanced' => $this->totalStats['total_enhanced'],
                'execution_time' => $executionTime
            ];

            $executionLog->markCompleted($finalStats);

            $this->displayConfigSummary($config, $executionTime);

            Log::info("✅ اجرای کانفیگ تکمیل شد", [
                'config_id' => $config->id,
                'execution_id' => $executionLog->execution_id,
                'execution_time' => $executionTime,
                'final_stats' => $finalStats
            ]);

        } catch (\Exception $e) {
            Log::error("❌ خطا در تکمیل اجرای کانفیگ", [
                'config_id' => $config->id,
                'execution_id' => $executionLog->execution_id,
                'error' => $e->getMessage()
            ]);

            $this->command->error("❌ خطا در تکمیل آمار: " . $e->getMessage());
        }
    }

    public function displayFinalSummary(): void
    {
        $totalTime = microtime(true) - $this->startTime;
        $totalImpactful = $this->totalStats['total_success'] + $this->totalStats['total_enhanced'];
        $overallImpactRate = $this->totalStats['total_processed'] > 0
            ? round(($totalImpactful / $this->totalStats['total_processed']) * 100, 1)
            : 0;

        $this->command->info("🎉 خلاصه نهایی کرال هوشمند:");
        $this->command->info("=" . str_repeat("=", 60));

        // آمار اصلی
        $this->command->line("📊 آمار کلی:");
        $this->command->line("   • کل رکوردهای پردازش شده: " . number_format($this->totalStats['total_processed']));
        $this->command->line("   • کتاب‌های جدید ایجاد شده: " . number_format($this->totalStats['total_success']));
        $this->command->line("   • کتاب‌های بهبود یافته: " . number_format($this->totalStats['total_enhanced']));
        $this->command->line("   • کل تأثیرگذار: " . number_format($totalImpactful) . " ({$overallImpactRate}%)");
        $this->command->line("   • رکوردهای ناموفق: " . number_format($this->totalStats['total_failed']));
        $this->command->line("   • رکوردهای تکراری: " . number_format($this->totalStats['total_duplicate']));
        $this->command->newLine();

        // آمار عملکرد
        $this->command->line("⏱️ عملکرد:");
        $this->command->line("   • کل زمان اجرا: " . gmdate('H:i:s', (int)$totalTime));
        if ($this->totalStats['total_processed'] > 0) {
            $recordsPerSecond = round($this->totalStats['total_processed'] / $totalTime, 2);
            $this->command->line("   • سرعت پردازش: {$recordsPerSecond} رکورد/ثانیه");
        }
        $this->command->newLine();

        // ویژگی‌های پیشرفته
        $this->command->line("🧠 ویژگی‌های بروزرسانی هوشمند:");
        $this->command->line("   ✅ تشخیص و تکمیل فیلدهای خالی");
        $this->command->line("   ✅ بهبود توضیحات ناقص");
        $this->command->line("   ✅ ادغام ISBN و نویسندگان جدید");
        $this->command->line("   ✅ بروزرسانی هش‌ها و تصاویر");
        $this->command->line("   ✅ محاسبه دقیق نرخ تأثیر");
        $this->command->newLine();

        // تفکیک تأثیرات
        if ($this->totalStats['total_processed'] > 0) {
            $newBookRate = round(($this->totalStats['total_success'] / $this->totalStats['total_processed']) * 100, 1);
            $enhancementRate = round(($this->totalStats['total_enhanced'] / $this->totalStats['total_processed']) * 100, 1);
            $duplicateRate = round(($this->totalStats['total_duplicate'] / $this->totalStats['total_processed']) * 100, 1);
            $failureRate = round(($this->totalStats['total_failed'] / $this->totalStats['total_processed']) * 100, 1);

            $this->command->line("📈 تفکیک نتایج:");
            $this->command->line("   • نرخ کتاب‌های جدید: {$newBookRate}%");
            $this->command->line("   • نرخ بهبود و غنی‌سازی: {$enhancementRate}%");
            $this->command->line("   • نرخ تکراری (بدون تغییر): {$duplicateRate}%");
            $this->command->line("   • نرخ خطا: {$failureRate}%");
            $this->command->newLine();
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
        $this->command->info("📊 نتایج تفصیلی:");
        $this->command->line("   • کل پردازش شده: " . number_format($this->totalStats['total_processed']));
        $this->command->line("   • کتاب‌های جدید: " . number_format($this->totalStats['total_success']));
        $this->command->line("   • کتاب‌های بهبود یافته: " . number_format($this->totalStats['total_enhanced']));
        $this->command->line("   • کل تأثیرگذار: " . number_format($totalImpactful) . " ({$impactRate}%)");
        $this->command->line("   • خطا: " . number_format($this->totalStats['total_failed']));
        $this->command->line("   • تکراری: " . number_format($this->totalStats['total_duplicate']));
        $this->command->line("   • زمان اجرا: " . round($executionTime, 2) . " ثانیه");

        // نمایش تفکیک عملکرد
        if ($this->totalStats['total_processed'] > 0) {
            $enhancementRate = round(($this->totalStats['total_enhanced'] / $this->totalStats['total_processed']) * 100, 1);
            $this->command->line("   • نرخ بهبود: {$enhancementRate}%");

            if ($executionTime > 0) {
                $recordsPerSecond = round($this->totalStats['total_processed'] / $executionTime, 2);
                $this->command->line("   • سرعت: {$recordsPerSecond} رکورد/ثانیه");
            }
        }

        $this->command->newLine();
    }
}
