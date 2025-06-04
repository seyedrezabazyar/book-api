<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class BookSource extends Model
{
    protected $fillable = [
        'book_hash',
        'source_type',
        'source_id',
        'source_url',
        'source_updated_at',
        'is_active',
        'priority'
    ];

    protected function casts(): array
    {
        return [
            'source_updated_at' => 'datetime',
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    // روابط
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'book_hash', 'content_hash');
    }

    // اسکوپ‌ها
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeBySourceType(Builder $query, string $sourceType): Builder
    {
        return $query->where('source_type', $sourceType);
    }

    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('priority', 1);
    }

    public function scopeOrderByPriority(Builder $query): Builder
    {
        return $query->orderBy('priority')->orderByDesc('created_at');
    }

    // متدهای استاتیک جستجو
    public static function findBySource(string $sourceType, string $sourceId): ?self
    {
        return static::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();
    }

    public static function findBookBySource(string $sourceType, string $sourceId): ?Book
    {
        $source = self::findBySource($sourceType, $sourceId);
        return $source?->book;
    }

    public static function getActiveSourcesForBook(string $bookHash): array
    {
        return static::where('book_hash', $bookHash)
            ->active()
            ->orderByPriority()
            ->get()
            ->map(function ($source) {
                return [
                    'type' => $source->source_type,
                    'id' => $source->source_id,
                    'url' => $source->source_url,
                    'priority' => $source->priority,
                    'updated_at' => $source->source_updated_at?->format('Y-m-d H:i:s'),
                ];
            })
            ->toArray();
    }

    // متدهای کاربردی
    public function isPrimary(): bool
    {
        return $this->priority === 1;
    }

    public function isOutdated(int $days = 30): bool
    {
        if (!$this->source_updated_at) {
            return true;
        }

        return $this->source_updated_at->diffInDays(now()) > $days;
    }

    public function updateLastChecked(): void
    {
        $this->update(['source_updated_at' => now()]);
    }

    public function activate(): void
    {
        $this->update(['is_active' => true]);
    }

    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    public function setPriority(int $priority): void
    {
        $this->update(['priority' => $priority]);
    }

    // متدهای کمکی برای نوع منبع
    public function isLibgen(): bool
    {
        return $this->source_type === 'libgen';
    }

    public function isAnna(): bool
    {
        return $this->source_type === 'anna';
    }

    public function isZlib(): bool
    {
        return $this->source_type === 'zlib';
    }

    public function isGutenberg(): bool
    {
        return $this->source_type === 'gutenberg';
    }

    public function isInternetArchive(): bool
    {
        return $this->source_type === 'ia';
    }

    // متد تولید URL کامل در صورت نیاز
    public function getFullUrl(): string
    {
        if ($this->source_url) {
            return $this->source_url;
        }

        // تولید URL بر اساس نوع منبع و شناسه
        return match($this->source_type) {
            'libgen' => "https://libgen.is/book/index.php?md5={$this->source_id}",
            'anna' => "https://annas-archive.org/md5/{$this->source_id}",
            'zlib' => "https://z-lib.is/book/{$this->source_id}",
            'gutenberg' => "https://www.gutenberg.org/ebooks/{$this->source_id}",
            'ia' => "https://archive.org/details/{$this->source_id}",
            default => ''
        };
    }

    // متد تولید نام منبع قابل خواندن
    public function getSourceDisplayName(): string
    {
        return match($this->source_type) {
            'libgen' => 'Library Genesis',
            'anna' => "Anna's Archive",
            'zlib' => 'Z-Library',
            'gutenberg' => 'Project Gutenberg',
            'ia' => 'Internet Archive',
            default => $this->source_type
        };
    }

    // Observer events
    protected static function boot()
    {
        parent::boot();

        // هنگام ایجاد منبع جدید
        static::creating(function ($source) {
            // تنظیم اولویت پیش‌فرض اگر مشخص نشده
            if (!$source->priority) {
                $maxPriority = static::where('book_hash', $source->book_hash)->max('priority') ?? 0;
                $source->priority = $maxPriority + 1;
            }

            // تنظیم تاریخ بررسی اگر مشخص نشده
            if (!$source->source_updated_at) {
                $source->source_updated_at = now();
            }
        });

        // هنگام حذف منبع
        static::deleting(function ($source) {
            // اگر این منبع اصلی بود، منبع بعدی را اصلی کن
            if ($source->isPrimary()) {
                $nextSource = static::where('book_hash', $source->book_hash)
                    ->where('id', '!=', $source->id)
                    ->active()
                    ->orderBy('priority')
                    ->first();

                if ($nextSource) {
                    $nextSource->setPriority(1);
                }
            }
        });
    }
}
