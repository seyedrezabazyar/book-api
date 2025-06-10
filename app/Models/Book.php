<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
        'category_id',
        'publisher_id',
        'downloads_count',
        'status',
    ];

    protected $casts = [
        'publication_year' => 'integer',
        'pages_count' => 'integer',
        'file_size' => 'integer',
        'downloads_count' => 'integer',
    ];

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
        return $this->belongsToMany(Author::class, 'book_author')->withTimestamps();
    }

    public function sources(): HasMany
    {
        return $this->hasMany(BookSource::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(BookImage::class);
    }

    public function hashes(): HasOne
    {
        return $this->hasOne(BookHash::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeSearch($query, ?string $search)
    {
        return $search ? $query->where(function ($q) use ($search) {
            $q->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        }) : $query;
    }

    public function getMainHash(): ?string
    {
        return $this->hashes?->md5;
    }

    public function getAllHashes(): array
    {
        return $this->hashes ? $this->hashes->getAllHashes() : [];
    }

    public static function findByMd5(string $md5): ?self
    {
        return BookHash::where('md5', $md5)->first()?->book;
    }

    public static function createWithDetails(array $bookData, array $hashData = [], ?string $sourceName = null, ?string $sourceId = null): self
    {
        return DB::transaction(function () use ($bookData, $hashData, $sourceName, $sourceId) {

            Log::info('شروع ایجاد کتاب با جزئیات', [
                'title' => $bookData['title'] ?? 'نامشخص',
                'author' => $bookData['author'] ?? 'نامشخص',
                'has_hash_data' => !empty($hashData),
                'source_name' => $sourceName,
                'source_id' => $sourceId
            ]);

            // ایجاد یا پیدا کردن دسته‌بندی
            $category = Category::firstOrCreate(
                ['name' => $bookData['category'] ?? 'عمومی'],
                [
                    'slug' => Str::slug(($bookData['category'] ?? 'عمومی') . '_' . time()),
                    'is_active' => true,
                    'books_count' => 0,
                ]
            );

            // ایجاد یا پیدا کردن ناشر
            $publisher = null;
            if (!empty($bookData['publisher'])) {
                $publisher = Publisher::firstOrCreate(
                    ['name' => $bookData['publisher']],
                    [
                        'slug' => Str::slug($bookData['publisher'] . '_' . time()),
                        'is_active' => true,
                        'books_count' => 0,
                    ]
                );
            }

            // ایجاد کتاب
            $book = self::create([
                'title' => $bookData['title'],
                'description' => $bookData['description'] ?? null,
                'excerpt' => Str::limit($bookData['description'] ?? $bookData['title'], 200),
                'slug' => Str::slug($bookData['title'] . '_' . time() . '_' . rand(1000, 9999)),
                'isbn' => $bookData['isbn'] ?? null,
                'publication_year' => $bookData['publication_year'] ?? null,
                'pages_count' => $bookData['pages_count'] ?? null,
                'language' => $bookData['language'] ?? 'fa',
                'format' => $bookData['format'] ?? 'pdf',
                'file_size' => $bookData['file_size'] ?? null,
                'category_id' => $category->id,
                'publisher_id' => $publisher?->id,
                'downloads_count' => 0,
                'status' => 'active',
            ]);

            Log::info('کتاب اصلی ایجاد شد', [
                'book_id' => $book->id,
                'title' => $book->title
            ]);

            // ایجاد هش‌ها
            if (!empty($hashData['md5'])) {
                try {
                    BookHash::create([
                        'book_id' => $book->id,
                        'md5' => $hashData['md5'],
                        'sha1' => $hashData['sha1'] ?? null,
                        'sha256' => $hashData['sha256'] ?? null,
                        'crc32' => $hashData['crc32'] ?? null,
                        'ed2k_hash' => $hashData['ed2k'] ?? null,
                        'btih' => $hashData['btih'] ?? null,
                        'magnet_link' => $hashData['magnet'] ?? null,
                    ]);

                    Log::info('هش‌های کتاب ایجاد شد', [
                        'book_id' => $book->id,
                        'hash_types' => array_keys(array_filter($hashData))
                    ]);
                } catch (\Exception $e) {
                    Log::error('خطا در ایجاد هش‌های کتاب', [
                        'book_id' => $book->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // اضافه کردن نویسندگان - اصلاح شده
            if (!empty($bookData['author'])) {
                Log::info('شروع اضافه کردن نویسندگان', [
                    'book_id' => $book->id,
                    'authors_string' => $bookData['author']
                ]);

                try {
                    $addedAuthors = $book->addAuthorsWithTimestamps($bookData['author']);

                    Log::info('نتیجه اضافه کردن نویسندگان', [
                        'book_id' => $book->id,
                        'added_authors' => $addedAuthors,
                        'added_count' => count($addedAuthors)
                    ]);

                    // بروزرسانی تعداد کتاب‌های دسته‌بندی
                    $category->increment('books_count');
                    if ($publisher) {
                        $publisher->increment('books_count');
                    }

                } catch (\Exception $e) {
                    Log::error('خطا در اضافه کردن نویسندگان', [
                        'book_id' => $book->id,
                        'authors_string' => $bookData['author'],
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            } else {
                Log::warning('هیچ نویسنده‌ای برای اضافه کردن یافت نشد', [
                    'book_id' => $book->id,
                    'book_data_keys' => array_keys($bookData)
                ]);
            }

            // اضافه کردن تصویر
            if (!empty($bookData['image_url'])) {
                try {
                    BookImage::create([
                        'book_id' => $book->id,
                        'image_url' => $bookData['image_url'],
                    ]);

                    Log::info('تصویر کتاب اضافه شد', [
                        'book_id' => $book->id,
                        'image_url' => $bookData['image_url']
                    ]);
                } catch (\Exception $e) {
                    Log::error('خطا در اضافه کردن تصویر', [
                        'book_id' => $book->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // ثبت منبع
            if ($sourceName && $sourceId) {
                try {
                    BookSource::recordBookSource($book->id, $sourceName, $sourceId);

                    Log::info('منبع کتاب ثبت شد', [
                        'book_id' => $book->id,
                        'source_name' => $sourceName,
                        'source_id' => $sourceId
                    ]);
                } catch (\Exception $e) {
                    Log::error('خطا در ثبت منبع کتاب', [
                        'book_id' => $book->id,
                        'source_name' => $sourceName,
                        'source_id' => $sourceId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // بررسی نهایی نویسندگان
            $finalAuthorsCount = $book->authors()->count();
            Log::info('✨ کتاب با جزئیات کامل ایجاد شد', [
                'book_id' => $book->id,
                'title' => $book->title,
                'md5' => $hashData['md5'] ?? null,
                'source' => $sourceName,
                'final_authors_count' => $finalAuthorsCount,
                'category' => $category->name,
                'publisher' => $publisher?->name
            ]);

            return $book;
        });
    }

    public function addAuthorsWithTimestamps(string $authorsString): array
    {
        if (empty(trim($authorsString))) {
            Log::info('رشته نویسندگان خالی است', ['book_id' => $this->id]);
            return [];
        }

        // تمیز کردن و تفکیک نام‌های نویسندگان
        $authorsString = trim($authorsString);
        $separators = [',', '،', ';', '؛', '&', 'and', 'و'];

        // جایگزینی جداکننده‌های مختلف با کاما
        foreach ($separators as $separator) {
            $authorsString = str_ireplace($separator, ',', $authorsString);
        }

        $authorNames = array_filter(array_map('trim', explode(',', $authorsString)));

        if (empty($authorNames)) {
            Log::warning('هیچ نام نویسنده‌ای یافت نشد', [
                'book_id' => $this->id,
                'original_string' => $authorsString
            ]);
            return [];
        }

        $addedAuthors = [];

        // دریافت نویسندگان موجود این کتاب
        $existingAuthorNames = $this->authors()->pluck('name')->map(function($name) {
            return strtolower(trim($name));
        })->toArray();

        Log::info('شروع اضافه کردن نویسندگان', [
            'book_id' => $this->id,
            'book_title' => $this->title,
            'author_names' => $authorNames,
            'existing_authors' => $existingAuthorNames
        ]);

        foreach ($authorNames as $name) {
            $name = trim($name);

            if (empty($name) || strlen($name) < 2) {
                Log::warning('نام نویسنده نامعتبر رد شد', ['name' => $name]);
                continue;
            }

            $normalizedName = strtolower(trim($name));

            // بررسی اینکه آیا این نویسنده قبلاً برای این کتاب ثبت شده
            if (in_array($normalizedName, $existingAuthorNames)) {
                Log::info('نویسنده قبلاً برای این کتاب ثبت شده', [
                    'book_id' => $this->id,
                    'author_name' => $name
                ]);
                continue;
            }

            try {
                // ایجاد یا پیدا کردن نویسنده
                $author = Author::firstOrCreate(
                    ['name' => $name],
                    [
                        'slug' => Str::slug($name . '_' . time() . '_' . rand(1000, 9999)),
                        'is_active' => true,
                        'books_count' => 0
                    ]
                );

                Log::info('نویسنده ایجاد یا پیدا شد', [
                    'author_id' => $author->id,
                    'author_name' => $author->name,
                    'was_recently_created' => $author->wasRecentlyCreated
                ]);

                // بررسی اینکه آیا رابطه book-author وجود دارد
                $relationExists = $this->authors()->where('author_id', $author->id)->exists();

                if (!$relationExists) {
                    // اضافه کردن رابطه
                    $this->authors()->attach($author->id, [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);

                    $addedAuthors[] = $name;

                    // بروزرسانی تعداد کتاب‌های نویسنده
                    $author->increment('books_count');

                    Log::info('نویسنده به کتاب اضافه شد', [
                        'book_id' => $this->id,
                        'author_id' => $author->id,
                        'author_name' => $author->name
                    ]);
                } else {
                    Log::info('رابطه book-author قبلاً وجود داشت', [
                        'book_id' => $this->id,
                        'author_id' => $author->id,
                        'author_name' => $author->name
                    ]);
                }

            } catch (\Exception $e) {
                Log::error('خطا در اضافه کردن نویسنده', [
                    'book_id' => $this->id,
                    'author_name' => $name,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        if (!empty($addedAuthors)) {
            Log::info('نویسندگان جدید با موفقیت اضافه شدند', [
                'book_id' => $this->id,
                'book_title' => $this->title,
                'added_authors' => $addedAuthors,
                'total_added' => count($addedAuthors)
            ]);
        } else {
            Log::warning('هیچ نویسنده جدیدی اضافه نشد', [
                'book_id' => $this->id,
                'original_string' => $authorsString,
                'parsed_names' => $authorNames
            ]);
        }

        return $addedAuthors;
    }

    public function smartUpdate(array $newData, array $options = []): array
    {
        $changes = [];
        $needsUpdate = false;

        Log::info('🔄 شروع smartUpdate برای کتاب', [
            'book_id' => $this->id,
            'title' => $this->title,
            'new_data_keys' => array_keys($newData),
            'options' => $options,
        ]);

        if ($options['fill_missing_fields'] ?? true) {
            $fillableFields = [
                'publication_year', 'pages_count', 'file_size', 'language',
                'format', 'excerpt', 'category', 'publisher',
            ];

            foreach ($fillableFields as $field) {
                if ($this->shouldFillField($field, $newData)) {
                    $oldValue = $this->$field;
                    $this->$field = $newData[$field];
                    $changes['filled_fields'][] = [
                        'field' => $field,
                        'old_value' => $oldValue,
                        'new_value' => $newData[$field],
                    ];
                    $needsUpdate = true;

                    Log::info('✅ فیلد خالی تکمیل شد', [
                        'field' => $field,
                        'old' => $oldValue,
                        'new' => $newData[$field],
                    ]);
                }
            }
        }

        if ($options['update_descriptions'] ?? true) {
            $descriptionResult = $this->smartUpdateDescription($newData['description'] ?? null);
            if ($descriptionResult['updated']) {
                $changes['updated_description'] = $descriptionResult;
                $needsUpdate = true;
            }
        }

        if (!empty($newData['isbn'])) {
            $isbnResult = $this->smartMergeIsbn($newData['isbn']);
            if ($isbnResult['updated']) {
                $changes['merged_isbn'] = $isbnResult;
                $needsUpdate = true;
            }
        }

        if (!empty($newData['author'])) {
            $authorsResult = $this->smartAddAuthors($newData['author']);
            if (!empty($authorsResult)) {
                $changes['added_authors'] = $authorsResult;
            }
        }

        $numericFields = ['publication_year', 'pages_count', 'file_size'];
        foreach ($numericFields as $field) {
            if (isset($newData[$field]) && $this->shouldUpdateNumericField($field, $newData[$field])) {
                $oldValue = $this->$field;
                $this->$field = $newData[$field];
                $changes['updated_numeric'][] = [
                    'field' => $field,
                    'old_value' => $oldValue,
                    'new_value' => $newData[$field],
                ];
                $needsUpdate = true;
            }
        }

        if ($needsUpdate) {
            $this->save();
            Log::info('💾 تغییرات ذخیره شد', [
                'book_id' => $this->id,
                'changes_count' => count($changes),
            ]);
        }

        $hashResult = $this->smartUpdateHashes($newData);
        if ($hashResult['updated']) {
            $changes['updated_hashes'] = $hashResult;
        }

        if (!empty($newData['image_url'])) {
            $imageResult = $this->smartUpdateImages($newData['image_url']);
            if ($imageResult['updated']) {
                $changes['updated_images'] = $imageResult;
            }
        }

        $action = $this->determineAction($needsUpdate, $changes);

        Log::info('🎯 smartUpdate تمام شد', [
            'book_id' => $this->id,
            'action' => $action,
            'changes_summary' => array_keys($changes),
        ]);

        return [
            'updated' => $needsUpdate,
            'changes' => $changes,
            'action' => $action,
        ];
    }

    private function smartAddAuthors(string $authorsString): array
    {
        return $this->addAuthorsWithTimestamps($authorsString);
    }

    private function smartUpdateHashes(array $data): array
    {
        if (!$this->hashes) {
            return ['updated' => false, 'reason' => 'no_hash_record'];
        }

        $hashFields = ['sha1', 'sha256', 'crc32', 'ed2k', 'btih', 'magnet'];
        $updates = [];
        $addedHashes = [];

        foreach ($hashFields as $field) {
            $dbField = $field === 'ed2k' ? 'ed2k_hash' : ($field === 'magnet' ? 'magnet_link' : $field);

            if (!empty($data[$field]) && empty($this->hashes->$dbField)) {
                $updates[$dbField] = $data[$field];
                $addedHashes[] = $field;
            }
        }

        if (!empty($updates)) {
            $this->hashes->update($updates);

            Log::info('🔐 هش‌های جدید اضافه شدند', [
                'book_id' => $this->id,
                'added_hashes' => $addedHashes,
            ]);

            return [
                'updated' => true,
                'added_hashes' => $addedHashes,
                'updates' => $updates,
            ];
        }

        return ['updated' => false, 'reason' => 'no_new_hashes'];
    }

    private function shouldFillField(string $field, array $newData): bool
    {
        if (empty($newData[$field])) {
            return false;
        }

        $currentValue = $this->$field;

        return empty($currentValue) ||
            (is_string($currentValue) && trim($currentValue) === '') ||
            (is_numeric($currentValue) && $currentValue <= 0);
    }

    private function smartUpdateDescription(?string $newDescription): array
    {
        if (empty($newDescription)) {
            return ['updated' => false];
        }

        $currentDescription = trim($this->description ?? '');
        $newDescriptionTrimmed = trim($newDescription);

        $currentLength = strlen($currentDescription);
        $newLength = strlen($newDescriptionTrimmed);

        $shouldUpdate = false;
        $reason = '';

        if ($currentLength === 0) {
            $shouldUpdate = true;
            $reason = 'empty_current';
        } elseif ($newLength > $currentLength * 1.3) {
            $shouldUpdate = true;
            $reason = 'significantly_longer';
        } elseif ($newLength > $currentLength + 100) {
            $shouldUpdate = true;
            $reason = 'much_more_content';
        } elseif ($this->isNewDescriptionBetter($currentDescription, $newDescriptionTrimmed)) {
            $shouldUpdate = true;
            $reason = 'better_quality';
        }

        if ($shouldUpdate) {
            $oldDescription = $this->description;
            $this->description = $newDescriptionTrimmed;

            Log::info('📝 توضیحات بروزرسانی شد', [
                'book_id' => $this->id,
                'reason' => $reason,
                'old_length' => $currentLength,
                'new_length' => $newLength,
            ]);

            return [
                'updated' => true,
                'reason' => $reason,
                'old_length' => $currentLength,
                'new_length' => $newLength,
                'old_description' => Str::limit($oldDescription, 100),
                'new_description' => Str::limit($newDescriptionTrimmed, 100),
            ];
        }

        return ['updated' => false, 'reason' => 'not_better'];
    }

    private function isNewDescriptionBetter(string $current, string $new): bool
    {
        $currentSentences = substr_count($current, '.') + substr_count($current, '!') + substr_count($current, '?');
        $newSentences = substr_count($new, '.') + substr_count($new, '!') + substr_count($new, '?');

        $currentWords = str_word_count($current);
        $newWords = str_word_count($new);

        return ($newSentences > $currentSentences * 1.5) || ($newWords > $currentWords * 1.5);
    }

    private function smartMergeIsbn(string $newIsbn): array
    {
        $newIsbn = trim($newIsbn);
        if (empty($newIsbn)) {
            return ['updated' => false];
        }

        $currentIsbn = trim($this->isbn ?? '');

        if (empty($currentIsbn)) {
            $this->isbn = $newIsbn;
            return [
                'updated' => true,
                'action' => 'filled_empty',
                'new_isbn' => $newIsbn,
            ];
        }

        $existingIsbns = array_filter(array_map('trim', explode(',', $currentIsbn)));
        $newIsbns = array_filter(array_map('trim', explode(',', $newIsbn)));
        $addedIsbns = [];

        foreach ($newIsbns as $isbn) {
            $cleanIsbn = preg_replace('/[^0-9X]/i', '', $isbn);
            $cleanExisting = array_map(fn($existing) => preg_replace('/[^0-9X]/i', '', $existing), $existingIsbns);

            if (!in_array($cleanIsbn, $cleanExisting) && strlen($cleanIsbn) >= 10) {
                $existingIsbns[] = $isbn;
                $addedIsbns[] = $isbn;
            }
        }

        if (!empty($addedIsbns)) {
            $this->isbn = implode(', ', $existingIsbns);

            Log::info('📚 ISBN جدید اضافه شد', [
                'book_id' => $this->id,
                'added_isbns' => $addedIsbns,
                'final_isbn' => $this->isbn,
            ]);

            return [
                'updated' => true,
                'action' => 'merged',
                'added_isbns' => $addedIsbns,
                'final_isbn' => $this->isbn,
            ];
        }

        return ['updated' => false, 'reason' => 'no_new_isbns'];
    }

    private function shouldUpdateNumericField(string $field, $newValue): bool
    {
        if (!is_numeric($newValue) || $newValue <= 0) {
            return false;
        }

        $currentValue = $this->$field;

        if (empty($currentValue) || $currentValue <= 0) {
            return true;
        }

        $currentYear = date('Y');
        return match ($field) {
            'publication_year' => ($newValue >= 1900 && $newValue <= $currentYear + 2) &&
                abs($newValue - $currentYear) < abs($currentValue - $currentYear),
            'pages_count' => ($newValue >= 10 && $newValue <= 10000) &&
                ($currentValue < 10 || $currentValue > 10000 || abs($newValue - 200) < abs($currentValue - 200)),
            'file_size' => ($newValue >= 1024 && $newValue <= 1024 * 1024 * 1024) &&
                ($currentValue < 1024 || $currentValue > 1024 * 1024 * 1024),
            default => false,
        };
    }

    private function smartUpdateImages(string $imageUrl): array
    {
        if (empty($imageUrl) || !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            return ['updated' => false, 'reason' => 'invalid_url'];
        }

        if (!$this->images()->where('image_url', $imageUrl)->exists()) {
            $this->images()->create(['image_url' => $imageUrl]);

            Log::info('🖼️ تصویر جدید اضافه شد', [
                'book_id' => $this->id,
                'image_url' => $imageUrl,
            ]);

            return [
                'updated' => true,
                'action' => 'added_new_image',
                'image_url' => $imageUrl,
            ];
        }

        return ['updated' => false, 'reason' => 'image_exists'];
    }

    private function determineAction(bool $needsUpdate, array $changes): string
    {
        if (empty($changes)) {
            return 'no_changes';
        }

        $changeTypes = array_keys($changes);

        if (in_array('filled_fields', $changeTypes)) {
            return 'enhanced';
        }

        if (in_array('updated_description', $changeTypes)) {
            return 'enriched';
        }

        if (in_array('merged_isbn', $changeTypes) || in_array('added_authors', $changeTypes)) {
            return 'merged';
        }

        return $needsUpdate ? 'updated' : 'unchanged';
    }

    public static function calculateContentMd5(array $data): string
    {
        $content = implode('|', [
            strtolower(trim($data['title'] ?? '')),
            strtolower(trim($data['author'] ?? '')),
            trim($data['isbn'] ?? ''),
            $data['publication_year'] ?? '',
            $data['pages_count'] ?? '',
        ]);

        return md5($content);
    }

    public static function findByContent(array $data): ?self
    {
        return self::findByMd5(self::calculateContentMd5($data));
    }
}
