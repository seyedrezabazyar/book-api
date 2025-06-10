<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class Config extends Model
{
    protected $fillable = [
        'name',
        'base_url',
        'source_type',
        'source_name',
        'timeout',
        'delay_seconds',
        'records_per_run',
        'page_delay',
        'start_page',
        'max_pages',
        'current_page',
        'last_source_id',
        'auto_resume',
        'fill_missing_fields',
        'update_descriptions',
        'config_data',
        'created_by',
        'total_processed',
        'total_success',
        'total_failed',
        'last_run_at',
        'is_running'
    ];

    protected $casts = [
        'config_data' => 'array',
        'timeout' => 'integer',
        'delay_seconds' => 'integer',
        'records_per_run' => 'integer',
        'page_delay' => 'integer',
        'start_page' => 'integer',
        'max_pages' => 'integer',
        'current_page' => 'integer',
        'last_source_id' => 'integer',
        'total_processed' => 'integer',
        'total_success' => 'integer',
        'total_failed' => 'integer',
        'last_run_at' => 'datetime',
        'is_running' => 'boolean',
        'auto_resume' => 'boolean',
        'fill_missing_fields' => 'boolean',
        'update_descriptions' => 'boolean',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function executionLogs(): HasMany
    {
        return $this->hasMany(ExecutionLog::class);
    }

    /**
     * تعیین صفحه شروع هوشمند
     */
    public function getSmartStartPage(): int
    {
        // اولویت 1: اگر start_page مشخص شده، از آن استفاده کن
        if ($this->start_page && $this->start_page > 0) {
            Log::info("🎯 شروع از start_page تعیین شده", [
                'config_id' => $this->id,
                'start_page' => $this->start_page
            ]);
            return $this->start_page;
        }

        // اولویت 2: اگر auto_resume فعال باشد، از آخرین ID ادامه بده
        if ($this->auto_resume && $this->last_source_id > 0) {
            $nextId = $this->last_source_id + 1;
            Log::info("🔄 ادامه خودکار از آخرین ID", [
                'config_id' => $this->id,
                'last_source_id' => $this->last_source_id,
                'next_start' => $nextId
            ]);
            return $nextId;
        }

        // اولویت 3: آخرین ID از book_sources برای این منبع
        $lastIdFromSources = BookSource::where('source_name', $this->source_name)
            ->whereRaw('source_id REGEXP "^[0-9]+$"')
            ->orderByRaw('CAST(source_id AS UNSIGNED) DESC')
            ->value('source_id');

        if ($lastIdFromSources > 0) {
            $nextId = (int)$lastIdFromSources + 1;
            Log::info("📊 استفاده از آخرین ID در منبع", [
                'config_id' => $this->id,
                'source_name' => $this->source_name,
                'last_id_from_sources' => $lastIdFromSources,
                'next_start' => $nextId
            ]);
            return $nextId;
        }

        // پیش‌فرض: از 1 شروع کن
        Log::info("🆕 شروع جدید از ID 1", [
            'config_id' => $this->id,
            'source_name' => $this->source_name
        ]);
        return 1;
    }

    /**
     * ساخت URL API برای ID خاص
     */
    public function buildApiUrl(int $sourceId): string
    {
        $apiSettings = $this->getApiSettings();
        $baseUrl = rtrim($this->base_url, '/');
        $endpoint = $apiSettings['endpoint'] ?? '';

        $fullUrl = $baseUrl . ($endpoint ? '/' . ltrim($endpoint, '/') : '');

        // اضافه کردن ID به URL
        if (strpos($fullUrl, '{id}') !== false) {
            $fullUrl = str_replace('{id}', $sourceId, $fullUrl);
        } else {
            $params = ['id' => $sourceId];
            if (!empty($apiSettings['params'])) {
                $params = array_merge($params, $apiSettings['params']);
            }
            $fullUrl .= '?' . http_build_query($params);
        }

        return $fullUrl;
    }

    /**
     * بروزرسانی آخرین source_id و آمار با پشتیبانی از enhanced
     */
    public function updateProgress(int $sourceId, array $stats): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($sourceId, $stats) {
            $this->increment('total_processed', $stats['total'] ?? 0);
            $this->increment('total_success', $stats['success'] ?? 0);
            $this->increment('total_failed', $stats['failed'] ?? 0);

            // آمار enhanced به طور مستقیم در ExecutionLog ذخیره می‌شود
            // Config فقط آمار کلی نگهداری می‌کند

            // بروزرسانی آخرین ID اگر بزرگتر باشد
            if ($sourceId > $this->last_source_id) {
                $this->update([
                    'last_source_id' => $sourceId,
                    'current_page' => $sourceId,
                    'last_run_at' => now()
                ]);
            }
        });

        Log::info("📊 آمار کانفیگ بروزرسانی شد", [
            'config_id' => $this->id,
            'source_id' => $sourceId,
            'stats' => $stats,
            'new_totals' => [
                'total_processed' => $this->total_processed,
                'total_success' => $this->total_success,
                'total_failed' => $this->total_failed
            ]
        ]);
    }

    /**
     * همگام‌سازی آمار از لاگ‌ها - بهبود یافته
     */
    public function syncStatsFromLogs(): void
    {
        try {
            $stats = $this->executionLogs()
                ->whereIn('status', ['completed', 'stopped'])
                ->selectRaw('
                SUM(total_processed) as total_processed,
                SUM(total_success) as total_success,
                SUM(total_failed) as total_failed,
                SUM(total_enhanced) as total_enhanced
            ')
                ->first();

            if ($stats) {
                $this->update([
                    'total_processed' => $stats->total_processed ?? 0,
                    'total_success' => $stats->total_success ?? 0,
                    'total_failed' => $stats->total_failed ?? 0,
                    // total_enhanced در کانفیگ ذخیره نمی‌شود، فقط در لاگ‌ها
                ]);

                Log::info("🔄 آمار کانفیگ از لاگ‌ها همگام‌سازی شد", [
                    'config_id' => $this->id,
                    'synced_stats' => [
                        'total_processed' => $stats->total_processed,
                        'total_success' => $stats->total_success,
                        'total_enhanced' => $stats->total_enhanced,
                        'total_failed' => $stats->total_failed,
                    ]
                ]);
            }
        } catch (\Exception $e) {
            Log::error("❌ خطا در همگام‌سازی آمار از لاگ‌ها", [
                'config_id' => $this->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * آمار نمایشی پیشرفته
     */
    public function getDisplayStats(): array
    {
        try {
            $sourceCount = BookSource::where('source_name', $this->source_name)->count();

            // دریافت آمار enhanced از لاگ‌ها
            $enhancedStats = $this->executionLogs()
                ->whereIn('status', ['completed', 'stopped'])
                ->sum('total_enhanced');

            // محاسبه نرخ موفقیت واقعی
            $realSuccessCount = $this->total_success + $enhancedStats;
            $realSuccessRate = $this->total_processed > 0
                ? round(($realSuccessCount / $this->total_processed) * 100, 2)
                : 0;

            // محاسبه نرخ بهبود
            $enhancementRate = $this->total_processed > 0
                ? round(($enhancedStats / $this->total_processed) * 100, 2)
                : 0;

            return [
                'total_executions' => $this->executionLogs()->count(),
                'successful_executions' => $this->executionLogs()->where('status', 'completed')->count(),
                'total_processed' => $this->total_processed,
                'total_success' => $this->total_success,
                'total_enhanced' => $enhancedStats,
                'total_failed' => $this->total_failed,
                'success_rate' => $this->total_processed > 0
                    ? round(($this->total_success / $this->total_processed) * 100, 2)
                    : 0,
                'real_success_rate' => $realSuccessRate,
                'enhancement_rate' => $enhancementRate,
                'last_source_id' => $this->last_source_id,
                'next_source_id' => $this->getSmartStartPage(),
                'source_books_count' => $sourceCount,
                'impact_summary' => [
                    'total_impactful' => $realSuccessCount,
                    'new_books' => $this->total_success,
                    'enhanced_books' => $enhancedStats,
                    'failed_books' => $this->total_failed,
                    'duplicate_books' => max(0, $this->total_processed - $realSuccessCount - $this->total_failed)
                ]
            ];
        } catch (\Exception $e) {
            Log::error("❌ خطا در محاسبه آمار نمایشی", [
                'config_id' => $this->id,
                'error' => $e->getMessage()
            ]);

            // بازگشت آمار پایه در صورت خطا
            return [
                'total_executions' => $this->executionLogs()->count(),
                'successful_executions' => $this->executionLogs()->where('status', 'completed')->count(),
                'total_processed' => $this->total_processed,
                'total_success' => $this->total_success,
                'total_enhanced' => 0,
                'total_failed' => $this->total_failed,
                'success_rate' => 0,
                'real_success_rate' => 0,
                'enhancement_rate' => 0,
                'last_source_id' => $this->last_source_id,
                'next_source_id' => $this->getSmartStartPage(),
                'source_books_count' => 0,
            ];
        }
    }

    /**
     * دریافت خلاصه عملکرد
     */
    public function getPerformanceSummary(): array
    {
        $stats = $this->getDisplayStats();

        return [
            'total_impact' => $stats['impact_summary']['total_impactful'] ?? 0,
            'impact_rate' => $stats['real_success_rate'] ?? 0,
            'enhancement_contribution' => $stats['enhancement_rate'] ?? 0,
            'efficiency_score' => $this->calculateEfficiencyScore($stats),
            'quality_metrics' => [
                'data_completeness' => $this->calculateDataCompleteness(),
                'source_coverage' => $this->calculateSourceCoverage(),
                'update_frequency' => $this->calculateUpdateFrequency()
            ]
        ];
    }

    /**
     * محاسبه امتیاز کارایی
     */
    private function calculateEfficiencyScore(array $stats): float
    {
        if ($stats['total_processed'] <= 0) return 0;

        // وزن‌های مختلف برای محاسبه امتیاز
        $newBooksWeight = 0.4;
        $enhancementWeight = 0.3;
        $reliabilityWeight = 0.3;

        $newBooksScore = $stats['success_rate'] ?? 0;
        $enhancementScore = $stats['enhancement_rate'] ?? 0;
        $reliabilityScore = 100 - (($stats['total_failed'] / $stats['total_processed']) * 100);

        $totalScore = ($newBooksScore * $newBooksWeight) +
            ($enhancementScore * $enhancementWeight) +
            ($reliabilityScore * $reliabilityWeight);

        return round($totalScore, 1);
    }

    /**
     * محاسبه کاملیت داده‌ها
     */
    private function calculateDataCompleteness(): float
    {
        try {
            // این محاسبه بر اساس کتاب‌هایی که از این منبع آمده‌اند
            $sourceBooks = Book::whereHas('sources', function ($query) {
                $query->where('source_name', $this->source_name);
            })->get();

            if ($sourceBooks->isEmpty()) return 0;

            $totalFields = 0;
            $filledFields = 0;

            $checkFields = ['description', 'publication_year', 'pages_count', 'language', 'format'];

            foreach ($sourceBooks as $book) {
                foreach ($checkFields as $field) {
                    $totalFields++;
                    if (!empty($book->$field)) {
                        $filledFields++;
                    }
                }
            }

            return $totalFields > 0 ? round(($filledFields / $totalFields) * 100, 1) : 0;
        } catch (\Exception $e) {
            Log::error("خطا در محاسبه کاملیت داده‌ها", ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * محاسبه پوشش منبع
     */
    private function calculateSourceCoverage(): float
    {
        try {
            if ($this->last_source_id <= 0) return 0;

            $coveredIds = BookSource::where('source_name', $this->source_name)
                ->whereBetween('source_id', [1, $this->last_source_id])
                ->count();

            return round(($coveredIds / $this->last_source_id) * 100, 1);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * محاسبه فرکانس بروزرسانی
     */
    private function calculateUpdateFrequency(): string
    {
        try {
            $lastExecution = $this->executionLogs()->latest()->first();
            if (!$lastExecution) return 'هرگز';

            $daysSinceLastRun = now()->diffInDays($lastExecution->created_at);

            if ($daysSinceLastRun === 0) return 'امروز';
            if ($daysSinceLastRun === 1) return 'دیروز';
            if ($daysSinceLastRun <= 7) return 'این هفته';
            if ($daysSinceLastRun <= 30) return 'این ماه';

            return "بیش از {$daysSinceLastRun} روز پیش";
        } catch (\Exception $e) {
            return 'نامشخص';
        }
    }

    /**
     * بررسی وجود source ID
     */
    public function hasSourceId(int $sourceId): bool
    {
        return BookSource::where('source_name', $this->source_name)
            ->where('source_id', (string)$sourceId)
            ->exists();
    }

    /**
     * یافتن source ID های مفقود
     */
    public function findMissingSourceIds(int $startId, int $endId, int $limit = 100): array
    {
        $existingIds = BookSource::where('source_name', $this->source_name)
            ->whereBetween(\Illuminate\Support\Facades\DB::raw('CAST(source_id AS UNSIGNED)'), [$startId, $endId])
            ->pluck('source_id')
            ->map(fn($id) => (int)$id)
            ->toArray();

        $allIds = range($startId, $endId);
        $missingIds = array_diff($allIds, $existingIds);

        return array_slice(array_values($missingIds), 0, $limit);
    }

    /**
     * تنظیمات API
     */
    public function getApiSettings(): array
    {
        return $this->config_data['api'] ?? [];
    }

    public function getGeneralSettings(): array
    {
        return $this->config_data['general'] ?? [];
    }

    /**
     * فیلدهای قابل نقشه‌برداری
     */
    public static function getBookFields(): array
    {
        return [
            'title' => 'عنوان کتاب',
            'description' => 'توضیحات',
            'author' => 'نویسنده',
            'publisher' => 'ناشر',
            'category' => 'دسته‌بندی',
            'isbn' => 'شابک',
            'publication_year' => 'سال انتشار',
            'pages_count' => 'تعداد صفحات',
            'language' => 'زبان',
            'format' => 'فرمت فایل',
            'file_size' => 'حجم فایل',
            'image_url' => 'تصویر کتاب'
        ];
    }

    /**
     * ریست کردن آمار برای شروع مجدد
     */
    public function resetForRestart(): void
    {
        $this->update([
            'current_page' => $this->getSmartStartPage(),
            'is_running' => false
        ]);

        Log::info("🔄 کانفیگ برای شروع مجدد ریست شد", [
            'config_id' => $this->id,
            'new_start_page' => $this->current_page
        ]);
    }
}
