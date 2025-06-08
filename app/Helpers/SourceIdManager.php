<?php

namespace App\Helpers;

use App\Models\BookSource;
use App\Models\Config;
use App\Models\ScrapingFailure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SourceIdManager
{
    /**
     * تشخیص بهترین نقطه شروع برای یک کانفیگ
     */
    public static function getBestStartPoint(Config $config): int
    {
        // اولویت 1: اگر start_page مشخص شده
        if ($config->start_page && $config->start_page > 0) {
            Log::info("🎯 استفاده از start_page تعیین شده", [
                'config_id' => $config->id,
                'start_page' => $config->start_page
            ]);
            return $config->start_page;
        }

        // اولویت 2: اگر auto_resume فعال باشد، از آخرین ID ادامه بده
        if ($config->auto_resume && $config->last_source_id > 0) {
            $nextId = $config->last_source_id + 1;
            Log::info("🔄 ادامه خودکار از آخرین ID", [
                'config_id' => $config->id,
                'last_source_id' => $config->last_source_id,
                'next_start' => $nextId
            ]);
            return $nextId;
        }

        // اولویت 3: آخرین ID از book_sources
        $lastIdFromSources = BookSource::getLastSourceIdForType(
            $config->source_type,
            $config->source_name
        );

        if ($lastIdFromSources > 0) {
            $nextId = $lastIdFromSources + 1;
            Log::info("📊 استفاده از آخرین ID در book_sources", [
                'config_id' => $config->id,
                'last_id_from_sources' => $lastIdFromSources,
                'next_start' => $nextId
            ]);
            return $nextId;
        }

        // پیش‌فرض: از 1 شروع کن
        Log::info("🆕 شروع جدید از ID 1", [
            'config_id' => $config->id
        ]);
        return 1;
    }

    /**
     * یافتن source ID های مفقود در یک بازه
     */
    public static function findMissingIds(Config $config, int $startId, int $endId, int $limit = 100): array
    {
        // دریافت ID های موجود
        $existingIds = BookSource::where('source_type', $config->source_type)
            ->whereRaw('CAST(source_id AS UNSIGNED) BETWEEN ? AND ?', [$startId, $endId])
            ->whereHas('book', function ($q) {
                $q->where('status', 'active');
            })
            ->pluck('source_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->toArray();

        // دریافت ID های شکست خورده
        $failedIds = ScrapingFailure::where('config_id', $config->id)
            ->whereBetween(DB::raw('JSON_EXTRACT(error_details, "$.source_id")'), [$startId, $endId])
            ->pluck(DB::raw('JSON_EXTRACT(error_details, "$.source_id")'))
            ->map(function ($id) {
                return (int) $id;
            })
            ->toArray();

        // محاسبه ID های مفقود
        $allIds = range($startId, $endId);
        $processedIds = array_unique(array_merge($existingIds, $failedIds));
        $missingIds = array_diff($allIds, $processedIds);

        // محدود کردن نتایج
        $missingIds = array_slice(array_values($missingIds), 0, $limit);

        Log::info("🔍 جستجوی ID های مفقود", [
            'config_id' => $config->id,
            'range' => "{$startId}-{$endId}",
            'existing_count' => count($existingIds),
            'failed_count' => count($failedIds),
            'missing_count' => count($missingIds),
            'sample_missing' => array_slice($missingIds, 0, 10)
        ]);

        return $missingIds;
    }

    /**
     * تولید لیست بهینه ID ها برای پردازش
     */
    public static function generateOptimalIdList(Config $config, int $maxIds = 1000): array
    {
        $startId = static::getBestStartPoint($config);
        $endId = $startId + $maxIds - 1;

        // روش 1: اگر fill_missing_fields فعال باشد، اول ID های مفقود را پر کن
        if ($config->fill_missing_fields) {
            $missingIds = static::findMissingIds($config, 1, $startId - 1, $maxIds);

            if (!empty($missingIds)) {
                Log::info("🔧 استفاده از ID های مفقود", [
                    'config_id' => $config->id,
                    'missing_count' => count($missingIds),
                    'sample_ids' => array_slice($missingIds, 0, 10)
                ]);
                return $missingIds;
            }
        }

        // روش 2: لیست پیوسته از startId
        $sequentialIds = range($startId, $endId);

        Log::info("📈 استفاده از ID های پیوسته", [
            'config_id' => $config->id,
            'start_id' => $startId,
            'end_id' => $endId,
            'total_ids' => count($sequentialIds)
        ]);

        return $sequentialIds;
    }

    /**
     * آمار کامل یک منبع
     */
    public static function getSourceAnalytics(Config $config): array
    {
        $sourceStats = BookSource::getSourceStats($config->source_type, $config->source_name);

        // آمار شکست‌ها
        $failureStats = ScrapingFailure::where('config_id', $config->id)
            ->selectRaw('
                COUNT(*) as total_failures,
                COUNT(CASE WHEN is_resolved = 0 THEN 1 END) as unresolved_failures,
                MIN(JSON_EXTRACT(error_details, "$.source_id")) as first_failed_id,
                MAX(JSON_EXTRACT(error_details, "$.source_id")) as last_failed_id
            ')
            ->first();

        // آمار بازه‌های مفقود
        $missingRanges = static::findMissingRanges($config, 1, $sourceStats['last_source_id']);

        // محاسبه کیفیت پوشش
        $coverageQuality = static::calculateCoverageQuality($config, $sourceStats);

        return [
            'source_stats' => $sourceStats,
            'failure_stats' => $failureStats ? $failureStats->toArray() : [],
            'missing_ranges' => $missingRanges,
            'coverage_quality' => $coverageQuality,
            'recommendations' => static::generateRecommendations($config, $sourceStats, $missingRanges)
        ];
    }

    /**
     * یافتن بازه‌های مفقود
     */
    private static function findMissingRanges(Config $config, int $startId, int $endId): array
    {
        if ($endId <= $startId) {
            return [];
        }

        $existingIds = BookSource::where('source_type', $config->source_type)
            ->whereRaw('CAST(source_id AS UNSIGNED) BETWEEN ? AND ?', [$startId, $endId])
            ->orderByRaw('CAST(source_id AS UNSIGNED) ASC')
            ->pluck('source_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->toArray();

        $ranges = [];
        $rangeStart = null;

        for ($id = $startId; $id <= $endId; $id++) {
            $exists = in_array($id, $existingIds);

            if (!$exists && $rangeStart === null) {
                $rangeStart = $id;
            } elseif ($exists && $rangeStart !== null) {
                $ranges[] = [
                    'start' => $rangeStart,
                    'end' => $id - 1,
                    'count' => $id - $rangeStart
                ];
                $rangeStart = null;
            }
        }

        // اگر بازه آخر همچنان باز است
        if ($rangeStart !== null) {
            $ranges[] = [
                'start' => $rangeStart,
                'end' => $endId,
                'count' => $endId - $rangeStart + 1
            ];
        }

        return array_filter($ranges, function ($range) {
            return $range['count'] >= 3; // فقط بازه‌های 3 یا بیشتر
        });
    }

    /**
     * محاسبه کیفیت پوشش
     */
    private static function calculateCoverageQuality(Config $config, array $sourceStats): array
    {
        $totalPossible = $sourceStats['last_source_id'];
        $totalExists = $sourceStats['total_sources'];

        if ($totalPossible <= 0) {
            return [
                'overall_percentage' => 0,
                'quality_grade' => 'F',
                'quality_description' => 'هیچ داده‌ای موجود نیست'
            ];
        }

        $percentage = ($totalExists / $totalPossible) * 100;

        $grade = 'F';
        $description = 'خیلی ضعیف';

        if ($percentage >= 95) {
            $grade = 'A+';
            $description = 'عالی';
        } elseif ($percentage >= 90) {
            $grade = 'A';
            $description = 'خیلی خوب';
        } elseif ($percentage >= 80) {
            $grade = 'B';
            $description = 'خوب';
        } elseif ($percentage >= 70) {
            $grade = 'C';
            $description = 'متوسط';
        } elseif ($percentage >= 50) {
            $grade = 'D';
            $description = 'ضعیف';
        }

        return [
            'overall_percentage' => round($percentage, 2),
            'quality_grade' => $grade,
            'quality_description' => $description,
            'total_possible' => $totalPossible,
            'total_exists' => $totalExists,
            'total_missing' => $totalPossible - $totalExists
        ];
    }

    /**
     * تولید توصیه‌های بهبود
     */
    private static function generateRecommendations(Config $config, array $sourceStats, array $missingRanges): array
    {
        $recommendations = [];

        // توصیه برای ID های مفقود
        if (!empty($missingRanges)) {
            $totalMissing = array_sum(array_column($missingRanges, 'count'));
            $recommendations[] = [
                'type' => 'missing_ids',
                'priority' => 'high',
                'title' => "پر کردن {$totalMissing} ID مفقود",
                'description' => 'تعدادی ID در بازه‌های مختلف مفقود هستند که می‌توانند دریافت شوند.',
                'action' => 'فعال کردن گزینه "تکمیل فیلدهای خالی" و اجرای مجدد'
            ];
        }

        // توصیه برای پوشش کم
        $coverage = ($sourceStats['total_sources'] / max($sourceStats['last_source_id'], 1)) * 100;
        if ($coverage < 80) {
            $recommendations[] = [
                'type' => 'low_coverage',
                'priority' => 'medium',
                'title' => 'پوشش پایین منبع',
                'description' => "فقط {$coverage}% از ID های ممکن پوشش داده شده‌اند.",
                'action' => 'بررسی تنظیمات API و اجرای مجدد با تاخیر کمتر'
            ];
        }

        // توصیه برای شکست‌های زیاد
        $unresolved = ScrapingFailure::where('config_id', $config->id)
            ->where('is_resolved', false)
            ->count();

        if ($unresolved > 50) {
            $recommendations[] = [
                'type' => 'high_failures',
                'priority' => 'high',
                'title' => "{$unresolved} شکست حل نشده",
                'description' => 'تعداد زیادی از درخواست‌ها با شکست مواجه شده‌اند.',
                'action' => 'بررسی تنظیمات timeout و نقشه‌برداری فیلدها'
            ];
        }

        return $recommendations;
    }

    /**
     * پاکسازی هوشمند داده‌ها
     */
    public static function smartCleanup(Config $config): array
    {
        $cleaned = [
            'old_failures' => 0,
            'duplicate_sources' => 0,
            'orphaned_sources' => 0
        ];

        // پاکسازی شکست‌های قدیمی
        $cleaned['old_failures'] = ScrapingFailure::where('config_id', $config->id)
            ->where('created_at', '<', now()->subDays(30))
            ->where('is_resolved', true)
            ->delete();

        // پاکسازی منابع تکراری
        $duplicates = BookSource::findDuplicateSources();
        foreach ($duplicates as $duplicate) {
            if (count($duplicate['sources']) > 1) {
                // نگه داشتن جدیدترین و حذف بقیه
                $sources = collect($duplicate['sources'])->sortByDesc('created_at');
                $toDelete = $sources->slice(1);

                foreach ($toDelete as $source) {
                    $source->delete();
                    $cleaned['duplicate_sources']++;
                }
            }
        }

        // پاکسازی منابع بدون کتاب
        $cleaned['orphaned_sources'] = BookSource::whereDoesntHave('book')
            ->delete();

        Log::info("🧹 پاکسازی هوشمند انجام شد", [
            'config_id' => $config->id,
            'cleaned' => $cleaned
        ]);

        return $cleaned;
    }

    /**
     * تولید گزارش تفصیلی
     */
    public static function generateDetailedReport(Config $config): array
    {
        $analytics = static::getSourceAnalytics($config);
        $recentActivity = static::getRecentActivity($config);
        $performanceMetrics = static::getPerformanceMetrics($config);

        return [
            'config_info' => [
                'id' => $config->id,
                'name' => $config->name,
                'source_name' => $config->source_name,
                'source_type' => $config->source_type,
                'last_source_id' => $config->last_source_id,
                'total_success' => $config->total_success,
                'total_processed' => $config->total_processed
            ],
            'analytics' => $analytics,
            'recent_activity' => $recentActivity,
            'performance_metrics' => $performanceMetrics,
            'generated_at' => now()->toISOString()
        ];
    }

    /**
     * فعالیت‌های اخیر
     */
    private static function getRecentActivity(Config $config): array
    {
        $recentSources = BookSource::where('source_type', $config->source_type)
            ->where('created_at', '>=', now()->subDays(7))
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->with('book:id,title')
            ->get();

        $recentFailures = ScrapingFailure::where('config_id', $config->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return [
            'recent_sources' => $recentSources->toArray(),
            'recent_failures' => $recentFailures->toArray(),
            'activity_summary' => [
                'sources_last_7_days' => $recentSources->count(),
                'failures_last_7_days' => $recentFailures->count(),
                'success_rate_last_7_days' => $recentSources->count() > 0 ?
                    round(($recentSources->count() / ($recentSources->count() + $recentFailures->count())) * 100, 2) : 0
            ]
        ];
    }

    /**
     * معیارهای عملکرد
     */
    private static function getPerformanceMetrics(Config $config): array
    {
        $executions = $config->executionLogs()
            ->where('created_at', '>=', now()->subDays(30))
            ->get();

        if ($executions->isEmpty()) {
            return [
                'avg_execution_time' => 0,
                'avg_success_rate' => 0,
                'avg_records_per_minute' => 0,
                'total_executions' => 0
            ];
        }

        return [
            'avg_execution_time' => round($executions->avg('execution_time'), 2),
            'avg_success_rate' => round($executions->avg('success_rate'), 2),
            'avg_records_per_minute' => round($executions->avg('records_per_minute'), 2),
            'total_executions' => $executions->count(),
            'best_execution' => [
                'execution_id' => $executions->sortByDesc('success_rate')->first()?->execution_id,
                'success_rate' => $executions->max('success_rate'),
                'date' => $executions->sortByDesc('success_rate')->first()?->created_at
            ]
        ];
    }
}
