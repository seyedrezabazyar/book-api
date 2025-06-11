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
}
