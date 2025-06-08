<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class BookSource extends Model
{
    protected $table = 'book_sources';

    protected $fillable = [
        'book_id',
        'source_type',
        'source_id',
        'source_url',
        'source_updated_at',
        'is_active',
        'priority'
    ];

    protected $casts = [
        'source_updated_at' => 'datetime',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * اسکوپ‌ها
     */
    public function scopeActive($query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeBySourceType($query, $sourceType): Builder
    {
        return $query->where('source_type', $sourceType);
    }

    public function scopeBySourceName($query, $sourceName): Builder
    {
        return $query->whereHas('config', function ($q) use ($sourceName) {
            $q->where('source_name', $sourceName);
        });
    }

    /**
     * یافتن آخرین source_id برای یک منبع خاص
     */
    public static function getLastSourceIdForType(string $sourceType, string $sourceName = null): int
    {
        $query = static::where('source_type', $sourceType)
            ->whereNotNull('source_id')
            ->where('source_id', '!=', '');

        // اگر source_name مشخص شده، فیلتر کن
        if ($sourceName) {
            // فیلتر بر اساس URL pattern یا سایر روش‌ها
            $query->where(function ($q) use ($sourceName) {
                $q->where('source_url', 'like', "%{$sourceName}%")
                    ->orWhereHas('book', function ($bookQuery) use ($sourceName) {
                        // یا هر فیلتر دیگری که نیاز داشته باشیم
                    });
            });
        }

        $lastSource = $query->orderByRaw('CAST(source_id AS UNSIGNED) DESC')->first();

        $lastId = $lastSource ? (int) $lastSource->source_id : 0;

        Log::info("📊 آخرین source_id دریافت شد", [
            'source_type' => $sourceType,
            'source_name' => $sourceName,
            'last_id' => $lastId
        ]);

        return $lastId;
    }

    /**
     * بررسی وجود source_id برای یک منبع خاص
     */
    public static function sourceIdExists(string $sourceType, string $sourceId, string $sourceName = null): bool
    {
        $query = static::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereHas('book', function ($q) {
                $q->where('status', 'active'); // فقط کتاب‌های فعال
            });

        if ($sourceName) {
            $query->where('source_url', 'like', "%{$sourceName}%");
        }

        return $query->exists();
    }

    /**
     * دریافت لیست source_id های موجود برای یک بازه
     */
    public static function getExistingSourceIds(string $sourceType, int $startId, int $endId, string $sourceName = null): array
    {
        $query = static::where('source_type', $sourceType)
            ->whereRaw('CAST(source_id AS UNSIGNED) BETWEEN ? AND ?', [$startId, $endId])
            ->whereHas('book', function ($q) {
                $q->where('status', 'active');
            });

        if ($sourceName) {
            $query->where('source_url', 'like', "%{$sourceName}%");
        }

        return $query->pluck('source_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * یافتن source_id های مفقود در یک بازه
     */
    public static function getMissingSourceIds(string $sourceType, int $startId, int $endId, string $sourceName = null): array
    {
        $existingIds = static::getExistingSourceIds($sourceType, $startId, $endId, $sourceName);
        $allIds = range($startId, $endId);

        $missingIds = array_diff($allIds, $existingIds);

        Log::info("🔍 source_id های مفقود محاسبه شد", [
            'source_type' => $sourceType,
            'source_name' => $sourceName,
            'range' => "{$startId}-{$endId}",
            'existing_count' => count($existingIds),
            'missing_count' => count($missingIds),
            'sample_missing' => array_slice($missingIds, 0, 10)
        ]);

        return array_values($missingIds);
    }

    /**
     * آمار کلی یک منبع
     */
    public static function getSourceStats(string $sourceType, string $sourceName = null): array
    {
        $query = static::where('source_type', $sourceType);

        if ($sourceName) {
            $query->where('source_url', 'like', "%{$sourceName}%");
        }

        $totalSources = $query->count();
        $activeSources = $query->where('is_active', true)->count();
        $lastId = static::getLastSourceIdForType($sourceType, $sourceName);
        $firstId = $query->orderByRaw('CAST(source_id AS UNSIGNED) ASC')->value('source_id');

        return [
            'total_sources' => $totalSources,
            'active_sources' => $activeSources,
            'first_source_id' => $firstId ? (int) $firstId : 0,
            'last_source_id' => $lastId,
            'id_range' => $firstId ? (int) $firstId . '-' . $lastId : '0-0',
            'coverage_percentage' => $lastId > 0 ? round(($totalSources / $lastId) * 100, 2) : 0
        ];
    }

    /**
     * ایجاد منبع جدید با validation
     */
    public static function createSource(int $bookId, string $sourceType, string $sourceId, string $sourceUrl, int $priority = 1): static
    {
        // بررسی تکراری نبودن
        $existing = static::where('book_id', $bookId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();

        if ($existing) {
            // بروزرسانی منبع موجود
            $existing->update([
                'source_url' => $sourceUrl,
                'source_updated_at' => now(),
                'is_active' => true,
                'priority' => $priority
            ]);

            Log::info("🔄 منبع موجود بروزرسانی شد", [
                'book_id' => $bookId,
                'source_type' => $sourceType,
                'source_id' => $sourceId
            ]);

            return $existing;
        }

        // ایجاد منبع جدید
        $source = static::create([
            'book_id' => $bookId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_url' => $sourceUrl,
            'source_updated_at' => now(),
            'is_active' => true,
            'priority' => $priority
        ]);

        Log::info("✨ منبع جدید ایجاد شد", [
            'id' => $source->id,
            'book_id' => $bookId,
            'source_type' => $sourceType,
            'source_id' => $sourceId
        ]);

        return $source;
    }

    /**
     * حذف منابع غیرفعال
     */
    public static function cleanupInactiveSources(int $daysOld = 30): int
    {
        $cutoffDate = now()->subDays($daysOld);

        $deletedCount = static::where('is_active', false)
            ->where('updated_at', '<', $cutoffDate)
            ->delete();

        if ($deletedCount > 0) {
            Log::info("🧹 منابع غیرفعال پاک شدند", [
                'deleted_count' => $deletedCount,
                'cutoff_date' => $cutoffDate
            ]);
        }

        return $deletedCount;
    }

    /**
     * تشخیص منابع تکراری
     */
    public static function findDuplicateSources(): array
    {
        $duplicates = static::select('source_type', 'source_id')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('source_type', 'source_id')
            ->having('count', '>', 1)
            ->get();

        $duplicateList = [];
        foreach ($duplicates as $duplicate) {
            $sources = static::where('source_type', $duplicate->source_type)
                ->where('source_id', $duplicate->source_id)
                ->with('book:id,title')
                ->get();

            $duplicateList[] = [
                'source_type' => $duplicate->source_type,
                'source_id' => $duplicate->source_id,
                'count' => $duplicate->count,
                'sources' => $sources
            ];
        }

        if (count($duplicateList) > 0) {
            Log::warning("⚠️ منابع تکراری یافت شد", [
                'duplicate_count' => count($duplicateList)
            ]);
        }

        return $duplicateList;
    }

    /**
     * بازسازی ایندکس‌ها برای بهینه‌سازی
     */
    public static function rebuildIndexes(): void
    {
        try {
            // اجرای کوئری‌های بهینه‌سازی
            \DB::statement('ANALYZE TABLE book_sources');

            Log::info("🔧 ایندکس‌های book_sources بازسازی شد");
        } catch (\Exception $e) {
            Log::error("❌ خطا در بازسازی ایندکس‌ها", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * گزارش تفصیلی منبع
     */
    public function getDetailedInfo(): array
    {
        return [
            'id' => $this->id,
            'book_id' => $this->book_id,
            'book_title' => $this->book?->title,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'source_url' => $this->source_url,
            'is_active' => $this->is_active,
            'priority' => $this->priority,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'source_updated_at' => $this->source_updated_at,
        ];
    }

    /**
     * بررسی سلامت منبع
     */
    public function checkHealth(): array
    {
        $issues = [];

        // بررسی وجود کتاب
        if (!$this->book) {
            $issues[] = 'کتاب مرتبط یافت نشد';
        }

        // بررسی معتبر بودن source_id
        if (empty($this->source_id) || !is_numeric($this->source_id)) {
            $issues[] = 'source_id نامعتبر است';
        }

        // بررسی معتبر بودن URL
        if (!empty($this->source_url) && !filter_var($this->source_url, FILTER_VALIDATE_URL)) {
            $issues[] = 'URL منبع نامعتبر است';
        }

        // بررسی بروزرسانی اخیر
        if ($this->source_updated_at && $this->source_updated_at->lt(now()->subMonths(6))) {
            $issues[] = 'منبع مدت زیادی بروزرسانی نشده';
        }

        return [
            'is_healthy' => empty($issues),
            'issues' => $issues,
            'checked_at' => now()
        ];
    }
}
