<?php

namespace App\Services;

use App\Models\Config;
use App\Models\ExecutionLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CommandStatsTracker
{
    private array $stats = [
        'total_processed' => 0,
        'total_success' => 0,
        'total_enhanced' => 0,
        'total_failed' => 0,
        'total_duplicate' => 0,
        'created_books' => 0,
        'enhanced_books' => 0,
        'sources_added' => 0,
        'api_failures' => 0
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
        $executionId = 'crawl_intelligent_' . time() . '_' . $config->id;

        return ExecutionLog::create([
            'config_id' => $config->id,
            'execution_id' => $executionId,
            'status' => 'running',
            'started_at' => now(),
            'last_activity_at' => now(),
            'log_details' => [
                'processing_mode' => 'intelligent_md5_based',
                'command_options' => [
                    'start_page' => $this->command->option('start-page'),
                    'pages' => $this->command->option('pages'),
                    'force' => $this->command->option('force')
                ]
            ],
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
        $stats = $pageResult['stats'] ?? [];
        $action = $pageResult['action'] ?? 'unknown';

        // آمار اصلی
        $this->stats['total_processed'] += $stats['total_processed'] ?? 0;
        $this->stats['total_success'] += $stats['total_success'] ?? 0;
        $this->stats['total_failed'] += $stats['total_failed'] ?? 0;
        $this->stats['total_duplicate'] += $stats['total_duplicate'] ?? 0;
        $this->stats['total_enhanced'] += $stats['total_enhanced'] ?? 0;

        // آمار تفصیلی
        switch ($action) {
            case 'created':
                $this->stats['created_books']++;
                break;
            case 'enhanced':
            case 'enriched':
            case 'merged':
                $this->stats['enhanced_books']++;
                break;
            case 'source_added':
                $this->stats['sources_added']++;
                break;
            case 'api_failed':
                $this->stats['api_failures']++;
                break;
        }
    }

    public function getCurrentStats(): array
    {
        return $this->stats;
    }

    public function completeConfigExecution(Config $config, ExecutionLog $executionLog): void
    {
        $executionTime = microtime(true) - $this->startTime;

        try {
            $config->syncStatsFromLogs();

            $finalStats = [
                'total_processed' => $this->stats['total_processed'],
                'total_success' => $this->stats['total_success'],
                'total_failed' => $this->stats['total_failed'],
                'total_duplicate' => $this->stats['total_duplicate'],
                'total_enhanced' => $this->stats['total_enhanced'],
                'execution_time' => $executionTime
            ];

            $executionLog->markCompleted($finalStats);
            $this->displayConfigSummary($config, $executionTime);

            Log::info("✅ اجرای کانفیگ هوشمند تکمیل شد", [
                'config_id' => $config->id,
                'execution_time' => $executionTime,
                'final_stats' => $finalStats
            ]);

        } catch (\Exception $e) {
            Log::error("❌ خطا در تکمیل اجرای کانفیگ", [
                'config_id' => $config->id,
                'error' => $e->getMessage()
            ]);
            $this->command->error("❌ خطا در تکمیل آمار: " . $e->getMessage());
        }
    }

    public function displayFinalSummary(): void
    {
        $totalTime = microtime(true) - $this->startTime;
        $totalImpactful = $this->stats['total_success'] + $this->stats['total_enhanced'];
        $overallImpactRate = $this->stats['total_processed'] > 0
            ? round(($totalImpactful / $this->stats['total_processed']) * 100, 1)
            : 0;

        $this->command->info("🎉 خلاصه نهایی کرال هوشمند:");
        $this->command->info("=" . str_repeat("=", 50));

        // آمار اصلی
        $this->command->line("📊 آمار کلی:");
        $this->command->line("   • کل پردازش شده: " . number_format($this->stats['total_processed']));
        $this->command->line("   • کتاب‌های جدید: " . number_format($this->stats['total_success']));
        $this->command->line("   • کتاب‌های بهبود یافته: " . number_format($this->stats['total_enhanced']));
        $this->command->line("   • کل تأثیرگذار: " . number_format($totalImpactful) . " ({$overallImpactRate}%)");
        $this->command->line("   • ناموفق: " . number_format($this->stats['total_failed']));
        $this->command->line("   • تکراری: " . number_format($this->stats['total_duplicate']));
        $this->command->newLine();

        // آمار عملکرد
        $this->command->line("⏱️ عملکرد:");
        $this->command->line("   • زمان اجرا: " . gmdate('H:i:s', (int)$totalTime));
        if ($this->stats['total_processed'] > 0) {
            $recordsPerSecond = round($this->stats['total_processed'] / $totalTime, 2);
            $this->command->line("   • سرعت: {$recordsPerSecond} رکورد/ثانیه");
        }
        $this->command->newLine();

        // نرخ‌های کلیدی
        if ($this->stats['total_processed'] > 0) {
            $newBookRate = round(($this->stats['total_success'] / $this->stats['total_processed']) * 100, 1);
            $enhancementRate = round(($this->stats['total_enhanced'] / $this->stats['total_processed']) * 100, 1);

            $this->command->line("📈 نرخ‌های کلیدی:");
            $this->command->line("   • نرخ ایجاد کتاب جدید: {$newBookRate}%");
            $this->command->line("   • نرخ بهبود: {$enhancementRate}%");
            $this->command->line("   • نرخ تأثیرگذاری کل: {$overallImpactRate}%");
        }

        $this->command->info("✨ کرال هوشمند با موفقیت تمام شد! ✨");
    }

    private function displayConfigSummary(Config $config, float $executionTime): void
    {
        $totalImpactful = $this->stats['total_success'] + $this->stats['total_enhanced'];
        $impactRate = $this->stats['total_processed'] > 0
            ? round(($totalImpactful / $this->stats['total_processed']) * 100, 1)
            : 0;

        $this->command->info("✅ تکمیل کانفیگ: {$config->source_name}");
        $this->command->info("📊 نتایج:");
        $this->command->line("   • کل پردازش شده: " . number_format($this->stats['total_processed']));
        $this->command->line("   • کتاب‌های جدید: " . number_format($this->stats['total_success']));
        $this->command->line("   • کتاب‌های بهبود یافته: " . number_format($this->stats['total_enhanced']));
        $this->command->line("   • کل تأثیرگذار: " . number_format($totalImpactful) . " ({$impactRate}%)");
        $this->command->line("   • خطا: " . number_format($this->stats['total_failed']));
        $this->command->line("   • تکراری: " . number_format($this->stats['total_duplicate']));
        $this->command->line("   • زمان اجرا: " . round($executionTime, 2) . " ثانیه");

        if ($this->stats['total_processed'] > 0 && $executionTime > 0) {
            $recordsPerSecond = round($this->stats['total_processed'] / $executionTime, 2);
            $this->command->line("   • سرعت: {$recordsPerSecond} رکورد/ثانیه");
        }

        $this->command->newLine();
    }
}
