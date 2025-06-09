<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;

/**
 * مدل کتاب‌ها - بهبود یافته
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

        static::created(function ($book) {
            Log::info("📚 کتاب ایجاد شد", [
                'id' => $book->id,
                'title' => $book->title,
                'content_hash' => $book->content_hash,
            ]);
        });

        static::updated(function ($book) {
            if ($book->isDirty()) {
                Log::info("📝 کتاب بروزرسانی شد", [
                    'id' => $book->id,
                    'title' => $book->title,
                    'changed_fields' => array_keys($book->getDirty())
                ]);
            }
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

    public function bookHash(): HasOne
    {
        return $this->hasOne(BookHash::class);
    }

    // برای سازگاری با کد قدیمی
    public function hashes(): HasOne
    {
        return $this->bookHash();
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

    /**
     * متدهای مربوط به هش
     */

    /**
     * دریافت یا ایجاد هش کتاب
     */
    public function getOrCreateBookHash(): BookHash
    {
        return $this->bookHash ?? BookHash::createOrUpdateForBook($this);
    }

    /**
     * بروزرسانی هش‌های کتاب
     */
    public function updateHashes(array $hashData): bool
    {
        $bookHash = $this->getOrCreateBookHash();
        return $bookHash->updateMissingHashes($hashData);
    }

    /**
     * دریافت تمام هش‌های موجود
     */
    public function getAllHashes(): array
    {
        $bookHash = $this->bookHash;
        return $bookHash ? $bookHash->getAllHashes() : ['md5' => $this->content_hash];
    }

    /**
     * دریافت هش خاص
     */
    public function getHash(string $hashType = 'md5'): ?string
    {
        if ($hashType === 'md5') {
            return $this->content_hash;
        }

        $bookHash = $this->bookHash;
        return $bookHash ? $bookHash->getHash($hashType) : null;
    }

    /**
     * بررسی اینکه آیا هش خاصی موجود است
     */
    public function hasHash(string $hashType): bool
    {
        if ($hashType === 'md5') {
            return !empty($this->content_hash);
        }

        $bookHash = $this->bookHash;
        return $bookHash ? $bookHash->hasHash($hashType) : false;
    }

    /**
     * متدهای کمکی برای ادغام هوشمند
     */

    /**
     * ادغام ISBN جدید با موجود
     */
    public function mergeIsbn(string $newIsbn): bool
    {
        if (empty($this->isbn)) {
            $this->isbn = $newIsbn;
            return true;
        }

        // نرمال‌سازی ISBN ها
        $existing = preg_replace('/[^0-9X-]/', '', strtoupper($this->isbn));
        $new = preg_replace('/[^0-9X-]/', '', strtoupper($newIsbn));

        if ($existing === $new) {
            return false; // بدون تغییر
        }

        // تبدیل به آرایه و حذف تکراری‌ها
        $existingIsbns = array_filter(explode(',', $this->isbn));
        $newIsbns = array_filter(explode(',', $newIsbn));

        $allIsbns = array_unique(array_merge($existingIsbns, $newIsbns));
        $mergedIsbn = implode(', ', $allIsbns);

        if ($mergedIsbn !== $this->isbn) {
            $this->isbn = $mergedIsbn;
            return true;
        }

        return false;
    }

    /**
     * بهبود توضیحات کتاب
     */
    public function improveDescription(string $newDescription): bool
    {
        if (empty($this->description)) {
            $this->description = $newDescription;
            return true;
        }

        $existingLength = strlen(trim($this->description));
        $newLength = strlen(trim($newDescription));

        // اگر توضیحات جدید 30% بیشتر باشد، از آن استفاده کن
        if ($newLength > $existingLength * 1.3) {
            $this->description = $newDescription;
            return true;
        }

        // اگر توضیحات جدید کمی بیشتر باشد، بررسی تشابه
        if ($newLength > $existingLength * 1.1 && $newLength <= $existingLength * 1.3) {
            similar_text($this->description, $newDescription, $percent);
            if ($percent < 80) { // اگر کمتر از 80% شباهت داشت، ادغام کن
                $this->description = $this->description . "\n\n---\n\n" . $newDescription;
                return true;
            }
        }

        return false;
    }

    /**
     * بروزرسانی فیلدهای خالی
     */
    public function fillEmptyFields(array $newData): array
    {
        $updated = [];
        $fillableFields = ['publication_year', 'pages_count', 'file_size', 'language', 'format'];

        foreach ($fillableFields as $field) {
            if (empty($this->$field) && !empty($newData[$field])) {
                $this->$field = $newData[$field];
                $updated[] = $field;
            }
        }

        return $updated;
    }

    /**
     * ادغام نویسندگان جدید
     */
    public function mergeAuthors(string $newAuthorsString): array
    {
        $newAuthorNames = array_map('trim', explode(',', $newAuthorsString));
        $existingAuthorNames = $this->authors()->pluck('name')->toArray();

        $addedAuthors = [];

        foreach ($newAuthorNames as $authorName) {
            if (empty($authorName) || in_array($authorName, $existingAuthorNames)) {
                continue;
            }

            // پیدا کردن یا ایجاد نویسنده
            $author = Author::firstOrCreate(
                ['name' => $authorName],
                [
                    'slug' => \Illuminate\Support\Str::slug($authorName . '_' . time()),
                    'is_active' => true,
                    'books_count' => 0
                ]
            );

            // بررسی اینکه آیا رابطه قبلاً وجود دارد
            $exists = \Illuminate\Support\Facades\DB::table('book_author')
                ->where('book_id', $this->id)
                ->where('author_id', $author->id)
                ->exists();

            if (!$exists) {
                // اضافه کردن رابطه
                \Illuminate\Support\Facades\DB::table('book_author')->insert([
                    'book_id' => $this->id,
                    'author_id' => $author->id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                $addedAuthors[] = $authorName;

                Log::info("✅ نویسنده جدید '{$authorName}' به کتاب '{$this->title}' اضافه شد", [
                    'book_id' => $this->id,
                    'author_id' => $author->id,
                    'author_name' => $authorName
                ]);
            }
        }

        return $addedAuthors;
    }

    /**
     * دریافت منابع کتاب
     */
    public function getBookSources(): array
    {
        return $this->sources()->orderBy('discovered_at', 'desc')->get()->map(function ($source) {
            return [
                'source_name' => $source->source_name,
                'source_id' => $source->source_id,
                'discovered_at' => $source->discovered_at,
            ];
        })->toArray();
    }

    /**
     * اضافه کردن منبع جدید
     */
    public function addSource(string $sourceName, string $sourceId): BookSource
    {
        return BookSource::recordBookSource($this->id, $sourceName, $sourceId);
    }

    /**
     * آپدیت هوشمند کتاب با داده‌های جدید
     */
    public function smartUpdate(array $newData, array $options = []): array
    {
        $changes = [];
        $needsUpdate = false;

        // فعال/غیرفعال کردن انواع بروزرسانی
        $fillMissingFields = $options['fill_missing_fields'] ?? true;
        $updateDescriptions = $options['update_descriptions'] ?? true;
        $mergeIsbns = $options['merge_isbns'] ?? true;
        $mergeAuthors = $options['merge_authors'] ?? true;

        // تکمیل فیلدهای خالی
        if ($fillMissingFields) {
            $filledFields = $this->fillEmptyFields($newData);
            if (!empty($filledFields)) {
                $changes['filled_fields'] = $filledFields;
                $needsUpdate = true;
            }
        }

        // ادغام ISBN
        if ($mergeIsbns && !empty($newData['isbn'])) {
            if ($this->mergeIsbn($newData['isbn'])) {
                $changes['merged_isbn'] = true;
                $needsUpdate = true;
            }
        }

        // بهبود توضیحات
        if ($updateDescriptions && !empty($newData['description'])) {
            if ($this->improveDescription($newData['description'])) {
                $changes['improved_description'] = true;
                $needsUpdate = true;
            }
        }

        // ادغام نویسندگان
        if ($mergeAuthors && !empty($newData['author'])) {
            $addedAuthors = $this->mergeAuthors($newData['author']);
            if (!empty($addedAuthors)) {
                $changes['added_authors'] = $addedAuthors;
            }
        }

        // بروزرسانی هش‌ها
        $hashData = array_filter([
            'sha1' => $newData['sha1'] ?? null,
            'sha256' => $newData['sha256'] ?? null,
            'crc32' => $newData['crc32'] ?? null,
            'ed2k_hash' => $newData['ed2k'] ?? null,
            'btih' => $newData['btih'] ?? null,
            'magnet_link' => $newData['magnet'] ?? null,
        ]);

        if (!empty($hashData)) {
            if ($this->updateHashes($hashData)) {
                $changes['updated_hashes'] = array_keys($hashData);
            }
        }

        // ذخیره تغییرات
        if ($needsUpdate) {
            $this->save();
        }

        return [
            'updated' => $needsUpdate,
            'changes' => $changes,
            'action' => $needsUpdate ? 'updated' : 'no_changes'
        ];
    }

    /**
     * دریافت آمار کامل کتاب
     */
    public function getCompleteStats(): array
    {
        return [
            'basic_info' => [
                'id' => $this->id,
                'title' => $this->title,
                'isbn' => $this->isbn,
                'publication_year' => $this->publication_year,
                'pages_count' => $this->pages_count,
                'language' => $this->language,
                'format' => $this->format,
                'file_size' => $this->file_size,
                'content_hash' => $this->content_hash,
            ],
            'relations' => [
                'authors_count' => $this->authors()->count(),
                'sources_count' => $this->sources()->count(),
                'images_count' => $this->images()->count(),
                'category' => $this->category?->name,
                'publisher' => $this->publisher?->name,
            ],
            'hashes' => $this->getAllHashes(),
            'sources' => $this->getBookSources(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * جستجوی کتاب بر اساس هش
     */
    public static function findByHash(string $hash, string $hashType = 'md5'): ?self
    {
        if ($hashType === 'md5') {
            return self::where('content_hash', $hash)->first();
        }

        return BookHash::findBookByHash($hash, $hashType);
    }

    /**
     * بررسی تکراری بودن کتاب
     */
    public static function isDuplicate(array $bookData): ?self
    {
        // محاسبه هش
        $hashData = [
            'title' => $bookData['title'] ?? '',
            'author' => $bookData['author'] ?? '',
            'isbn' => $bookData['isbn'] ?? '',
            'publication_year' => $bookData['publication_year'] ?? '',
            'pages_count' => $bookData['pages_count'] ?? ''
        ];

        $normalizedData = array_map(function ($value) {
            return strtolower(trim(preg_replace('/\s+/', ' ', $value)));
        }, $hashData);

        $contentHash = md5(json_encode($normalizedData, JSON_UNESCAPED_UNICODE));

        return self::where('content_hash', $contentHash)->first();
    }

    /**
     * ایجاد کتاب جدید با تمام روابط
     */
    public static function createWithRelations(array $bookData, array $options = []): self
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($bookData, $options) {
            // محاسبه هش
            $contentHash = self::calculateContentHash($bookData);

            // ایجاد category و publisher
            $category = Category::firstOrCreate(
                ['name' => $bookData['category'] ?? 'عمومی'],
                [
                    'slug' => \Illuminate\Support\Str::slug(($bookData['category'] ?? 'عمومی') . '_' . time()),
                    'is_active' => true,
                    'books_count' => 0
                ]
            );

            $publisher = null;
            if (!empty($bookData['publisher'])) {
                $publisher = Publisher::firstOrCreate(
                    ['name' => $bookData['publisher']],
                    [
                        'slug' => \Illuminate\Support\Str::slug($bookData['publisher'] . '_' . time()),
                        'is_active' => true,
                        'books_count' => 0
                    ]
                );
            }

            // ایجاد کتاب
            $book = self::create([
                'title' => $bookData['title'],
                'description' => $bookData['description'] ?? null,
                'excerpt' => \Illuminate\Support\Str::limit($bookData['description'] ?? $bookData['title'], 200),
                'slug' => \Illuminate\Support\Str::slug($bookData['title'] . '_' . time()),
                'isbn' => $bookData['isbn'] ?? null,
                'publication_year' => $bookData['publication_year'] ?? null,
                'pages_count' => $bookData['pages_count'] ?? null,
                'language' => $bookData['language'] ?? 'fa',
                'format' => $bookData['format'] ?? 'pdf',
                'file_size' => $bookData['file_size'] ?? null,
                'content_hash' => $contentHash,
                'category_id' => $category->id,
                'publisher_id' => $publisher?->id,
                'downloads_count' => 0,
                'status' => 'active'
            ]);

            // ایجاد هش‌ها
            $hashData = array_filter([
                'sha1' => $bookData['sha1'] ?? null,
                'sha256' => $bookData['sha256'] ?? null,
                'crc32' => $bookData['crc32'] ?? null,
                'ed2k_hash' => $bookData['ed2k'] ?? null,
                'btih' => $bookData['btih'] ?? null,
                'magnet_link' => $bookData['magnet'] ?? null,
            ]);

            BookHash::createOrUpdateForBook($book, $hashData);

            // اضافه کردن نویسندگان
            if (!empty($bookData['author'])) {
                $book->mergeAuthors($bookData['author']);
            }

            // اضافه کردن تصاویر
            if (!empty($bookData['image_url'])) {
                BookImage::updateOrCreate(
                    ['book_id' => $book->id],
                    ['image_url' => $bookData['image_url']]
                );
            }

            // اضافه کردن منبع
            if (!empty($options['source_name']) && !empty($options['source_id'])) {
                $book->addSource($options['source_name'], $options['source_id']);
            }

            Log::info("✨ کتاب جدید با تمام روابط ایجاد شد", [
                'book_id' => $book->id,
                'title' => $book->title,
                'content_hash' => $contentHash,
                'authors_count' => $book->authors()->count(),
                'has_hashes' => !empty($hashData),
                'has_images' => !empty($bookData['image_url']),
                'source' => $options['source_name'] ?? null,
            ]);

            return $book;
        });
    }

    /**
     * محاسبه هش محتوا
     */
    public static function calculateContentHash(array $data): string
    {
        $hashData = [
            'title' => $data['title'] ?? '',
            'author' => $data['author'] ?? '',
            'isbn' => $data['isbn'] ?? '',
            'publication_year' => $data['publication_year'] ?? '',
            'pages_count' => $data['pages_count'] ?? ''
        ];

        $normalizedData = array_map(function ($value) {
            return strtolower(trim(preg_replace('/\s+/', ' ', $value)));
        }, $hashData);

        return md5(json_encode($normalizedData, JSON_UNESCAPED_UNICODE));
    }

    /**
     * آمار عمومی کتاب‌ها
     */
    public static function getGeneralStats(): array
    {
        return [
            'total_books' => self::count(),
            'active_books' => self::where('status', 'active')->count(),
            'books_with_isbn' => self::whereNotNull('isbn')->count(),
            'books_with_description' => self::whereNotNull('description')->count(),
            'books_with_images' => self::whereHas('images')->count(),
            'books_with_hashes' => self::whereHas('bookHash')->count(),
            'languages' => self::select('language')
                ->groupBy('language')
                ->selectRaw('language, COUNT(*) as count')
                ->orderBy('count', 'desc')
                ->get()
                ->pluck('count', 'language')
                ->toArray(),
            'formats' => self::select('format')
                ->groupBy('format')
                ->selectRaw('format, COUNT(*) as count')
                ->orderBy('count', 'desc')
                ->get()
                ->pluck('count', 'format')
                ->toArray(),
        ];
    }
}
