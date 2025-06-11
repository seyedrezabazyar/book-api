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

    public function getAllHashes(): array
    {
        return $this->hashes ? $this->hashes->getAllHashes() : [];
    }

    public static function findByMd5(string $md5): ?self
    {
        return BookHash::where('md5', $md5)->first()?->book;
    }

    /**
     * ایجاد کتاب جدید با جزئیات کامل
     */
    public static function createWithDetails(array $bookData, array $hashData = [], ?string $sourceName = null, ?string $sourceId = null): self
    {
        return DB::transaction(function () use ($bookData, $hashData, $sourceName, $sourceId) {

            Log::info('🚀 شروع ایجاد کتاب جدید', [
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

            Log::info('✅ کتاب اصلی ایجاد شد', [
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

                    Log::info('🔐 هش‌های کتاب ایجاد شد', [
                        'book_id' => $book->id,
                        'hash_types' => array_keys(array_filter($hashData))
                    ]);
                } catch (\Exception $e) {
                    Log::error('❌ خطا در ایجاد هش‌های کتاب', [
                        'book_id' => $book->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // اضافه کردن نویسندگان
            if (!empty($bookData['author'])) {
                Log::info('👤 شروع اضافه کردن نویسندگان', [
                    'book_id' => $book->id,
                    'authors_string' => $bookData['author']
                ]);

                try {
                    $addedAuthors = $book->addAuthorsFromString($bookData['author']);

                    Log::info('✅ نویسندگان اضافه شدند', [
                        'book_id' => $book->id,
                        'added_authors' => $addedAuthors,
                        'added_count' => count($addedAuthors)
                    ]);

                    // بروزرسانی تعداد کتاب‌های دسته‌بندی و ناشر
                    $category->increment('books_count');
                    if ($publisher) {
                        $publisher->increment('books_count');
                    }

                } catch (\Exception $e) {
                    Log::error('❌ خطا در اضافه کردن نویسندگان', [
                        'book_id' => $book->id,
                        'authors_string' => $bookData['author'],
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            // اضافه کردن تصویر
            if (!empty($bookData['image_url'])) {
                try {
                    BookImage::create([
                        'book_id' => $book->id,
                        'image_url' => $bookData['image_url'],
                    ]);

                    Log::info('🖼️ تصویر کتاب اضافه شد', [
                        'book_id' => $book->id,
                        'image_url' => $bookData['image_url']
                    ]);
                } catch (\Exception $e) {
                    Log::error('❌ خطا در اضافه کردن تصویر', [
                        'book_id' => $book->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // ثبت منبع
            if ($sourceName && $sourceId) {
                try {
                    BookSource::recordBookSource($book->id, $sourceName, $sourceId);

                    Log::info('📋 منبع کتاب ثبت شد', [
                        'book_id' => $book->id,
                        'source_name' => $sourceName,
                        'source_id' => $sourceId
                    ]);
                } catch (\Exception $e) {
                    Log::error('❌ خطا در ثبت منبع کتاب', [
                        'book_id' => $book->id,
                        'source_name' => $sourceName,
                        'source_id' => $sourceId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // بررسی نهایی نویسندگان
            $finalAuthorsCount = $book->authors()->count();
            Log::info('🎯 کتاب با موفقیت ایجاد شد', [
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

    /**
     * اضافه کردن نویسندگان از رشته متنی
     */
    public function addAuthorsFromString(string $authorsString): array
    {
        if (empty(trim($authorsString))) {
            Log::debug('رشته نویسندگان خالی است', ['book_id' => $this->id]);
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

        return $this->addAuthorsArray($authorNames);
    }

    /**
     * اضافه کردن آرایه نویسندگان
     */
    public function addAuthorsArray(array $authorNames): array
    {
        $addedAuthors = [];

        // دریافت نویسندگان موجود این کتاب
        $existingAuthorNames = $this->authors()->pluck('name')->map(function($name) {
            return strtolower(trim($name));
        })->toArray();

        Log::debug('شروع اضافه کردن نویسندگان', [
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

            // بررسی تکراری نبودن
            if (in_array($normalizedName, $existingAuthorNames)) {
                Log::debug('نویسنده تکراری رد شد', [
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

                // بررسی وجود رابطه
                $relationExists = $this->authors()->where('author_id', $author->id)->exists();

                if (!$relationExists) {
                    // اضافه کردن رابطه
                    $this->authors()->attach($author->id, [
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);

                    $addedAuthors[] = $name;
                    $existingAuthorNames[] = $normalizedName; // به لیست محلی اضافه کن

                    // بروزرسانی تعداد کتاب‌های نویسنده
                    $author->increment('books_count');

                    Log::debug('✅ نویسنده اضافه شد', [
                        'book_id' => $this->id,
                        'author_id' => $author->id,
                        'author_name' => $author->name
                    ]);
                }

            } catch (\Exception $e) {
                Log::error('❌ خطا در اضافه کردن نویسنده', [
                    'book_id' => $this->id,
                    'author_name' => $name,
                    'error' => $e->getMessage()
                ]);
            }
        }

        if (!empty($addedAuthors)) {
            Log::info('🎉 نویسندگان جدید اضافه شدند', [
                'book_id' => $this->id,
                'book_title' => $this->title,
                'added_authors' => $addedAuthors,
                'total_added' => count($addedAuthors)
            ]);
        }

        return $addedAuthors;
    }

    /**
     * به‌روزرسانی هوشمند کتاب بر اساس داده‌های جدید - نسخه بهبود یافته
     */
    public function intelligentUpdate(array $newData, array $options = []): array
    {
        $changes = [];
        $needsUpdate = false;

        Log::info('🔄 شروع intelligentUpdate پیشرفته', [
            'book_id' => $this->id,
            'title' => $this->title,
            'new_data_keys' => array_keys($newData),
            'options' => $options,
        ]);

        // 1. تحلیل و آپدیت فیلدهای اصلی
        $fieldUpdates = $this->updateBookFieldsIntelligently($newData, $options);
        if ($fieldUpdates['updated']) {
            $changes = array_merge($changes, $fieldUpdates['changes']);
            $needsUpdate = true;
        }

        // 2. آپدیت یا اضافه کردن نویسندگان جدید
        if (!empty($newData['author'])) {
            $authorUpdates = $this->updateAuthorsIntelligently($newData['author']);
            if (!empty($authorUpdates)) {
                $changes['new_authors'] = $authorUpdates;
            }
        }

        // 3. آپدیت یا اضافه کردن ISBN های جدید
        if (!empty($newData['isbn'])) {
            $isbnUpdates = $this->updateIsbnsIntelligently($newData['isbn']);
            if (!empty($isbnUpdates)) {
                $changes['new_isbns'] = $isbnUpdates;
                $needsUpdate = true;
            }
        }

        // 4. آپدیت هش‌ها
        $hashResult = $this->updateHashesIntelligently($newData);
        if ($hashResult['updated']) {
            $changes['updated_hashes'] = $hashResult;
        }

        // 5. آپدیت تصاویر
        if (!empty($newData['image_url'])) {
            $imageResult = $this->updateImagesIntelligently($newData['image_url']);
            if ($imageResult['updated']) {
                $changes['updated_images'] = $imageResult;
            }
        }

        // 6. آپدیت دسته‌بندی و ناشر در صورت لزوم
        $metadataUpdates = $this->updateMetadataIntelligently($newData);
        if ($metadataUpdates['updated']) {
            $changes['updated_metadata'] = $metadataUpdates;
            $needsUpdate = true;
        }

        // ذخیره تغییرات
        if ($needsUpdate) {
            $this->save();
            Log::info('💾 تغییرات ذخیره شد', [
                'book_id' => $this->id,
                'changes_count' => count($changes),
            ]);
        }

        $action = $this->determineUpdateAction($needsUpdate, $changes);

        Log::info('🎯 intelligentUpdate تمام شد', [
            'book_id' => $this->id,
            'action' => $action,
            'changes_summary' => array_keys($changes),
            'database_updated' => $needsUpdate
        ]);

        return [
            'updated' => $needsUpdate,
            'changes' => $changes,
            'action' => $action,
            'summary' => $this->generateChangeSummary($changes)
        ];
    }

    /**
     * آپدیت هوشمند فیلدهای اصلی کتاب
     */
    private function updateBookFieldsIntelligently(array $newData, array $options): array
    {
        $changes = [];
        $updated = false;

        $fieldsToCheck = [
            'description' => 'description',
            'publication_year' => 'publication_year',
            'pages_count' => 'pages_count',
            'file_size' => 'file_size',
            'language' => 'language',
            'format' => 'format',
            'excerpt' => 'excerpt'
        ];

        foreach ($fieldsToCheck as $field => $dataKey) {
            if (!isset($newData[$dataKey])) {
                continue;
            }

            $updateResult = $this->evaluateFieldUpdate($field, $this->$field, $newData[$dataKey]);

            if ($updateResult['should_update']) {
                $oldValue = $this->$field;
                $this->$field = $updateResult['new_value'];

                $changes['updated_fields'][] = [
                    'field' => $field,
                    'old_value' => $oldValue,
                    'new_value' => $updateResult['new_value'],
                    'reason' => $updateResult['reason'],
                    'improvement_type' => $updateResult['type']
                ];

                $updated = true;

                Log::debug("✅ فیلد {$field} آپدیت شد", [
                    'book_id' => $this->id,
                    'field' => $field,
                    'reason' => $updateResult['reason'],
                    'type' => $updateResult['type']
                ]);
            }
        }

        return ['updated' => $updated, 'changes' => $changes];
    }

    /**
     * ارزیابی لزوم آپدیت فیلد خاص
     */
    private function evaluateFieldUpdate(string $field, $currentValue, $newValue): array
    {
        $result = [
            'should_update' => false,
            'new_value' => $newValue,
            'reason' => '',
            'type' => 'no_change'
        ];

        // اگر مقدار جدید خالی است، آپدیت نکن
        if ($this->isValueEmpty($newValue)) {
            return $result;
        }

        // اگر مقدار فعلی خالی است، حتماً آپدیت کن
        if ($this->isValueEmpty($currentValue)) {
            $result['should_update'] = true;
            $result['reason'] = 'فیلد خالی بود و مقدار جدید دارد';
            $result['type'] = 'fill_empty';
            return $result;
        }

        // بررسی‌های خاص هر فیلد
        switch ($field) {
            case 'description':
                return $this->evaluateDescriptionUpdate($currentValue, $newValue);

            case 'pages_count':
                return $this->evaluatePagesCountUpdate($currentValue, $newValue);

            case 'file_size':
                return $this->evaluateFileSizeUpdate($currentValue, $newValue);

            case 'publication_year':
                return $this->evaluatePublicationYearUpdate($currentValue, $newValue);

            case 'language':
                return $this->evaluateLanguageUpdate($currentValue, $newValue);

            case 'format':
                return $this->evaluateFormatUpdate($currentValue, $newValue);

            default:
                return $result;
        }
    }

    /**
     * ارزیابی آپدیت توضیحات
     */
    private function evaluateDescriptionUpdate($current, $new): array
    {
        $result = ['should_update' => false, 'new_value' => $new, 'reason' => '', 'type' => 'no_change'];

        $currentLength = strlen(trim($current ?? ''));
        $newLength = strlen(trim($new ?? ''));

        // اگر توضیحات جدید حداقل 50% طولانی‌تر است
        if ($newLength > ($currentLength * 1.5)) {
            $result['should_update'] = true;
            $result['reason'] = "توضیحات جدید {$newLength} کاراکتر در مقابل {$currentLength} کاراکتر فعلی";
            $result['type'] = 'better_content';
        }
        // یا اگر توضیحات جدید حداقل 200 کاراکتر بیشتر دارد
        elseif (($newLength - $currentLength) >= 200) {
            $result['should_update'] = true;
            $result['reason'] = "توضیحات جدید " . ($newLength - $currentLength) . " کاراکتر بیشتر دارد";
            $result['type'] = 'longer_content';
        }

        return $result;
    }

    /**
     * ارزیابی آپدیت تعداد صفحات
     */
    private function evaluatePagesCountUpdate($current, $new): array
    {
        $result = ['should_update' => false, 'new_value' => $new, 'reason' => '', 'type' => 'no_change'];

        if (!is_numeric($new) || $new <= 0 || $new > 50000) {
            return $result;
        }

        $newPages = (int)$new;
        $currentPages = (int)$current;

        // اگر تعداد صفحات جدید بیشتر و معقول است
        if ($newPages > $currentPages && $newPages <= ($currentPages * 3)) {
            $result['should_update'] = true;
            $result['new_value'] = $newPages;
            $result['reason'] = "تعداد صفحات بیشتر: {$newPages} در مقابل {$currentPages}";
            $result['type'] = 'higher_value';
        }

        return $result;
    }

    /**
     * ارزیابی آپدیت اندازه فایل
     */
    private function evaluateFileSizeUpdate($current, $new): array
    {
        $result = ['should_update' => false, 'new_value' => $new, 'reason' => '', 'type' => 'no_change'];

        if (!is_numeric($new) || $new <= 0) {
            return $result;
        }

        $newSize = (int)$new;
        $currentSize = (int)$current;

        // اگر اندازه فایل جدید بیشتر است (اما نه بیش از 10 برابر)
        if ($newSize > $currentSize && $newSize <= ($currentSize * 10)) {
            $result['should_update'] = true;
            $result['new_value'] = $newSize;
            $result['reason'] = "اندازه فایل بیشتر: " . $this->formatFileSize($newSize) . " در مقابل " . $this->formatFileSize($currentSize);
            $result['type'] = 'larger_size';
        }

        return $result;
    }

    /**
     * ارزیابی آپدیت سال انتشار
     */
    private function evaluatePublicationYearUpdate($current, $new): array
    {
        $result = ['should_update' => false, 'new_value' => $new, 'reason' => '', 'type' => 'no_change'];

        if (!is_numeric($new) || $new < 1000 || $new > date('Y') + 2) {
            return $result;
        }

        $newYear = (int)$new;
        $currentYear = date('Y');
        $currentYearValue = (int)$current;

        // اگر سال جدید به سال فعلی نزدیک‌تر است
        if (abs($newYear - $currentYear) < abs($currentYearValue - $currentYear)) {
            $result['should_update'] = true;
            $result['new_value'] = $newYear;
            $result['reason'] = "سال دقیق‌تر: {$newYear} در مقابل {$currentYearValue}";
            $result['type'] = 'more_accurate';
        }

        return $result;
    }

    /**
     * ارزیابی آپدیت زبان
     */
    private function evaluateLanguageUpdate($current, $new): array
    {
        $result = ['should_update' => false, 'new_value' => $new, 'reason' => '', 'type' => 'no_change'];

        // فقط اگر زبان فعلی نامشخص یا عمومی باشد
        if (in_array($current, ['unknown', 'general', '']) && !empty($new) && strlen($new) >= 2) {
            $result['should_update'] = true;
            $result['reason'] = "زبان مشخص شد: {$new}";
            $result['type'] = 'specification';
        }

        return $result;
    }

    /**
     * ارزیابی آپدیت فرمت
     */
    private function evaluateFormatUpdate($current, $new): array
    {
        $result = ['should_update' => false, 'new_value' => $new, 'reason' => '', 'type' => 'no_change'];

        $betterFormats = ['pdf' => 1, 'epub' => 2, 'mobi' => 2, 'djvu' => 1, 'audio' => 3];

        $currentScore = $betterFormats[$current] ?? 0;
        $newScore = $betterFormats[$new] ?? 0;

        // اگر فرمت جدید بهتر است
        if ($newScore > $currentScore) {
            $result['should_update'] = true;
            $result['reason'] = "فرمت بهتر: {$new} در مقابل {$current}";
            $result['type'] = 'better_format';
        }

        return $result;
    }

    /**
     * آپدیت هوشمند نویسندگان
     */
    private function updateAuthorsIntelligently(string $newAuthorsString): array
    {
        if (empty(trim($newAuthorsString))) {
            return [];
        }

        // دریافت نویسندگان موجود
        $existingAuthorNames = $this->authors()->pluck('name')->map(function($name) {
            return strtolower(trim($name));
        })->toArray();

        // پارس نویسندگان جدید
        $newAuthorNames = $this->parseAuthorsString($newAuthorsString);
        $uniqueNewAuthors = [];

        foreach ($newAuthorNames as $authorName) {
            $normalizedName = strtolower(trim($authorName));
            if (!in_array($normalizedName, $existingAuthorNames) && strlen(trim($authorName)) >= 2) {
                $uniqueNewAuthors[] = trim($authorName);
            }
        }

        if (!empty($uniqueNewAuthors)) {
            $addedAuthors = $this->addAuthorsArray($uniqueNewAuthors);

            Log::debug('👥 نویسندگان جدید اضافه شدند', [
                'book_id' => $this->id,
                'new_authors' => $addedAuthors,
                'total_authors_now' => $this->authors()->count()
            ]);

            return $addedAuthors;
        }

        return [];
    }

    /**
     * آپدیت هوشمند ISBN ها
     */
    private function updateIsbnsIntelligently(string $newIsbnString): array
    {
        if (empty(trim($newIsbnString))) {
            return [];
        }

        // دریافت ISBN های موجود
        $existingIsbns = $this->isbn ? array_map('trim', explode(',', $this->isbn)) : [];
        $existingIsbnsCleaned = array_map(function($isbn) {
            return preg_replace('/[^0-9X]/i', '', $isbn);
        }, $existingIsbns);

        // پارس ISBN های جدید
        $newIsbns = array_map('trim', explode(',', $newIsbnString));
        $uniqueNewIsbns = [];

        foreach ($newIsbns as $isbn) {
            $cleanedIsbn = preg_replace('/[^0-9X]/i', '', $isbn);
            if (!in_array($cleanedIsbn, $existingIsbnsCleaned) && strlen($cleanedIsbn) >= 10) {
                $uniqueNewIsbns[] = trim($isbn);
            }
        }

        if (!empty($uniqueNewIsbns)) {
            // اضافه کردن ISBN های جدید
            $allIsbns = array_merge($existingIsbns, $uniqueNewIsbns);
            $this->isbn = implode(', ', $allIsbns);

            Log::debug('📚 ISBN های جدید اضافه شدند', [
                'book_id' => $this->id,
                'new_isbns' => $uniqueNewIsbns,
                'final_isbn' => $this->isbn,
            ]);

            return $uniqueNewIsbns;
        }

        return [];
    }

    /**
     * آپدیت هوشمند متادیتا (دسته‌بندی و ناشر)
     */
    private function updateMetadataIntelligently(array $data): array
    {
        $updates = ['updated' => false, 'changes' => []];

        // آپدیت دسته‌بندی
        if (!empty($data['category']) && (empty($this->category) || $this->category->name === 'عمومی')) {
            $category = \App\Models\Category::firstOrCreate(
                ['name' => $data['category']],
                [
                    'slug' => \Illuminate\Support\Str::slug($data['category'] . '_' . time()),
                    'is_active' => true,
                    'books_count' => 0,
                ]
            );

            $oldCategoryName = $this->category?->name;
            $this->category_id = $category->id;
            $updates['updated'] = true;
            $updates['changes']['category'] = [
                'old' => $oldCategoryName,
                'new' => $category->name
            ];

            Log::debug('📂 دسته‌بندی آپدیت شد', [
                'book_id' => $this->id,
                'old_category' => $oldCategoryName,
                'new_category' => $category->name
            ]);
        }

        // آپدیت ناشر
        if (!empty($data['publisher']) && empty($this->publisher)) {
            $publisher = \App\Models\Publisher::firstOrCreate(
                ['name' => $data['publisher']],
                [
                    'slug' => \Illuminate\Support\Str::slug($data['publisher'] . '_' . time()),
                    'is_active' => true,
                    'books_count' => 0,
                ]
            );

            $this->publisher_id = $publisher->id;
            $updates['updated'] = true;
            $updates['changes']['publisher'] = [
                'old' => null,
                'new' => $publisher->name
            ];

            Log::debug('🏢 ناشر اضافه شد', [
                'book_id' => $this->id,
                'publisher' => $publisher->name
            ]);
        }

        return $updates;
    }

    /**
     * تولید خلاصه تغییرات
     */
    private function generateChangeSummary(array $changes): string
    {
        $summary = [];

        if (isset($changes['updated_fields'])) {
            $fieldsCount = count($changes['updated_fields']);
            $summary[] = "{$fieldsCount} فیلد آپدیت شد";
        }

        if (isset($changes['new_authors'])) {
            $authorsCount = count($changes['new_authors']);
            $summary[] = "{$authorsCount} نویسنده جدید";
        }

        if (isset($changes['new_isbns'])) {
            $isbnCount = count($changes['new_isbns']);
            $summary[] = "{$isbnCount} ISBN جدید";
        }

        if (isset($changes['updated_hashes']['added_hashes'])) {
            $hashCount = count($changes['updated_hashes']['added_hashes']);
            $summary[] = "{$hashCount} هش جدید";
        }

        if (isset($changes['updated_images'])) {
            $summary[] = "تصویر جدید";
        }

        if (isset($changes['updated_metadata']['changes'])) {
            $metaCount = count($changes['updated_metadata']['changes']);
            $summary[] = "{$metaCount} متادیتا آپدیت شد";
        }

        return !empty($summary) ? implode(', ', $summary) : 'بدون تغییر قابل توجه';
    }

    /**
     * فرمت کردن اندازه فایل
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * پارس رشته نویسندگان
     */
    private function parseAuthorsString(string $authorsString): array
    {
        $separators = [',', '،', ';', '؛', '&', 'and', 'و'];

        foreach ($separators as $separator) {
            $authorsString = str_ireplace($separator, ',', $authorsString);
        }

        return array_filter(array_map('trim', explode(',', $authorsString)));
    }

    /**
     * بروزرسانی فیلدهای کتاب
     */
    private function updateBookFields(array $newData, array $options): array
    {
        $changes = [];
        $updated = false;

        $fieldsToCheck = [
            'description', 'publication_year', 'pages_count', 'file_size',
            'language', 'format', 'excerpt'
        ];

        foreach ($fieldsToCheck as $field) {
            if (!isset($newData[$field])) {
                continue;
            }

            $currentValue = $this->$field;
            $newValue = $newData[$field];

            if ($this->shouldUpdateField($field, $currentValue, $newValue)) {
                $oldValue = $currentValue;
                $this->$field = $newValue;
                $changes['updated_fields'][] = [
                    'field' => $field,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                ];
                $updated = true;

                Log::debug("✅ فیلد {$field} بروزرسانی شد", [
                    'book_id' => $this->id,
                    'old' => $oldValue,
                    'new' => $newValue,
                ]);
            }
        }

        return ['updated' => $updated, 'changes' => $changes];
    }

    /**
     * بررسی لزوم بروزرسانی فیلد
     */
    private function shouldUpdateField(string $field, $currentValue, $newValue): bool
    {
        // اگر مقدار جدید خالی است، بروزرسانی نکن
        if ($this->isValueEmpty($newValue)) {
            return false;
        }

        // اگر مقدار فعلی خالی است، حتماً بروزرسان
        if ($this->isValueEmpty($currentValue)) {
            return true;
        }

        // منطق خاص هر فیلد
        switch ($field) {
            case 'description':
                return $this->isDescriptionBetter($currentValue, $newValue);

            case 'pages_count':
                return is_numeric($newValue) && $newValue > 0 &&
                    (is_numeric($currentValue) ? $newValue > $currentValue : true);

            case 'file_size':
                return is_numeric($newValue) && $newValue > 0 &&
                    (is_numeric($currentValue) ? $newValue > $currentValue : true);

            case 'publication_year':
                return $this->isYearBetter($currentValue, $newValue);

            default:
                return false;
        }
    }

    /**
     * بررسی بهتر بودن توضیحات
     */
    private function isDescriptionBetter($current, $new): bool
    {
        $currentLength = strlen(trim($current ?? ''));
        $newLength = strlen(trim($new ?? ''));

        // اگر توضیحات جدید حداقل 30% طولانی‌تر است
        return $newLength > ($currentLength * 1.3);
    }

    /**
     * بررسی بهتر بودن سال انتشار
     */
    private function isYearBetter($current, $new): bool
    {
        if (!is_numeric($new) || $new < 1000 || $new > date('Y') + 2) {
            return false;
        }

        if (!is_numeric($current)) {
            return true;
        }

        $currentYear = date('Y');
        return abs($new - $currentYear) < abs($current - $currentYear);
    }

    /**
     * بروزرسانی هش‌ها
     */
    private function updateHashesIntelligently(array $data): array
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

    /**
     * بروزرسانی تصاویر
     */
    private function updateImagesIntelligently(string $imageUrl): array
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

    /**
     * تشخیص نوع عملیات
     */
    private function determineUpdateAction(bool $needsUpdate, array $changes): string
    {
        if (empty($changes)) {
            return 'no_changes';
        }

        if (isset($changes['updated_fields']) && !empty($changes['updated_fields'])) {
            return 'enhanced';
        }

        if (isset($changes['new_authors']) || isset($changes['new_isbns'])) {
            return 'merged';
        }

        if (isset($changes['updated_hashes']) || isset($changes['updated_images'])) {
            return 'enriched';
        }

        return $needsUpdate ? 'updated' : 'unchanged';
    }

    /**
     * متدهای کمکی
     */
    private function isValueEmpty($value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (is_string($value) && trim($value) === '') {
            return true;
        }

        if (is_numeric($value) && $value <= 0) {
            return true;
        }

        return false;
    }

    /**
     * محاسبه MD5 محتوا
     */
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
}
