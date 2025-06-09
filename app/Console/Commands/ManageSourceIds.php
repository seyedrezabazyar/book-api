<?php

namespace App\Console\Commands;

use App\Models\Config;
use App\Models\BookSource;
use App\Models\ScrapingFailure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ManageSourceIds extends Command
{
    protected $signature = 'crawl:manage-sources
                            {action : Action to perform (analyze|missing|cleanup|report)}
                            {--config= : Specific config ID to work with}
                            {--range= : ID range for analysis (e.g., 1-1000)}
                            {--limit=100 : Limit for results}
                            {--fix : Actually perform fixes (not just dry run)}';

    protected $description = 'مدیریت و تحلیل Source ID های کرال شده - ساده شده';

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

    private function getConfigs($configId)
    {
        if ($configId) {
            return Config::where('id', $configId)->get();
        }
        return Config::all();
    }

    private function analyzeConfig(Config $config): void
    {
        $this->info("🔍 تحلیل کانفیگ {$config->name}...");

        $sourceStats = $config->getSourceStats();

        // نمایش آمار منبع
        $this->table(
            ['متریک', 'مقدار'],
            [
                ['نام منبع', $sourceStats['source_name']],
                ['کل رکوردها', number_format($sourceStats['total_records'])],
                ['کتاب‌های یکتا', number_format($sourceStats['unique_books'])],
                ['اولین ID', $sourceStats['first_source_id']],
                ['آخرین ID', $sourceStats['last_source_id']],
                ['بازه پوشش', $sourceStats['id_range']]
            ]
        );

        // بررسی تطابق با آمار کانفیگ
        $this->info("\n🔍 مقایسه با آمار کانفیگ:");
        $this->table(
            ['آمار', 'کانفیگ', 'منبع', 'تطابق'],
            [
                [
                    'کل موفق',
                    number_format($config->total_success),
                    number_format($sourceStats['unique_books']),
                    $config->total_success == $sourceStats['unique_books'] ? '✅' : '❌'
                ],
                [
                    'آخرین ID',
                    $config->last_source_id,
                    $sourceStats['last_source_id'],
                    $config->last_source_id == $sourceStats['last_source_id'] ? '✅' : '❌'
                ]
            ]
        );

        // یافتن ID های مفقود در بازه کوچک
        $startCheck = max(1, $sourceStats['last_source_id'] - 100);
        $endCheck = $sourceStats['last_source_id'];
        $missingIds = $config->findMissingSourceIds($startCheck, $endCheck, 20);

        if (!empty($missingIds)) {
            $this->warn("⚠️ " . count($missingIds) . " ID مفقود در بازه {$startCheck}-{$endCheck}:");
            $this->line("   " . implode(', ', array_slice($missingIds, 0, 10)));
        } else {
            $this->info("✅ هیچ ID مفقودی در بازه اخیر یافت نشد");
        }

        // توصیه‌ها
        $this->info("\n💡 توصیه‌ها:");
        if ($config->total_success < $sourceStats['unique_books']) {
            $diff = $sourceStats['unique_books'] - $config->total_success;
            $this->line("   🔧 آمار کانفیگ {$diff} کتاب کمتر از واقعیت است - نیاز به همگام‌سازی");
        }
        if (!empty($missingIds)) {
            $this->line("   🔍 برای یافتن همه ID های مفقود: --action=missing");
        }
    }

    private function findMissingIds(Config $config, $range, int $limit): void
    {
        $this->info("🔍 جستجوی ID های مفقود...");

        if ($range) {
            [$startId, $endId] = explode('-', $range);
            $startId = (int) $startId;
            $endId = (int) $endId;
        } else {
            $sourceStats = $config->getSourceStats();
            $startId = 1;
            $endId = max($config->last_source_id, $sourceStats['last_source_id']);
        }

        if ($endId <= $startId) {
            $this->warn("⚠️ بازه نامعتبر: {$startId}-{$endId}");
            return;
        }

        $this->info("   بررسی بازه: {$startId} تا {$endId}");

        $missingIds = $config->findMissingSourceIds($startId, $endId, $limit);

        if (empty($missingIds)) {
            $this->info("✅ هیچ ID مفقودی در این بازه یافت نشد!");
            return;
        }

        $this->warn("📋 " . count($missingIds) . " ID مفقود یافت شد:");

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

    private function cleanupConfig(Config $config, bool $fix): void
    {
        $this->info("🧹 پاکسازی کانفیگ {$config->name}...");

        if (!$fix) {
            $this->warn("⚠️ حالت Dry Run - برای اعمال تغییرات از --fix استفاده کنید");
        }

        $cleaned = [
            'old_failures' => 0,
            'duplicate_sources' => 0,
            'orphaned_books' => 0
        ];

        // پاکسازی شکست‌های قدیمی
        $oldFailuresQuery = ScrapingFailure::where('config_id', $config->id)
            ->where('created_at', '<', now()->subDays(30))
            ->where('is_resolved', true);

        $cleaned['old_failures'] = $oldFailuresQuery->count();
        if ($fix) {
            $oldFailuresQuery->delete();
        }

        // پاکسازی منابع تکراری
        if ($fix) {
            $cleaned['duplicate_sources'] = BookSource::cleanupDuplicates();
        } else {
            // شمارش تکراری‌ها
            $duplicates = DB::table('book_sources')
                ->select('book_id', 'source_name', 'source_id')
                ->selectRaw('COUNT(*) as count')
                ->groupBy('book_id', 'source_name', 'source_id')
                ->having('count', '>', 1)
                ->get();
            $cleaned['duplicate_sources'] = $duplicates->sum('count') - $duplicates->count();
        }

        // پاکسازی کتاب‌های بدون منبع (orphaned)
        $orphanedBooksQuery = DB::table('books')
            ->leftJoin('book_sources', 'books.id', '=', 'book_sources.book_id')
            ->whereNull('book_sources.id');

        $cleaned['orphaned_books'] = $orphanedBooksQuery->count();
        if ($fix) {
            $orphanedBooksQuery->delete();
        }

        $this->table(
            ['نوع پاکسازی', 'تعداد'],
            [
                ['شکست‌های قدیمی', $cleaned['old_failures']],
                ['منابع تکراری', $cleaned['duplicate_sources']],
                ['کتاب‌های بدون منبع', $cleaned['orphaned_books']]
            ]
        );

        if ($fix) {
            $this->info("✅ پاکسازی انجام شد!");
        } else {
            $this->info("👀 پیش‌نمایش پاکسازی (برای اعمال --fix اضافه کنید)");
        }
    }

    private function generateReport(Config $config): void
    {
        $this->info("📊 تولید گزارش کامل...");

        $sourceStats = $config->getSourceStats();
        $configStats = $config->getDisplayStats();

        $report = [
            'config_info' => [
                'id' => $config->id,
                'name' => $config->name,
                'source_name' => $config->source_name,
                'source_type' => $config->source_type,
                'last_source_id' => $config->last_source_id,
                'total_success' => $config->total_success,
                'total_processed' => $config->total_processed
            ],
            'source_analysis' => $sourceStats,
            'config_stats' => $configStats,
            'data_integrity' => [
                'config_vs_source_match' => $config->total_success == $sourceStats['unique_books'],
                'last_id_match' => $config->last_source_id == $sourceStats['last_source_id'],
                'missing_ids_sample' => $config->findMissingSourceIds(
                    max(1, $sourceStats['last_source_id'] - 100),
                    $sourceStats['last_source_id'],
                    10
                )
            ],
            'recommendations' => $this->generateRecommendations($config, $sourceStats),
            'generated_at' => now()->toISOString()
        ];

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
        $this->table(
            ['شاخص', 'مقدار'],
            [
                ['کل رکوردهای منبع', number_format($sourceStats['total_records'])],
                ['کتاب‌های یکتا منبع', number_format($sourceStats['unique_books'])],
                ['آمار موفق کانفیگ', number_format($config->total_success)],
                ['تطابق آمار', $config->total_success == $sourceStats['unique_books'] ? '✅ بله' : '❌ خیر'],
                ['آخرین ID منبع', $sourceStats['last_source_id']],
                ['آخرین ID کانفیگ', $config->last_source_id],
                ['تطابق ID', $config->last_source_id == $sourceStats['last_source_id'] ? '✅ بله' : '❌ خیر']
            ]
        );

        // نمایش توصیه‌های کلیدی
        if (!empty($report['recommendations'])) {
            $this->info("\n🎯 توصیه‌های کلیدی:");
            foreach (array_slice($report['recommendations'], 0, 3) as $rec) {
                $priority = match($rec['priority']) {
                    'high' => '🔴',
                    'medium' => '🟡',
                    default => '🟢'
                };
                $this->line("   {$priority} {$rec['title']}");
            }
        }
    }

    private function generateRecommendations(Config $config, array $sourceStats): array
    {
        $recommendations = [];

        // بررسی عدم تطابق آمار
        if ($config->total_success != $sourceStats['unique_books']) {
            $diff = abs($config->total_success - $sourceStats['unique_books']);
            $recommendations[] = [
                'type' => 'stats_mismatch',
                'priority' => 'high',
                'title' => "عدم تطابق آمار ({$diff} اختلاف)",
                'description' => 'آمار کانفیگ با آمار واقعی منبع تطابق ندارد',
                'action' => 'بروزرسانی آمار کانفیگ یا بررسی مجدد داده‌ها'
            ];
        }

        // بررسی عدم تطابق آخرین ID
        if ($config->last_source_id != $sourceStats['last_source_id']) {
            $recommendations[] = [
                'type' => 'last_id_mismatch',
                'priority' => 'medium',
                'title' => 'عدم تطابق آخرین ID',
                'description' => 'آخرین ID کانفیگ با آخرین ID منبع تطابق ندارد',
                'action' => 'بروزرسانی last_source_id کانفیگ'
            ];
        }

        // بررسی ID های مفقود
        $missingIds = $config->findMissingSourceIds(
            max(1, $sourceStats['last_source_id'] - 1000),
            $sourceStats['last_source_id'],
            50
        );

        if (!empty($missingIds)) {
            $recommendations[] = [
                'type' => 'missing_ids',
                'priority' => 'medium',
                'title' => count($missingIds) . " ID مفقود در بازه اخیر",
                'description' => 'برخی ID ها در بازه اخیر پردازش نشده‌اند',
                'action' => 'اجرای دستور crawl:missing-ids برای بازیابی'
            ];
        }

        // بررسی کیفیت پوشش
        if ($sourceStats['total_records'] > 0) {
            $coverageRate = ($sourceStats['unique_books'] / $sourceStats['total_records']) * 100;
            if ($coverageRate < 80) {
                $recommendations[] = [
                    'type' => 'low_coverage',
                    'priority' => 'low',
                    'title' => "پوشش پایین ({$coverageRate}%)",
                    'description' => 'نسبت کتاب‌های یکتا به کل رکوردها پایین است',
                    'action' => 'بررسی کیفیت داده‌ها و حذف تکراری‌ها'
                ];
            }
        }

        return $recommendations;
    }

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
