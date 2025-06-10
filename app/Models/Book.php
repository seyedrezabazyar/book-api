<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;

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
        'status'
    ];

    protected $casts = [
        'publication_year' => 'integer',
        'pages_count' => 'integer',
        'file_size' => 'integer',
        'downloads_count' => 'integer'
    ];

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

    public function hashes(): HasOne
    {
        return $this->hasOne(BookHash::class);
    }

    /**
     * اسکوپ‌ها
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
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
     * متدهای کمکی
     */
    public function getMainHash(): ?string
    {
        return $this->hashes?->md5;
    }

    public function getAllHashes(): array
    {
        return $this->hashes ? $this->hashes->getAllHashes() : [];
    }

    /**
     * بررسی تکراری بودن بر اساس MD5
     */
    public static function findByMd5(string $md5): ?self
    {
        $bookHash = BookHash::where('md5', $md5)->first();
        return $bookHash ? $bookHash->book : null;
    }

    /**
     * ایجاد کتاب جدید با تمام روابط
     */
    public static function createWithDetails(array $bookData, array $hashData = [], string $sourceName = null, string $sourceId = null): self
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($bookData, $hashData, $sourceName, $sourceId) {
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
                'category_id' => $category->id,
                'publisher_id' => $publisher?->id,
                'downloads_count' => 0,
                'status' => 'active'
            ]);

            // ایجاد هش‌ها - اطمینان از وجود MD5
            if (!empty($hashData['md5'])) {
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
            }

            // اضافه کردن نویسندگان
            if (!empty($bookData['author'])) {
                $book->addAuthors($bookData['author']);
            }

            // اضافه کردن تصاویر
            if (!empty($bookData['image_url'])) {
                BookImage::create([
                    'book_id' => $book->id,
                    'image_url' => $bookData['image_url']
                ]);
            }

            // اضافه کردن منبع
            if ($sourceName && $sourceId) {
                BookSource::recordBookSource($book->id, $sourceName, $sourceId);
            }

            Log::info("✨ کتاب جدید ایجاد شد", [
                'book_id' => $book->id,
                'title' => $book->title,
                'md5' => $hashData['md5'] ?? null,
                'source' => $sourceName,
            ]);

            return $book;
        });
    }

    /**
     * بروزرسانی هوشمند کتاب با منطق پیشرفته
     */
    public function smartUpdate(array $newData, array $options = []): array
    {
        $changes = [];
        $needsUpdate = false;

        Log::info("🔄 شروع smartUpdate برای کتاب", [
            'book_id' => $this->id,
            'title' => $this->title,
            'new_data_keys' => array_keys($newData),
            'options' => $options
        ]);

        // تکمیل فیلدهای خالی - لیست کامل‌تر
        if ($options['fill_missing_fields'] ?? true) {
            $fillableFields = [
                'publication_year', 'pages_count', 'file_size', 'language',
                'format', 'excerpt', 'category', 'publisher'
            ];

            foreach ($fillableFields as $field) {
                if ($this->shouldFillField($field, $newData)) {
                    $oldValue = $this->$field;
                    $this->$field = $newData[$field];
                    $changes['filled_fields'][] = [
                        'field' => $field,
                        'old_value' => $oldValue,
                        'new_value' => $newData[$field]
                    ];
                    $needsUpdate = true;

                    Log::info("✅ فیلد خالی تکمیل شد", [
                        'field' => $field,
                        'old' => $oldValue,
                        'new' => $newData[$field]
                    ]);
                }
            }
        }

        // بهبود توضیحات با منطق پیشرفته‌تر
        if ($options['update_descriptions'] ?? true) {
            $descriptionResult = $this->smartUpdateDescription($newData['description'] ?? null);
            if ($descriptionResult['updated']) {
                $changes['updated_description'] = $descriptionResult;
                $needsUpdate = true;
            }
        }

        // ادغام هوشمند ISBN
        if (!empty($newData['isbn'])) {
            $isbnResult = $this->smartMergeIsbn($newData['isbn']);
            if ($isbnResult['updated']) {
                $changes['merged_isbn'] = $isbnResult;
                $needsUpdate = true;
            }
        }

        // ادغام نویسندگان (این قبلاً وجود داشت اما بهبود می‌دهیم)
        if (!empty($newData['author'])) {
            $authorsResult = $this->smartAddAuthors($newData['author']);
            if (!empty($authorsResult['added'])) {
                $changes['added_authors'] = $authorsResult;
            }
        }

        // بروزرسانی فیلدهای عددی با مقایسه هوشمند
        $numericFields = ['publication_year', 'pages_count', 'file_size'];
        foreach ($numericFields as $field) {
            if (isset($newData[$field]) && $this->shouldUpdateNumericField($field, $newData[$field])) {
                $oldValue = $this->$field;
                $this->$field = $newData[$field];
                $changes['updated_numeric'][] = [
                    'field' => $field,
                    'old_value' => $oldValue,
                    'new_value' => $newData[$field]
                ];
                $needsUpdate = true;
            }
        }

        // بروزرسانی دسته‌بندی و ناشر با منطق هوشمند
        $relationFields = ['category', 'publisher'];
        foreach ($relationFields as $field) {
            if (isset($newData[$field]) && $this->shouldUpdateRelationField($field, $newData[$field])) {
                $changes['updated_relations'][] = [
                    'field' => $field,
                    'old_value' => $this->$field,
                    'new_value' => $newData[$field]
                ];
                // این فیلدها در createWithDetails پردازش می‌شوند
            }
        }

        // ذخیره تغییرات
        if ($needsUpdate) {
            $this->save();
            Log::info("💾 تغییرات ذخیره شد", [
                'book_id' => $this->id,
                'changes_count' => count($changes)
            ]);
        }

        // بروزرسانی هش‌ها
        $hashResult = $this->smartUpdateHashes($newData);
        if ($hashResult['updated']) {
            $changes['updated_hashes'] = $hashResult;
        }

        // بروزرسانی تصاویر
        if (!empty($newData['image_url'])) {
            $imageResult = $this->smartUpdateImages($newData['image_url']);
            if ($imageResult['updated']) {
                $changes['updated_images'] = $imageResult;
            }
        }

        $action = $this->determineAction($needsUpdate, $changes);

        Log::info("🎯 smartUpdate تمام شد", [
            'book_id' => $this->id,
            'action' => $action,
            'changes_summary' => array_keys($changes)
        ]);

        return [
            'updated' => $needsUpdate,
            'changes' => $changes,
            'action' => $action
        ];
    }

    /**
     * بررسی اینکه آیا فیلد باید تکمیل شود
     */
    private function shouldFillField(string $field, array $newData): bool
    {
        // اگر فیلد جدید خالی است، نیازی به تکمیل نیست
        if (empty($newData[$field])) {
            return false;
        }

        // اگر فیلد فعلی خالی است، باید تکمیل شود
        $currentValue = $this->$field;

        return empty($currentValue) ||
            (is_string($currentValue) && trim($currentValue) === '') ||
            (is_numeric($currentValue) && $currentValue <= 0);
    }

    /**
     * بروزرسانی هوشمند توضیحات
     */
    private function smartUpdateDescription(?string $newDescription): array
    {
        if (empty($newDescription)) {
            return ['updated' => false];
        }

        $currentDescription = trim($this->description ?? '');
        $newDescriptionTrimmed = trim($newDescription);

        $currentLength = strlen($currentDescription);
        $newLength = strlen($newDescriptionTrimmed);

        // شرایط بروزرسانی:
        // 1. توضیحات فعلی خالی است
        // 2. توضیحات جدید حداقل 30% بلندتر است
        // 3. توضیحات جدید حداقل 100 کاراکتر بیشتر دارد
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

            Log::info("📝 توضیحات بروزرسانی شد", [
                'book_id' => $this->id,
                'reason' => $reason,
                'old_length' => $currentLength,
                'new_length' => $newLength
            ]);

            return [
                'updated' => true,
                'reason' => $reason,
                'old_length' => $currentLength,
                'new_length' => $newLength,
                'old_description' => Str::limit($oldDescription, 100),
                'new_description' => Str::limit($newDescriptionTrimmed, 100)
            ];
        }

        return ['updated' => false, 'reason' => 'not_better'];
    }

    /**
     * بررسی کیفیت توضیحات جدید
     */
    private function isNewDescriptionBetter(string $current, string $new): bool
    {
        // تعداد جملات
        $currentSentences = substr_count($current, '.') + substr_count($current, '!') + substr_count($current, '?');
        $newSentences = substr_count($new, '.') + substr_count($new, '!') + substr_count($new, '?');

        // تعداد کلمات
        $currentWords = str_word_count($current);
        $newWords = str_word_count($new);

        // اگر توضیحات جدید بیشتر از 50% جمله یا کلمه بیشتر دارد
        return ($newSentences > $currentSentences * 1.5) || ($newWords > $currentWords * 1.5);
    }

    /**
     * ادغام هوشمند ISBN
     */
    private function smartMergeIsbn(string $newIsbn): array
    {
        $newIsbn = trim($newIsbn);
        if (empty($newIsbn)) {
            return ['updated' => false];
        }

        $currentIsbn = trim($this->isbn ?? '');

        // اگر فعلی خالی است
        if (empty($currentIsbn)) {
            $this->isbn = $newIsbn;
            return [
                'updated' => true,
                'action' => 'filled_empty',
                'new_isbn' => $newIsbn
            ];
        }

        // تجزیه ISBN های موجود
        $existingIsbns = array_filter(array_map('trim', explode(',', $currentIsbn)));
        $newIsbns = array_filter(array_map('trim', explode(',', $newIsbn)));

        $addedIsbns = [];
        foreach ($newIsbns as $isbn) {
            // تمیز کردن ISBN (حذف خط تیره و فاصله)
            $cleanIsbn = preg_replace('/[^0-9X]/i', '', $isbn);
            $cleanExisting = array_map(function($existing) {
                return preg_replace('/[^0-9X]/i', '', $existing);
            }, $existingIsbns);

            // اگر این ISBN قبلاً وجود ندارد
            if (!in_array($cleanIsbn, $cleanExisting) && strlen($cleanIsbn) >= 10) {
                $existingIsbns[] = $isbn;
                $addedIsbns[] = $isbn;
            }
        }

        if (!empty($addedIsbns)) {
            $this->isbn = implode(', ', $existingIsbns);

            Log::info("📚 ISBN جدید اضافه شد", [
                'book_id' => $this->id,
                'added_isbns' => $addedIsbns,
                'final_isbn' => $this->isbn
            ]);

            return [
                'updated' => true,
                'action' => 'merged',
                'added_isbns' => $addedIsbns,
                'final_isbn' => $this->isbn
            ];
        }

        return ['updated' => false, 'reason' => 'no_new_isbns'];
    }

    /**
     * اضافه کردن هوشمند نویسندگان
     */
    private function smartAddAuthors(string $authorsString): array
    {
        $authorNames = array_filter(array_map('trim', explode(',', $authorsString)));
        $existingAuthors = $this->authors()->pluck('name')->toArray();
        $addedAuthors = [];

        foreach ($authorNames as $name) {
            $name = trim($name);
            if (empty($name)) continue;

            // بررسی تکراری بودن (case-insensitive)
            $isExisting = false;
            foreach ($existingAuthors as $existing) {
                if (mb_strtolower($name) === mb_strtolower($existing)) {
                    $isExisting = true;
                    break;
                }
            }

            if (!$isExisting) {
                $author = Author::firstOrCreate(
                    ['name' => $name],
                    [
                        'slug' => Str::slug($name . '_' . time()),
                        'is_active' => true
                    ]
                );

                if (!$this->authors()->where('author_id', $author->id)->exists()) {
                    $this->authors()->attach($author->id);
                    $addedAuthors[] = $name;
                }
            }
        }

        if (!empty($addedAuthors)) {
            Log::info("👤 نویسندگان جدید اضافه شدند", [
                'book_id' => $this->id,
                'added_authors' => $addedAuthors
            ]);
        }

        return [
            'added' => $addedAuthors,
            'total_authors' => $this->authors()->count()
        ];
    }

    /**
     * بروزرسانی فیلدهای عددی
     */
    private function shouldUpdateNumericField(string $field, $newValue): bool
    {
        if (!is_numeric($newValue) || $newValue <= 0) {
            return false;
        }

        $currentValue = $this->$field;

        // اگر فعلی خالی یا صفر است
        if (empty($currentValue) || $currentValue <= 0) {
            return true;
        }

        // منطق خاص برای هر فیلد
        switch ($field) {
            case 'publication_year':
                // اگر سال جدید معقول‌تر است (نه خیلی قدیم و نه آینده)
                $currentYear = date('Y');
                return ($newValue >= 1900 && $newValue <= $currentYear + 2) &&
                    abs($newValue - $currentYear) < abs($currentValue - $currentYear);

            case 'pages_count':
                // اگر تعداد صفحات جدید منطقی‌تر است (نه خیلی کم و نه خیلی زیاد)
                return ($newValue >= 10 && $newValue <= 10000) &&
                    ($currentValue < 10 || $currentValue > 10000 ||
                        abs($newValue - 200) < abs($currentValue - 200)); // 200 صفحه متوسط

            case 'file_size':
                // اگر حجم فایل منطقی‌تر است
                return ($newValue >= 1024 && $newValue <= 1024*1024*1024) && // 1KB تا 1GB
                    ($currentValue < 1024 || $currentValue > 1024*1024*1024);
        }

        return false;
    }

    /**
     * بررسی بروزرسانی فیلدهای رابطه‌ای
     */
    private function shouldUpdateRelationField(string $field, $newValue): bool
    {
        if (empty($newValue)) return false;

        $currentValue = $this->$field;

        // اگر فعلی خالی است
        if (empty($currentValue)) return true;

        // اگر مقدار جدید بهتر است (طولانی‌تر یا حاوی اطلاعات بیشتر)
        if (is_string($newValue) && is_string($currentValue)) {
            return strlen(trim($newValue)) > strlen(trim($currentValue)) * 1.2;
        }

        return false;
    }

    /**
     * بروزرسانی هوشمند هش‌ها
     */
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

            Log::info("🔐 هش‌های جدید اضافه شدند", [
                'book_id' => $this->id,
                'added_hashes' => $addedHashes
            ]);

            return [
                'updated' => true,
                'added_hashes' => $addedHashes,
                'updates' => $updates
            ];
        }

        return ['updated' => false, 'reason' => 'no_new_hashes'];
    }

    /**
     * بروزرسانی تصاویر
     */
    private function smartUpdateImages(string $imageUrl): array
    {
        if (empty($imageUrl) || !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            return ['updated' => false, 'reason' => 'invalid_url'];
        }

        // بررسی اینکه این URL قبلاً وجود دارد یا نه
        $existingImage = $this->images()->where('image_url', $imageUrl)->exists();

        if (!$existingImage) {
            $this->images()->create(['image_url' => $imageUrl]);

            Log::info("🖼️ تصویر جدید اضافه شد", [
                'book_id' => $this->id,
                'image_url' => $imageUrl
            ]);

            return [
                'updated' => true,
                'action' => 'added_new_image',
                'image_url' => $imageUrl
            ];
        }

        return ['updated' => false, 'reason' => 'image_exists'];
    }

    /**
     * تعیین عمل انجام شده
     */
    private function determineAction(bool $needsUpdate, array $changes): string
    {
        if (empty($changes)) {
            return 'no_changes';
        }

        $changeTypes = array_keys($changes);

        if (in_array('filled_fields', $changeTypes)) {
            return 'enhanced'; // بهبود یافته
        }

        if (in_array('updated_description', $changeTypes)) {
            return 'enriched'; // غنی شده
        }

        if (in_array('merged_isbn', $changeTypes) || in_array('added_authors', $changeTypes)) {
            return 'merged'; // ادغام شده
        }

        return $needsUpdate ? 'updated' : 'unchanged';
    }

    private function mergeIsbn(string $newIsbn): bool
    {
        if (empty($this->isbn)) {
            $this->isbn = $newIsbn;
            return true;
        }

        $existing = array_filter(explode(',', $this->isbn));
        $new = array_filter(explode(',', $newIsbn));
        $merged = array_unique(array_merge($existing, $new));

        $mergedIsbn = implode(', ', $merged);
        if ($mergedIsbn !== $this->isbn) {
            $this->isbn = $mergedIsbn;
            return true;
        }

        return false;
    }

    private function addAuthors(string $authorsString): array
    {
        $authorNames = array_map('trim', explode(',', $authorsString));
        $existingNames = $this->authors()->pluck('name')->toArray();
        $addedAuthors = [];

        foreach ($authorNames as $name) {
            if (empty($name) || in_array($name, $existingNames)) continue;

            $author = Author::firstOrCreate(
                ['name' => $name],
                [
                    'slug' => \Illuminate\Support\Str::slug($name . '_' . time()),
                    'is_active' => true
                ]
            );

            if (!$this->authors()->where('author_id', $author->id)->exists()) {
                $this->authors()->attach($author->id);
                $addedAuthors[] = $name;
            }
        }

        return $addedAuthors;
    }

    private function updateHashes(array $data): void
    {
        if (empty($this->hashes)) return;

        $hashFields = ['sha1', 'sha256', 'crc32', 'ed2k', 'btih', 'magnet'];
        $updates = [];

        foreach ($hashFields as $field) {
            $dbField = $field === 'ed2k' ? 'ed2k_hash' : ($field === 'magnet' ? 'magnet_link' : $field);

            if (!empty($data[$field]) && empty($this->hashes->$dbField)) {
                $updates[$dbField] = $data[$field];
            }
        }

        if (!empty($updates)) {
            $this->hashes->update($updates);
        }
    }

    /**
     * محاسبه MD5 بر اساس اطلاعات کتاب
     */
    public static function calculateContentMd5(array $data): string
    {
        $content = implode('|', [
            strtolower(trim($data['title'] ?? '')),
            strtolower(trim($data['author'] ?? '')),
            trim($data['isbn'] ?? ''),
            $data['publication_year'] ?? '',
            $data['pages_count'] ?? ''
        ]);

        return md5($content);
    }

    /**
     * پیدا کردن کتاب بر اساس محتوا
     */
    public static function findByContent(array $data): ?self
    {
        $md5 = self::calculateContentMd5($data);
        return self::findByMd5($md5);
    }
}
