<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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

    /**
     * ثبت یا بروزرسانی منبع کتاب
     * این متد اصلی برای ثبت منابع است
     */
    public static function recordBookSource(int $bookId, string $sourceName, string $sourceId): self
    {
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

        Log::info("📝 منبع کتاب ثبت شد", [
            'book_id' => $bookId,
            'source_name' => $sourceName,
            'source_id' => $sourceId,
            'was_existing' => !$source->wasRecentlyCreated
        ]);

        return $source;
    }

    /**
     * بررسی وجود منبع خاص
     */
    public static function sourceExists(string $sourceName, string $sourceId): bool
    {
        return self::where('source_name', $sourceName)
            ->where('source_id', $sourceId)
            ->exists();
    }

    /**
     * یافتن کتاب بر اساس منبع و ID
     */
    public static function findBookBySource(string $sourceName, string $sourceId): ?Book
    {
        $source = self::where('source_name', $sourceName)
            ->where('source_id', $sourceId)
            ->with('book')
            ->first();

        return $source?->book;
    }

    /**
     * دریافت آخرین source_id عددی برای منبع خاص
     */
    public static function getLastNumericSourceId(string $sourceName): int
    {
        $result = self::where('source_name', $sourceName)
            ->whereRaw('source_id REGEXP "^[0-9]+$"') // فقط source_id های عددی
            ->orderByRaw('CAST(source_id AS UNSIGNED) DESC')
            ->value('source_id');

        return $result ? (int) $result : 0;
    }

    /**
     * آمار منبع خاص - اصلاح شده
     */
    public static function getSourceStats(string $sourceName): array
    {
        $stats = self::where('source_name', $sourceName)
            ->selectRaw('
                COUNT(*) as total_records,
                COUNT(DISTINCT book_id) as unique_books,
                MIN(CASE WHEN source_id REGEXP "^[0-9]+$" THEN CAST(source_id AS UNSIGNED) END) as first_id,
                MAX(CASE WHEN source_id REGEXP "^[0-9]+$" THEN CAST(source_id AS UNSIGNED) END) as last_id
            ')
            ->first();

        return [
            'source_name' => $sourceName,
            'total_records' => $stats->total_records ?? 0,
            'unique_books' => $stats->unique_books ?? 0,
            'first_source_id' => $stats->first_id ?? 0,
            'last_source_id' => $stats->last_id ?? 0,
            'id_range' => ($stats->first_id && $stats->last_id) ?
                "{$stats->first_id}-{$stats->last_id}" : '0-0'
        ];
    }

    /**
     * یافتن source_id های مفقود در بازه
     */
    public static function findMissingSourceIds(string $sourceName, int $startId, int $endId, int $limit = 100): array
    {
        // دریافت ID های موجود
        $existingIds = self::where('source_name', $sourceName)
            ->whereBetween(DB::raw('CAST(source_id AS UNSIGNED)'), [$startId, $endId])
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

        Log::info("🔍 جستجوی ID های مفقود", [
            'source_name' => $sourceName,
            'range' => "{$startId}-{$endId}",
            'existing_count' => count($existingIds),
            'missing_count' => count($missingIds),
            'sample_missing' => array_slice($missingIds, 0, 10)
        ]);

        return $missingIds;
    }

    /**
     * دریافت منابع یک کتاب
     */
    public static function getBookSources(int $bookId): array
    {
        return self::where('book_id', $bookId)
            ->orderBy('discovered_at', 'desc')
            ->get()
            ->map(function ($source) {
                return [
                    'source_name' => $source->source_name,
                    'source_id' => $source->source_id,
                    'discovered_at' => $source->discovered_at,
                    'url' => self::buildSourceUrl($source->source_name, $source->source_id)
                ];
            })
            ->toArray();
    }

    /**
     * ساخت URL منبع (اختیاری)
     */
    private static function buildSourceUrl(string $sourceName, string $sourceId): ?string
    {
        $urlTemplates = [
            'libgen_rs' => 'http://libgen.rs/book/index.php?md5={id}',
            'zlib' => 'https://z-lib.org/book/{id}',
            'anna_archive' => 'https://annas-archive.org/md5/{id}',
            // افزودن منابع دیگر در صورت نیاز
        ];

        return isset($urlTemplates[$sourceName]) ?
            str_replace('{id}', $sourceId, $urlTemplates[$sourceName]) : null;
    }

    /**
     * پاکسازی منابع تکراری (در صورت نیاز)
     */
    public static function cleanupDuplicates(): int
    {
        // یافتن منابع تکراری
        $duplicates = DB::table('book_sources')
            ->select('book_id', 'source_name', 'source_id')
            ->selectRaw('COUNT(*) as count, MIN(id) as keep_id')
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
        }

        if ($deletedCount > 0) {
            Log::info("🧹 منابع تکراری پاک شدند", ['deleted_count' => $deletedCount]);
        }

        return $deletedCount;
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
            'source_name' => $this->source_name,
            'source_id' => $this->source_id,
            'discovered_at' => $this->discovered_at,
            'source_url' => self::buildSourceUrl($this->source_name, $this->source_id),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
