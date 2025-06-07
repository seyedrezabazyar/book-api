<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

/**
 * مدل کتاب‌ها
 */
class Book extends Model
{
    use HasFactory;

    protected $table = 'books';

    protected $fillable = [
        'title',
        'description',
        'excerpt',
        'slug',
        'isbn',
        'publication_year',
        'pages_count',
        'language',
        'format',
        'file_size',
        'content_hash',
        'category_id',
        'publisher_id',
        'downloads_count',
        'status'
    ];

    protected $casts = [
        'publication_year' => 'integer',
        'pages_count' => 'integer',
        'file_size' => 'integer',
        'downloads_count' => 'integer'
    ];

    /**
     * Debug: ثبت stack trace هنگام ایجاد کتاب
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($book) {
            // ثبت stack trace برای دیدن از کجا کتاب ایجاد می‌شود
            $stackTrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);

            $caller = "Unknown";
            foreach ($stackTrace as $trace) {
                if (isset($trace['file']) && !str_contains($trace['file'], 'vendor/')) {
                    $file = basename($trace['file']);
                    $line = $trace['line'] ?? '?';
                    $function = $trace['function'] ?? '?';
                    $caller = "{$file}:{$line} ({$function})";
                    break;
                }
            }

            Log::warning("🔍 کتاب در حال ایجاد", [
                'title' => $book->title,
                'caller' => $caller,
                'stack_trace' => array_slice($stackTrace, 0, 5)
            ]);
        });

        static::created(function ($book) {
            Log::info("📚 کتاب ایجاد شد", [
                'id' => $book->id,
                'title' => $book->title,
                'created_at' => $book->created_at
            ]);
        });
    }

    /**
     * روابط
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class, 'book_author');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(BookSource::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(BookImage::class);
    }

    public function hashes(): HasMany
    {
        return $this->hasMany(BookHash::class);
    }

    /**
     * اسکوپ‌ها
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByLanguage($query, $language)
    {
        return $query->where('language', $language);
    }

    public function scopeByFormat($query, $format)
    {
        return $query->where('format', $format);
    }

    public function scopeSearch($query, $search)
    {
        if (!empty($search)) {
            return $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }
        return $query;
    }
}
