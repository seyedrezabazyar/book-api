<?php

namespace App\Console\Commands;

use App\Models\Config;
use App\Models\BookSource;
use App\Models\ScrapingFailure;
use App\Helpers\SourceIdManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ManageSourceIds extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'crawl:manage-sources
                            {action : Action to perform (analyze|missing|cleanup|report)}
                            {--config= : Specific config ID to work with}
                            {--range= : ID range for analysis (e.g., 1-1000)}
                            {--limit=100 : Limit for results}
                            {--fix : Actually perform fixes (not just dry run)}';

    /**
     * The console command description.
     */
    protected $description = 'مدیریت و تحلیل Source ID های کرال شده';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $action = $this->argument('action');
        $configId = $this->option('config');
        $range = $this->option('range');
        $limit = (int) $this->option('limit');
        $fix = $this->option('fix');

        $this->info("🚀 شروع مدیریت Source ID ها - عمل: {$action}");

        // دریافت کانفیگ(ها)
        $configs = $this->getConfigs($configId);

        if ($configs->isEmpty()) {
            $this->error('❌ هیچ کانفیگی یافت نشد!');
            return 1;
        }

        foreach ($configs as $config) {
            $this->info("\n📊 پردازش کانفیگ: {$config->name} (ID: {$config->id})");
            $this->info("   منبع: {$config->source_name} | آخرین ID: {$config->last_source_id}");

            switch ($action) {
                case 'analyze':
                    $this->analyzeConfig($config);
                    break;
                case 'missing':
                    $this->findMissingIds($config, $range, $limit);
                    break;
                case 'cleanup':
                    $this->cleanupConfig($config, $fix);
                    break;
                case 'report':
                    $this->generateReport($config);
                    break;
                default:
                    $this->error("❌ عمل نامعتبر: {$action}");
                    return 1;
            }
        }

        $this->info("\n✅ مدیریت Source ID ها تمام شد!");
        return 0;
    }

    /**
     * دریافت کانفیگ(ها) برای پردازش
     */
    private function getConfigs($configId)
    {
        if ($configId) {
            return Config::where('id', $configId)->get();
        }

        return Config::all();
    }

    /**
     * تحلیل کانفیگ
     */
    private function analyzeConfig(Config $config): void
    {
        $this->info("🔍 تحلیل کانفیگ {$config->name}...");

        $analytics = SourceIdManager::getSourceAnalytics($config);

        // نمایش آمار منبع
        $sourceStats = $analytics['source_stats'];
        $this->table(
            ['متریک', 'مقدار'],
            [
                ['کل منابع', number_format($sourceStats['total_sources'])],
                ['منابع فعال', number_format($sourceStats['active_sources'])],
                ['اولین ID', $sourceStats['first_source_id']],
                ['آخرین ID', $sourceStats['last_source_id']],
                ['بازه پوشش', $sourceStats['id_range']],
                ['درصد پوشش', $sourceStats['coverage_percentage'] . '%']
            ]
        );

        // نمایش آمار شکست‌ها
        if (!empty($analytics['failure_stats'])) {
            $failureStats = $analytics['failure_stats'];
            $this->info("\n📉 آمار شکست‌ها:");
            $this->table(
                ['نوع', 'تعداد'],
                [
                    ['کل شکست‌ها', $failureStats['total_failures'] ?? 0],
                    ['حل نشده', $failureStats['unresolved_failures'] ?? 0],
                    ['اولین ID شکست خورده', $failureStats['first_failed_id'] ?? '-'],
                    ['آخرین ID شکست خورده', $failureStats['last_failed_id'] ?? '-']
                ]
            );
        }

        // نمایش کیفیت پوشش
        $coverage = $analytics['coverage_quality'];
        $this->info("\n🎯 کیفیت پوشش:");
        $this->line("   درصد کلی: {$coverage['overall_percentage']}%");
        $this->line("   نمره: {$coverage['quality_grade']} ({$coverage['quality_description']})");
        $this->line("   کل ممکن: " . number_format($coverage['total_possible']));
        $this->line("   موجود: " . number_format($coverage['total_exists']));
        $this->line("   مفقود: " . number_format($coverage['total_missing']));

        // نمایش بازه‌های مفقود
        if (!empty($analytics['missing_ranges'])) {
            $this->info("\n📋 بازه‌های مفقود (بزرگتر از 3):");
            foreach (array_slice($analytics['missing_ranges'], 0, 10) as $range) {
                $this->line("   ID {$range['start']} تا {$range['end']} ({$range['count']} مورد)");
            }
            if (count($analytics['missing_ranges']) > 10) {
                $remaining = count($analytics['missing_ranges']) - 10;
                $this->line("   ... و {$remaining} بازه دیگر");
            }
        }

        // نمایش توصیه‌ها
        if (!empty($analytics['recommendations'])) {
            $this->info("\n💡 توصیه‌ها:");
            foreach ($analytics['recommendations'] as $rec) {
                $priority = match($rec['priority']) {
                    'high' => '🔴',
                    'medium' => '🟡',
                    default => '🟢'
                };
                $this->line("   {$priority} {$rec['title']}");
                $this->line("      {$rec['description']}");
                $this->line("      عمل: {$rec['action']}");
            }
        }
    }

    /**
     * یافتن ID های مفقود
     */
    private function findMissingIds(Config $config, $range, int $limit): void
    {
        $this->info("🔍 جستجوی ID های مفقود...");

        if ($range) {
            [$startId, $endId] = explode('-', $range);
            $startId = (int) $startId;
            $endId = (int) $endId;
        } else {
            $startId = 1;
            $endId = $config->last_source_id;
        }

        if ($endId <= $startId) {
            $this->warn("⚠️ بازه نامعتبر: {$startId}-{$endId}");
            return;
        }

        $this->info("   بررسی بازه: {$startId} تا {$endId}");

        $missingIds = SourceIdManager::findMissingIds($config, $startId, $endId, $limit);

        if (empty($missingIds)) {
            $this->info("✅ هیچ ID مفقودی در این بازه یافت نشد!");
            return;
        }

        $this->warn("📋 {" . count($missingIds) . "} ID مفقود یافت شد:");

        // نمایش ID های مفقود به صورت گروه‌بندی شده
        $groups = $this->groupConsecutiveIds($missingIds);

        foreach (array_slice($groups, 0, 20) as $group) {
            if ($group['start'] === $group['end']) {
                $this->line("   ID {$group['start']}");
            } else {
                $this->line("   ID {$group['start']} تا {$group['end']} ({$group['count']} مورد)");
            }
        }

        if (count($groups) > 20) {
            $remaining = count($groups) - 20;
            $this->line("   ... و {$remaining} گروه دیگر");
        }

        // پیشنهاد عمل
        $this->info("\n💡 برای دریافت ID های مفقود:");
        $this->line("   1. تنظیمات کانفیگ را به 'تکمیل فیلدهای خالی' تغییر دهید");
        $this->line("   2. کانفیگ را مجدداً اجرا کنید");
        $this->line("   3. یا از دستور زیر استفاده کنید:");
        $this->line("   php artisan crawl:missing-ids {$config->id} --start={$startId} --end={$endId}");
    }

    /**
     * پاکسازی کانفیگ
     */
    private function cleanupConfig(Config $config, bool $fix): void
    {
        $this->info("🧹 پاکسازی کانفیگ {$config->name}...");

        if (!$fix) {
            $this->warn("⚠️ حالت Dry Run - برای اعمال تغییرات از --fix استفاده کنید");
        }

        $cleaned = SourceIdManager::smartCleanup($config);

        $this->table(
            ['نوع پاکسازی', 'تعداد'],
            [
                ['شکست‌های قدیمی', $cleaned['old_failures']],
                ['منابع تکراری', $cleaned['duplicate_sources']],
                ['منابع بدون کتاب', $cleaned['orphaned_sources']]
            ]
        );

        if ($fix) {
            $this->info("✅ پاکسازی انجام شد!");
        } else {
            $this->info("👀 پیش‌نمایش پاکسازی (برای اعمال --fix اضافه کنید)");
        }
    }

    /**
     * تولید گزارش کامل
     */
    private function generateReport(Config $config): void
    {
        $this->info("📊 تولید گزارش کامل...");

        $report = SourceIdManager::generateDetailedReport($config);

        // ذخیره گزارش در فایل
        $filename = "source_report_{$config->id}_{$config->source_name}_" . now()->format('Y-m-d_H-i-s') . ".json";
        $filepath = storage_path("app/reports/{$filename}");

        // اطمینان از وجود پوشه
        if (!is_dir(dirname($filepath))) {
            mkdir(dirname($filepath), 0755, true);
        }

        file_put_contents($filepath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("📄 گزارش ذخیره شد: {$filepath}");

        // نمایش خلاصه گزارش
        $this->info("\n📋 خلاصه گزارش:");
        $analytics = $report['analytics'];

        $this->table(
            ['شاخص', 'مقدار'],
            [
                ['کل منابع', number_format($analytics['source_stats']['total_sources'])],
                ['درصد پوشش', $analytics['coverage_quality']['overall_percentage'] . '%'],
                ['نمره کیفیت', $analytics['coverage_quality']['quality_grade']],
                ['کل شکست‌ها', $analytics['failure_stats']['total_failures'] ?? 0],
                ['شکست‌های حل نشده', $analytics['failure_stats']['unresolved_failures'] ?? 0],
                ['متوسط زمان اجرا', round($report['performance_metrics']['avg_execution_time'], 2) . 's'],
                ['متوسط نرخ موفقیت', round($report['performance_metrics']['avg_success_rate'], 2) . '%']
            ]
        );

        // نمایش توصیه‌های کلیدی
        if (!empty($analytics['recommendations'])) {
            $this->info("\n🎯 توصیه‌های کلیدی:");
            foreach (array_slice($analytics['recommendations'], 0, 3) as $rec) {
                $this->line("   • {$rec['title']}");
            }
        }
    }

    /**
     * گروه‌بندی ID های پیوسته
     */
    private function groupConsecutiveIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        sort($ids);
        $groups = [];
        $currentGroup = ['start' => $ids[0], 'end' => $ids[0], 'count' => 1];

        for ($i = 1; $i < count($ids); $i++) {
            if ($ids[$i] === $currentGroup['end'] + 1) {
                // ID پیوسته است
                $currentGroup['end'] = $ids[$i];
                $currentGroup['count']++;
            } else {
                // شکاف یافت شد، گروه فعلی را ذخیره کن و گروه جدید شروع کن
                $groups[] = $currentGroup;
                $currentGroup = ['start' => $ids[$i], 'end' => $ids[$i], 'count' => 1];
            }
        }

        // اضافه کردن آخرین گروه
        $groups[] = $currentGroup;

        return $groups;
    }
}
