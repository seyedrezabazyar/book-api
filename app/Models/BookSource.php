<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class BookSource extends Model
{
    protected $table = 'book_sources';

    protected $fillable = [
        'book_id',
        'source_name',
        'source_id',
        'discovered_at',
    ];

    protected $casts = [
        'discovered_at' => 'datetime',
    ];

    /**
     * روابط
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * اسکوپ‌ها
     */
    public function scopeBySourceName($query, string $sourceName): Builder
    {
        return $query->where('source_name', $sourceName);
    }

    public function scopeBySourceId($query, string $sourceId): Builder
    {
        return $query->where('source_id', $sourceId);
    }

    public function scopeNumericSourceIds($query): Builder
    {
        return $query->whereRaw('source_id REGEXP "^[0-9]+$"');
    }

    /**
     * ثبت یا بروزرسانی منبع کتاب - بهبود یافته
     */
    public static function recordBookSource(int $bookId, string $sourceName, string $sourceId): self
    {
        Log::debug("📝 شروع ثبت منبع کتاب", [
            'book_id' => $bookId,
            'source_name' => $sourceName,
            'source_id' => $sourceId
        ]);

        try {
            $source = self::updateOrCreate(
                [
                    'book_id' => $bookId,
                    'source_name' => $sourceName,
                    'source_id' => $sourceId
                ],
                [
                    'discovered_at' => now()
                ]
            );

            Log::info("✅ منبع کتاب ثبت شد", [
                'book_id' => $bookId,
                'source_name' => $sourceName,
                'source_id' => $sourceId,
                'was_existing' => !$source->wasRecentlyCreated,
                'source_record_id' => $source->id
            ]);

            // پاک کردن کش مربوطه
            self::clearRelatedCache($sourceName);

            return $source;

        } catch (\Exception $e) {
            Log::error("❌ خطا در ثبت منبع کتاب", [
                'book_id' => $bookId,
                'source_name' => $sourceName,
                'source_id' => $sourceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * بررسی وجود منبع خاص - بهبود یافته
     */
    public static function sourceExists(string $sourceName, string $sourceId): bool
    {
        $cacheKey = "source_exists_{$sourceName}_{$sourceId}";

        return Cache::remember($cacheKey, 300, function () use ($sourceName, $sourceId) {
            return self::where('source_name', $sourceName)
                ->where('source_id', $sourceId)
                ->exists();
        });
    }

    /**
     * یافتن کتاب بر اساس منبع و ID - بهبود یافته
     */
    public static function findBookBySource(string $sourceName, string $sourceId): ?Book
    {
        $cacheKey = "book_by_source_{$sourceName}_{$sourceId}";

        return Cache::remember($cacheKey, 600, function () use ($sourceName, $sourceId) {
            $source = self::where('source_name', $sourceName)
                ->where('source_id', $sourceId)
                ->with('book')
                ->first();

            return $source?->book;
        });
    }

    /**
     * دریافت آخرین source_id عددی برای منبع خاص - بهبود یافته
     */
    public static function getLastNumericSourceId(string $sourceName): int
    {
        $cacheKey = "last_numeric_id_{$sourceName}";

        return Cache::remember($cacheKey, 60, function () use ($sourceName) {
            try {
                $result = self::where('source_name', $sourceName)
                    ->whereRaw('source_id REGEXP "^[0-9]+$"') // فقط source_id های عددی
                    ->orderByRaw('CAST(source_id AS UNSIGNED) DESC')
                    ->value('source_id');

                $lastId = $result ? (int) $result : 0;

                Log::debug("🔍 آخرین ID عددی دریافت شد", [
                    'source_name' => $sourceName,
                    'last_numeric_id' => $lastId
                ]);

                return $lastId;

            } catch (\Exception $e) {
                Log::error("❌ خطا در دریافت آخرین ID عددی", [
                    'source_name' => $sourceName,
                    'error' => $e->getMessage()
                ]);
                return 0;
            }
        });
    }

    /**
     * آمار منبع خاص - بهبود یافته
     */
    public static function getSourceStats(string $sourceName): array
    {
        $cacheKey = "source_stats_{$sourceName}";

        return Cache::remember($cacheKey, 300, function () use ($sourceName) {
            try {
                $stats = self::where('source_name', $sourceName)
                    ->selectRaw('
                        COUNT(*) as total_records,
                        COUNT(DISTINCT book_id) as unique_books,
                        MIN(CASE WHEN source_id REGEXP "^[0-9]+$" THEN CAST(source_id AS UNSIGNED) END) as first_id,
                        MAX(CASE WHEN source_id REGEXP "^[0-9]+$" THEN CAST(source_id AS UNSIGNED) END) as last_id
                    ')
                    ->first();

                $result = [
                    'source_name' => $sourceName,
                    'total_records' => $stats->total_records ?? 0,
                    'unique_books' => $stats->unique_books ?? 0,
                    'first_source_id' => $stats->first_id ?? 0,
                    'last_source_id' => $stats->last_id ?? 0,
                    'id_range' => ($stats->first_id && $stats->last_id) ?
                        "{$stats->first_id}-{$stats->last_id}" : '0-0',
                    'coverage_percentage' => 0
                ];

                // محاسبه درصد پوشش
                if ($stats->first_id && $stats->last_id && $stats->total_records) {
                    $expectedRange = $stats->last_id - $stats->first_id + 1;
                    $result['coverage_percentage'] = round(($stats->total_records / $expectedRange) * 100, 2);
                }

                Log::debug("📊 آمار منبع محاسبه شد", [
                    'source_name' => $sourceName,
                    'stats' => $result
                ]);

                return $result;

            } catch (\Exception $e) {
                Log::error("❌ خطا در محاسبه آمار منبع", [
                    'source_name' => $sourceName,
                    'error' => $e->getMessage()
                ]);

                return [
                    'source_name' => $sourceName,
                    'total_records' => 0,
                    'unique_books' => 0,
                    'first_source_id' => 0,
                    'last_source_id' => 0,
                    'id_range' => '0-0',
                    'coverage_percentage' => 0
                ];
            }
        });
    }

    /**
     * یافتن source_id های مفقود در بازه - بهبود یافته
     */
    public static function findMissingSourceIds(string $sourceName, int $startId, int $endId, int $limit = 100): array
    {
        try {
            Log::info("🔍 جستجوی ID های مفقود", [
                'source_name' => $sourceName,
                'range' => "{$startId}-{$endId}",
                'limit' => $limit
            ]);

            // دریافت ID های موجود
            $existingIds = self::where('source_name', $sourceName)
                ->whereBetween(DB::raw('CAST(source_id AS UNSIGNED)'), [$startId, $endId])
                ->whereRaw('source_id REGEXP "^[0-9]+$"')
                ->pluck('source_id')
                ->map(fn($id) => (int) $id)
                ->sort()
                ->values()
                ->toArray();

            // محاسبه ID های مفقود
            $allIds = range($startId, $endId);
            $missingIds = array_diff($allIds, $existingIds);

            // محدود کردن نتایج
            $missingIds = array_slice(array_values($missingIds), 0, $limit);

            Log::info("📋 نتیجه جستجوی ID های مفقود", [
                'source_name' => $sourceName,
                'range' => "{$startId}-{$endId}",
                'existing_count' => count($existingIds),
                'missing_count' => count($missingIds),
                'sample_missing' => array_slice($missingIds, 0, 10)
            ]);

            return $missingIds;

        } catch (\Exception $e) {
            Log::error("❌ خطا در یافتن ID های مفقود", [
                'source_name' => $sourceName,
                'range' => "{$startId}-{$endId}",
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * دریافت منابع یک کتاب - بهبود یافته
     */
    public static function getBookSources(int $bookId): array
    {
        $cacheKey = "book_sources_{$bookId}";

        return Cache::remember($cacheKey, 300, function () use ($bookId) {
            return self::where('book_id', $bookId)
                ->orderBy('discovered_at', 'desc')
                ->get()
                ->map(function ($source) {
                    return [
                        'source_name' => $source->source_name,
                        'source_id' => $source->source_id,
                        'discovered_at' => $source->discovered_at,
                        'url' => self::buildSourceUrl($source->source_name, $source->source_id),
                        'is_numeric' => is_numeric($source->source_id)
                    ];
                })
                ->toArray();
        });
    }

    /**
     * ساخت URL منبع - بهبود یافته
     */
    private static function buildSourceUrl(string $sourceName, string $sourceId): ?string
    {
        $urlTemplates = [
            'libgen_rs' => 'http://libgen.rs/book/index.php?md5={id}',
            'zlib' => 'https://z-lib.org/book/{id}',
            'anna_archive' => 'https://annas-archive.org/md5/{id}',
            'libgen_is' => 'https://libgen.is/book/index.php?md5={id}',
            'sci_hub' => 'https://sci-hub.se/{id}',
            // افزودن منابع دیگر در صورت نیاز
        ];

        return isset($urlTemplates[$sourceName]) ?
            str_replace('{id}', $sourceId, $urlTemplates[$sourceName]) : null;
    }

    /**
     * پاکسازی منابع تکراری - بهبود یافته
     */
    public static function cleanupDuplicates(): int
    {
        try {
            Log::info("🧹 شروع پاکسازی منابع تکراری");

            // یافتن منابع تکراری
            $duplicates = DB::table('book_sources')
                ->select('book_id', 'source_name', 'source_id')
                ->selectRaw('COUNT(*) as count, MIN(id) as keep_id, GROUP_CONCAT(id) as all_ids')
                ->groupBy('book_id', 'source_name', 'source_id')
                ->having('count', '>', 1)
                ->get();

            $deletedCount = 0;
            foreach ($duplicates as $duplicate) {
                // حذف همه به جز اولین رکورد
                $deleted = self::where('book_id', $duplicate->book_id)
                    ->where('source_name', $duplicate->source_name)
                    ->where('source_id', $duplicate->source_id)
                    ->where('id', '!=', $duplicate->keep_id)
                    ->delete();

                $deletedCount += $deleted;

                Log::debug("🗑️ منابع تکراری حذف شدند", [
                    'book_id' => $duplicate->book_id,
                    'source_name' => $duplicate->source_name,
                    'source_id' => $duplicate->source_id,
                    'deleted_count' => $deleted,
                    'kept_id' => $duplicate->keep_id
                ]);
            }

            if ($deletedCount > 0) {
                Log::info("✅ پاکسازی منابع تکراری تمام شد", [
                    'total_deleted' => $deletedCount,
                    'duplicate_groups' => $duplicates->count()
                ]);

                // پاک کردن کش
                self::clearAllCache();
            }

            return $deletedCount;

        } catch (\Exception $e) {
            Log::error("❌ خطا در پاکسازی منابع تکراری", [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * گزارش تفصیلی منبع - بهبود یافته
     */
    public function getDetailedInfo(): array
    {
        return [
            'id' => $this->id,
            'book_id' => $this->book_id,
            'book_title' => $this->book?->title,
            'source_name' => $this->source_name,
            'source_id' => $this->source_id,
            'discovered_at' => $this->discovered_at,
            'source_url' => self::buildSourceUrl($this->source_name, $this->source_id),
            'is_numeric_id' => is_numeric($this->source_id),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'time_since_discovery' => $this->discovered_at ?
                $this->discovered_at->diffForHumans() : 'نامشخص'
        ];
    }

    /**
     * آمار کلی تمام منابع
     */
    public static function getGlobalStats(): array
    {
        $cacheKey = "global_source_stats";

        return Cache::remember($cacheKey, 600, function () {
            try {
                $stats = self::selectRaw('
                    COUNT(*) as total_records,
                    COUNT(DISTINCT source_name) as total_sources,
                    COUNT(DISTINCT book_id) as total_books,
                    COUNT(DISTINCT CONCAT(book_id, source_name, source_id)) as unique_entries
                ')->first();

                $topSources = self::select('source_name')
                    ->selectRaw('COUNT(*) as records_count, COUNT(DISTINCT book_id) as books_count')
                    ->groupBy('source_name')
                    ->orderBy('records_count', 'desc')
                    ->limit(10)
                    ->get();

                return [
                    'total_records' => $stats->total_records ?? 0,
                    'total_sources' => $stats->total_sources ?? 0,
                    'total_books' => $stats->total_books ?? 0,
                    'unique_entries' => $stats->unique_entries ?? 0,
                    'top_sources' => $topSources->toArray(),
                    'generated_at' => now()->toISOString()
                ];

            } catch (\Exception $e) {
                Log::error("❌ خطا در محاسبه آمار کلی منابع", [
                    'error' => $e->getMessage()
                ]);

                return [
                    'total_records' => 0,
                    'total_sources' => 0,
                    'total_books' => 0,
                    'unique_entries' => 0,
                    'top_sources' => [],
                    'generated_at' => now()->toISOString()
                ];
            }
        });
    }

    /**
     * پاک کردن کش مربوط به منبع خاص
     */
    private static function clearRelatedCache(string $sourceName): void
    {
        $cacheKeys = [
            "last_numeric_id_{$sourceName}",
            "source_stats_{$sourceName}",
            "global_source_stats"
        ];

        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }
    }

    /**
     * پاک کردن تمام کش منابع
     */
    private static function clearAllCache(): void
    {
        // در یک پیاده‌سازی واقعی، باید pattern-based cache clearing استفاده کرد
        Cache::forget("global_source_stats");

        // پاک کردن کش منابع مختلف
        $sources = self::distinct('source_name')->pluck('source_name');
        foreach ($sources as $sourceName) {
            self::clearRelatedCache($sourceName);
        }
    }

    /**
     * بررسی سلامت داده‌ها
     */
    public static function performHealthCheck(): array
    {
        $issues = [];

        try {
            // بررسی منابع بدون کتاب
            $orphanedSources = self::whereDoesntHave('book')->count();
            if ($orphanedSources > 0) {
                $issues[] = "منابع بدون کتاب: {$orphanedSources}";
            }

            // بررسی source_id های نامعتبر
            $invalidSourceIds = self::where('source_id', '')->orWhereNull('source_id')->count();
            if ($invalidSourceIds > 0) {
                $issues[] = "source_id های نامعتبر: {$invalidSourceIds}";
            }

            // بررسی منابع تکراری
            $duplicates = DB::table('book_sources')
                ->select('book_id', 'source_name', 'source_id')
                ->groupBy('book_id', 'source_name', 'source_id')
                ->havingRaw('COUNT(*) > 1')
                ->count();
            if ($duplicates > 0) {
                $issues[] = "گروه‌های تکراری: {$duplicates}";
            }

            Log::info("🏥 بررسی سلامت BookSource تمام شد", [
                'issues_found' => count($issues),
                'issues' => $issues
            ]);

            return [
                'healthy' => empty($issues),
                'issues_count' => count($issues),
                'issues' => $issues,
                'checked_at' => now()->toISOString()
            ];

        } catch (\Exception $e) {
            Log::error("❌ خطا در بررسی سلامت BookSource", [
                'error' => $e->getMessage()
            ]);

            return [
                'healthy' => false,
                'issues_count' => 1,
                'issues' => ['خطا در بررسی سلامت: ' . $e->getMessage()],
                'checked_at' => now()->toISOString()
            ];
        }
    }
}
