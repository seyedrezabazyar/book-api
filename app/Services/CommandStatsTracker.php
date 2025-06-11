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

    private array $detailedStats = [
        'created_books' => 0,
        'enhanced_books' => 0,
        'enriched_books' => 0,
        'merged_books' => 0,
        'sources_added' => 0,
        'already_processed' => 0,
        'api_failures' => 0,
        'processing_failures' => 0,
        'no_book_found' => 0,
        'max_retries_reached' => 0
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
                    'force' => $this->command->option('force'),
                    'enhanced_only' => $this->command->option('enhanced-only'),
                    'debug' => $this->command->option('debug')
                ],
                'smart_features' => [
                    'md5_based_processing' => true,
                    'intelligent_updates' => true,
                    'author_isbn_merging' => true,
                    'source_tracking' => true
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
        // استخراج آمار از نتیجه صفحه
        $stats = $pageResult['stats'] ?? [];
        $action = $pageResult['action'] ?? 'unknown';

        // آمار اصلی
        $this->totalStats['total_processed'] += $this->extractStatValue($stats, ['total_processed', 'total']);
        $this->totalStats['total_success'] += $this->extractStatValue($stats, ['total_success', 'success']);
        $this->totalStats['total_failed'] += $this->extractStatValue($stats, ['total_failed', 'failed']);
        $this->totalStats['total_duplicate'] += $this->extractStatValue($stats, ['total_duplicate', 'duplicate']);
        $this->totalStats['total_enhanced'] += $this->extractStatValue($stats, ['total_enhanced', 'enhanced']);

        // آمار تفصیلی بر اساس action
        $this->updateDetailedStats($action, $pageResult);

        Log::debug("📊 آمار کامند بروزرسانی شد", [
            'page_result_action' => $action,
            'page_stats' => $stats,
            'cumulative_stats' => $this->totalStats,
            'detailed_stats' => $this->detailedStats
        ]);
    }

    /**
     * بروزرسانی آمار تفصیلی
     */
    private function updateDetailedStats(string $action, array $pageResult): void
    {
        switch ($action) {
            case 'created':
                $this->detailedStats['created_books']++;
                break;

            case 'enhanced':
                $this->detailedStats['enhanced_books']++;
                break;

            case 'enriched':
                $this->detailedStats['enriched_books']++;
                break;

            case 'merged':
                $this->detailedStats['merged_books']++;
                break;

            case 'source_added':
                $this->detailedStats['sources_added']++;
                break;

            case 'already_processed':
                $this->detailedStats['already_processed']++;
                break;

            case 'api_failed':
                $this->detailedStats['api_failures']++;
                break;

            case 'processing_failed':
                $this->detailedStats['processing_failures']++;
                break;

            case 'no_book_found':
                $this->detailedStats['no_book_found']++;
                break;

            case 'max_retries_reached':
                $this->detailedStats['max_retries_reached']++;
                break;
        }
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
        return array_merge($this->totalStats, $this->detailedStats);
    }

    public function completeConfigExecution(Config $config, ExecutionLog $executionLog): void
    {
        $executionTime = microtime(true) - $this->startTime;

        try {
            // همگام‌سازی آمار کانفیگ از لاگ‌ها
            $config->syncStatsFromLogs();

            // محاسبه آمار نهایی
            $finalStats = [
                'total_processed' => $this->totalStats['total_processed'],
                'total_success' => $this->totalStats['total_success'],
                'total_failed' => $this->totalStats['total_failed'],
                'total_duplicate' => $this->totalStats['total_duplicate'],
                'total_enhanced' => $this->totalStats['total_enhanced'],
                'execution_time' => $executionTime
            ];

            // اضافه کردن آمار تفصیلی به log
            $detailedLogData = array_merge($finalStats, [
                'detailed_breakdown' => $this->detailedStats,
                'intelligent_processing_summary' => $this->generateIntelligentSummary()
            ]);

            $executionLog->markCompleted($detailedLogData);

            $this->displayConfigSummary($config, $executionTime);

            Log::info("✅ اجرای کانفیگ هوشمند تکمیل شد", [
                'config_id' => $config->id,
                'execution_id' => $executionLog->execution_id,
                'execution_time' => $executionTime,
                'final_stats' => $finalStats,
                'detailed_stats' => $this->detailedStats
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

    /**
     * تولید خلاصه هوشمند
     */
    private function generateIntelligentSummary(): array
    {
        $totalImpactful = $this->totalStats['total_success'] + $this->totalStats['total_enhanced'];
        $totalProcessed = $this->totalStats['total_processed'];

        return [
            'impact_rate' => $totalProcessed > 0 ? round(($totalImpactful / $totalProcessed) * 100, 2) : 0,
            'enhancement_rate' => $totalProcessed > 0 ? round(($this->totalStats['total_enhanced'] / $totalProcessed) * 100, 2) : 0,
            'creation_rate' => $totalProcessed > 0 ? round(($this->totalStats['total_success'] / $totalProcessed) * 100, 2) : 0,
            'duplicate_rate' => $totalProcessed > 0 ? round(($this->totalStats['total_duplicate'] / $totalProcessed) * 100, 2) : 0,
            'failure_rate' => $totalProcessed > 0 ? round(($this->totalStats['total_failed'] / $totalProcessed) * 100, 2) : 0,
            'intelligent_features' => [
                'books_enhanced' => $this->detailedStats['enhanced_books'],
                'books_enriched' => $this->detailedStats['enriched_books'],
                'books_merged' => $this->detailedStats['merged_books'],
                'sources_added' => $this->detailedStats['sources_added']
            ]
        ];
    }

    public function displayFinalSummary(): void
    {
        $totalTime = microtime(true) - $this->startTime;
        $totalImpactful = $this->totalStats['total_success'] + $this->totalStats['total_enhanced'];
        $overallImpactRate = $this->totalStats['total_processed'] > 0
            ? round(($totalImpactful / $this->totalStats['total_processed']) * 100, 1)
            : 0;

        $this->command->info("🎉 خلاصه نهایی کرال هوشمند مبتنی بر MD5:");
        $this->command->info("=" . str_repeat("=", 70));

        // آمار اصلی
        $this->command->line("📊 آمار کلی:");
        $this->command->line("   • کل رکوردهای پردازش شده: " . number_format($this->totalStats['total_processed']));
        $this->command->line("   • کتاب‌های جدید ایجاد شده: " . number_format($this->totalStats['total_success']));
        $this->command->line("   • کتاب‌های بهبود یافته: " . number_format($this->totalStats['total_enhanced']));
        $this->command->line("   • کل تأثیرگذار: " . number_format($totalImpactful) . " ({$overallImpactRate}%)");
        $this->command->line("   • رکوردهای ناموفق: " . number_format($this->totalStats['total_failed']));
        $this->command->line("   • رکوردهای تکراری: " . number_format($this->totalStats['total_duplicate']));
        $this->command->newLine();

        // آمار تفصیلی
        $this->command->line("🔍 تفکیک تفصیلی:");
        $this->command->line("   📚 کتاب‌های جدید: " . number_format($this->detailedStats['created_books']));
        $this->command->line("   🔧 بهبود فیلدهای خالی: " . number_format($this->detailedStats['enhanced_books']));
        $this->command->line("   💎 غنی‌سازی توضیحات: " . number_format($this->detailedStats['enriched_books']));
        $this->command->line("   🔗 ادغام نویسندگان/ISBN: " . number_format($this->detailedStats['merged_books']));
        $this->command->line("   📌 افزودن منابع جدید: " . number_format($this->detailedStats['sources_added']));
        $this->command->line("   📋 قبلاً پردازش شده: " . number_format($this->detailedStats['already_processed']));
        $this->command->newLine();

        // آمار خطاها
        $this->command->line("⚠️ آمار خطاها:");
        $this->command->line("   🌐 خطاهای API: " . number_format($this->detailedStats['api_failures']));
        $this->command->line("   ⚙️ خطاهای پردازش: " . number_format($this->detailedStats['processing_failures']));
        $this->command->line("   📭 کتاب یافت نشد: " . number_format($this->detailedStats['no_book_found']));
        $this->command->line("   🔄 حداکثر تلاش رسیده: " . number_format($this->detailedStats['max_retries_reached']));
        $this->command->newLine();

        // آمار عملکرد
        $this->command->line("⏱️ عملکرد:");
        $this->command->line("   • کل زمان اجرا: " . gmdate('H:i:s', (int)$totalTime));
        if ($this->totalStats['total_processed'] > 0) {
            $recordsPerSecond = round($this->totalStats['total_processed'] / $totalTime, 2);
            $this->command->line("   • سرعت پردازش: {$recordsPerSecond} رکورد/ثانیه");
        }
        $this->command->newLine();

        // نرخ‌های مهم
        if ($this->totalStats['total_processed'] > 0) {
            $newBookRate = round(($this->totalStats['total_success'] / $this->totalStats['total_processed']) * 100, 1);
            $enhancementRate = round(($this->totalStats['total_enhanced'] / $this->totalStats['total_processed']) * 100, 1);
            $duplicateRate = round(($this->totalStats['total_duplicate'] / $this->totalStats['total_processed']) * 100, 1);
            $failureRate = round(($this->totalStats['total_failed'] / $this->totalStats['total_processed']) * 100, 1);

            $this->command->line("📈 نرخ‌های کلیدی:");
            $this->command->line("   • نرخ ایجاد کتاب جدید: {$newBookRate}%");
            $this->command->line("   • نرخ بهبود و غنی‌سازی: {$enhancementRate}%");
            $this->command->line("   • نرخ تأثیرگذاری کل: {$overallImpactRate}%");
            $this->command->line("   • نرخ تکراری (بدون تغییر): {$duplicateRate}%");
            $this->command->line("   • نرخ خطا: {$failureRate}%");
            $this->command->newLine();
        }

        // ویژگی‌های هوشمند
        $this->command->line("🧠 ویژگی‌های پردازش هوشمند:");
        $this->command->line("   ✅ شناسایی منحصر‌به‌فرد بر اساس MD5");
        $this->command->line("   ✅ مقایسه دقیق فیلدها قبل از بروزرسانی");
        $this->command->line("   ✅ تکمیل فیلدهای خالی بدون حذف داده‌های موجود");
        $this->command->line("   ✅ بهبود توضیحات ناقص با حفظ محتوای بهتر");
        $this->command->line("   ✅ اضافه کردن نویسندگان و ISBN جدید بدون تکرار");
        $this->command->line("   ✅ ثبت چندگانه منابع برای هر کتاب");
        $this->command->line("   ✅ بروزرسانی هش‌ها و تصاویر");
        $this->command->line("   ✅ محاسبه دقیق نرخ‌های تأثیر و بهبود");
        $this->command->newLine();

        $this->command->info("✨ کرال هوشمند مبتنی بر MD5 با موفقیت تمام شد! ✨");
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

        // نمایش تفکیک هوشمند
        if ($this->totalStats['total_processed'] > 0) {
            $enhancementRate = round(($this->totalStats['total_enhanced'] / $this->totalStats['total_processed']) * 100, 1);
            $this->command->line("   • نرخ بهبود هوشمند: {$enhancementRate}%");

            if ($executionTime > 0) {
                $recordsPerSecond = round($this->totalStats['total_processed'] / $executionTime, 2);
                $this->command->line("   • سرعت پردازش: {$recordsPerSecond} رکورد/ثانیه");
            }
        }

        // نمایش آمار تفصیلی
        if ($this->totalStats['total_enhanced'] > 0) {
            $this->command->line("🔍 تفکیک بهبودها:");
            if ($this->detailedStats['enhanced_books'] > 0) {
                $this->command->line("     - تکمیل فیلدهای خالی: " . $this->detailedStats['enhanced_books']);
            }
            if ($this->detailedStats['enriched_books'] > 0) {
                $this->command->line("     - بهبود توضیحات: " . $this->detailedStats['enriched_books']);
            }
            if ($this->detailedStats['merged_books'] > 0) {
                $this->command->line("     - ادغام نویسندگان/ISBN: " . $this->detailedStats['merged_books']);
            }
        }

        $this->command->newLine();
    }
}
