<?php

namespace App\Services;

use App\Models\Book;
use App\Models\BookSource;
use App\Models\Config;
use App\Models\ExecutionLog;
use App\Services\FieldExtractor;
use App\Services\DataValidator;
use Illuminate\Support\Facades\Log;

class BookProcessor
{
    public function __construct(
        private FieldExtractor $fieldExtractor,
        private DataValidator $dataValidator
    ) {}

    /**
     * بررسی وضعیت پردازش source - نسخه بهبود یافته
     */
    public function checkSourceProcessingStatus(string $sourceName, int $sourceId, Config $config = null): array
    {
        // پیدا کردن BookSource موجود
        $existingSource = BookSource::where('source_name', $sourceName)
            ->where('source_id', (string)$sourceId)
            ->with('book.hashes', 'book.authors', 'book.category', 'book.publisher')
            ->first();

        if (!$existingSource) {
            // Source جدید است، پردازش کامل انجام شود
            return [
                'should_skip' => false,
                'needs_reprocessing' => false,
                'reason' => 'new_source',
                'action' => 'process_new'
            ];
        }

        $book = $existingSource->book;
        if (!$book) {
            // Source وجود دارد ولی کتاب حذف شده، پردازش مجدد
            return [
                'should_skip' => false,
                'needs_reprocessing' => true,
                'reason' => 'book_not_found',
                'action' => 'reprocess'
            ];
        }

        // بررسی نیاز به آپدیت
        $updateAnalysis = $this->analyzeBookUpdateNeeds($book, $config);

        Log::debug("📊 تحلیل نیاز به آپدیت کتاب", [
            'book_id' => $book->id,
            'source_id' => $sourceId,
            'needs_update' => $updateAnalysis['needs_update'],
            'empty_fields_count' => count($updateAnalysis['empty_fields']),
            'update_potential_score' => $updateAnalysis['update_potential_score']
        ]);

        // اگر تنظیمات force update فعال باشد
        if ($config && $this->shouldForceUpdate($config)) {
            return [
                'should_skip' => false,
                'needs_reprocessing' => true,
                'reason' => 'force_update_enabled',
                'action' => 'force_reprocess',
                'book_id' => $book->id,
                'empty_fields' => $updateAnalysis['empty_fields'],
                'update_potential' => $updateAnalysis['update_potential']
            ];
        }

        // اگر کتاب نیاز جدی به آپدیت دارد
        if ($updateAnalysis['needs_update'] && $updateAnalysis['update_potential_score'] >= 3) {
            return [
                'should_skip' => false,
                'needs_reprocessing' => true,
                'reason' => 'significant_update_potential',
                'action' => 'reprocess_for_update',
                'book_id' => $book->id,
                'empty_fields' => $updateAnalysis['empty_fields'],
                'update_potential' => $updateAnalysis['update_potential'],
                'update_score' => $updateAnalysis['update_potential_score']
            ];
        }

        // اگر فقط فیلدهای جزئی خالی هستند
        if ($updateAnalysis['needs_update'] && $updateAnalysis['update_potential_score'] >= 1) {
            return [
                'should_skip' => false,
                'needs_reprocessing' => true,
                'reason' => 'minor_update_potential',
                'action' => 'reprocess_for_minor_update',
                'book_id' => $book->id,
                'empty_fields' => $updateAnalysis['empty_fields'],
                'update_potential' => $updateAnalysis['update_potential'],
                'update_score' => $updateAnalysis['update_potential_score']
            ];
        }

        // کتاب کامل است، نیازی به پردازش مجدد نیست
        return [
            'should_skip' => true,
            'needs_reprocessing' => false,
            'reason' => 'book_already_complete',
            'action' => 'already_processed',
            'book_id' => $book->id
        ];
    }

    /**
     * تحلیل نیاز کتاب به آپدیت
     */
    private function analyzeBookUpdateNeeds(Book $book, Config $config = null): array
    {
        $analysis = [
            'needs_update' => false,
            'empty_fields' => [],
            'update_potential' => [],
            'update_potential_score' => 0
        ];

        // فیلدهای مهم که اگر خالی باشند امتیاز بالا دارند
        $importantFields = [
            'description' => ['score' => 2, 'check' => fn($book) => empty($book->description)],
            'publication_year' => ['score' => 1, 'check' => fn($book) => empty($book->publication_year)],
            'pages_count' => ['score' => 1, 'check' => fn($book) => empty($book->pages_count)],
            'isbn' => ['score' => 1, 'check' => fn($book) => empty($book->isbn)],
            'publisher' => ['score' => 1, 'check' => fn($book) => empty($book->publisher_id)],
            'file_size' => ['score' => 1, 'check' => fn($book) => empty($book->file_size)]
        ];

        // بررسی فیلدهای خالی
        foreach ($importantFields as $field => $config_data) {
            if ($config_data['check']($book)) {
                $analysis['empty_fields'][] = $field;
                $analysis['update_potential'][] = [
                    'field' => $field,
                    'reason' => 'empty_field',
                    'score' => $config_data['score']
                ];
                $analysis['update_potential_score'] += $config_data['score'];
                $analysis['needs_update'] = true;
            }
        }

        // بررسی هش‌های مفقود
        if (!$book->hashes || empty($book->hashes->md5)) {
            $analysis['empty_fields'][] = 'md5_hash';
            $analysis['update_potential'][] = [
                'field' => 'md5_hash',
                'reason' => 'missing_primary_hash',
                'score' => 2
            ];
            $analysis['update_potential_score'] += 2;
            $analysis['needs_update'] = true;
        }

        // بررسی هش‌های ثانویه
        if ($book->hashes) {
            $secondaryHashes = ['sha1', 'sha256', 'btih'];
            foreach ($secondaryHashes as $hashType) {
                if (empty($book->hashes->$hashType)) {
                    $analysis['update_potential'][] = [
                        'field' => $hashType,
                        'reason' => 'missing_secondary_hash',
                        'score' => 0.5
                    ];
                    $analysis['update_potential_score'] += 0.5;
                    $analysis['needs_update'] = true;
                }
            }
        }

        // بررسی تعداد نویسندگان
        if ($book->authors()->count() === 0) {
            $analysis['empty_fields'][] = 'authors';
            $analysis['update_potential'][] = [
                'field' => 'authors',
                'reason' => 'no_authors',
                'score' => 2
            ];
            $analysis['update_potential_score'] += 2;
            $analysis['needs_update'] = true;
        }

        // بررسی دسته‌بندی عمومی
        if ($book->category && $book->category->name === 'عمومی') {
            $analysis['update_potential'][] = [
                'field' => 'category',
                'reason' => 'generic_category',
                'score' => 1
            ];
            $analysis['update_potential_score'] += 1;
            $analysis['needs_update'] = true;
        }

        // بررسی توضیحات کوتاه
        if (!empty($book->description) && strlen($book->description) < 100) {
            $analysis['update_potential'][] = [
                'field' => 'description',
                'reason' => 'short_description',
                'score' => 1
            ];
            $analysis['update_potential_score'] += 1;
            $analysis['needs_update'] = true;
        }

        Log::debug("📊 تحلیل آپدیت کتاب", [
            'book_id' => $book->id,
            'empty_fields' => $analysis['empty_fields'],
            'update_score' => $analysis['update_potential_score'],
            'needs_update' => $analysis['needs_update']
        ]);

        return $analysis;
    }

    /**
     * بررسی اینکه آیا force update فعال است
     */
    private function shouldForceUpdate(Config $config): bool
    {
        // بررسی تنظیمات کانفیگ
        $generalSettings = $config->getGeneralSettings();

        // اگر تنظیم force_reprocess فعال باشد
        if (!empty($generalSettings['force_reprocess'])) {
            return true;
        }

        // اگر fill_missing_fields فعال باشد
        if ($config->fill_missing_fields) {
            return true;
        }

        // اگر update_descriptions فعال باشد
        if ($config->update_descriptions) {
            return true;
        }

        return false;
    }

    /**
     * پردازش کتاب - نسخه بهبود یافته
     */
    public function processBook(
        array $bookData,
        int $sourceId,
        Config $config,
        ExecutionLog $executionLog,
        array $sourceStatus = null
    ): array {
        try {
            // استخراج فیلدها
            $extractedData = $this->fieldExtractor->extractFields($bookData, $config);

            if (empty($extractedData['title'])) {
                Log::warning("عنوان کتاب استخراج نشد", ['source_id' => $sourceId]);
                return $this->buildFailureResult($sourceId);
            }

            // اعتبارسنجی و تمیز کردن داده‌ها
            $cleanedData = $this->dataValidator->cleanAndValidate($extractedData);

            // محاسبه MD5 برای شناسایی کتاب منحصر‌به‌فرد
            $md5 = Book::calculateContentMd5($cleanedData);

            $executionLog->addLogEntry("🔐 محاسبه MD5 برای source ID {$sourceId}", [
                'source_id' => $sourceId,
                'md5' => $md5,
                'title' => $cleanedData['title'],
                'extracted_hashes' => $this->extractHashesFromData($cleanedData),
                'is_reprocessing' => $sourceStatus['needs_reprocessing'] ?? false
            ]);

            // بررسی وجود کتاب با MD5
            $existingBook = Book::findByMd5($md5);

            if ($existingBook) {
                // کتاب موجود - اجرای منطق به‌روزرسانی هوشمند
                return $this->handleExistingBook(
                    $existingBook,
                    $cleanedData,
                    $sourceId,
                    $config,
                    $executionLog,
                    $sourceStatus
                );
            } else {
                // کتاب جدید - ثبت کامل
                return $this->handleNewBook($cleanedData, $md5, $sourceId, $config, $executionLog);
            }

        } catch (\Exception $e) {
            Log::error("❌ خطا در پردازش کتاب", [
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->buildFailureResult($sourceId);
        }
    }

    /**
     * پردازش کتاب موجود - نسخه بهبود یافته با پشتیبانی از re-processing
     */
    private function handleExistingBook(
        Book $existingBook,
        array $newData,
        int $sourceId,
        Config $config,
        ExecutionLog $executionLog,
        array $sourceStatus = null
    ): array {
        Log::info("📚 پردازش کتاب موجود", [
            'book_id' => $existingBook->id,
            'title' => $existingBook->title,
            'source_id' => $sourceId,
            'new_data_fields' => array_keys($newData),
            'is_reprocessing' => $sourceStatus['needs_reprocessing'] ?? false
        ]);

        // 1. بررسی وجود منبع قبلی (فقط اگر re-processing نباشد)
        $sourceExists = BookSource::where('book_id', $existingBook->id)
            ->where('source_name', $config->source_name)
            ->where('source_id', (string)$sourceId)
            ->exists();

        // اگر در حال re-processing هستیم، منبع را حذف و دوباره اضافه می‌کنیم
        if ($sourceExists && ($sourceStatus['needs_reprocessing'] ?? false)) {
            $executionLog->addLogEntry("🔄 حذف منبع قدیمی برای re-processing", [
                'book_id' => $existingBook->id,
                'source_id' => $sourceId,
                'reason' => $sourceStatus['reason'] ?? 'reprocessing'
            ]);

            // منبع قدیمی را حذف نمی‌کنیم، فقط آپدیت می‌کنیم
            BookSource::where('book_id', $existingBook->id)
                ->where('source_name', $config->source_name)
                ->where('source_id', (string)$sourceId)
                ->update(['discovered_at' => now()]);
        }

        if ($sourceExists && !($sourceStatus['needs_reprocessing'] ?? false)) {
            $executionLog->addLogEntry("⏭️ Source قبلاً ثبت شده", [
                'book_id' => $existingBook->id,
                'source_id' => $sourceId,
                'reason' => 'source_already_recorded'
            ]);

            return $this->buildResult($sourceId, 'already_processed', [
                'total_processed' => 1,
                'total_success' => 0,
                'total_failed' => 0,
                'total_duplicate' => 1,
                'total_enhanced' => 0
            ], $existingBook);
        }

        // 2. آنالیز جامع فیلدها برای تشخیص نیاز به آپدیت
        $updateAnalysis = $this->analyzeUpdateNeeds($existingBook, $newData);

        $executionLog->addLogEntry("🔍 آنالیز نیاز به آپدیت", [
            'book_id' => $existingBook->id,
            'source_id' => $sourceId,
            'analysis' => $updateAnalysis,
            'forced_reprocessing' => $sourceStatus['needs_reprocessing'] ?? false
        ]);

        // 3. اگر هیچ آپدیتی لازم نیست و re-processing اجباری نیست، فقط منبع را اضافه کن
        if (!$updateAnalysis['needs_update'] && !($sourceStatus['needs_reprocessing'] ?? false)) {
            if (!$sourceExists) {
                $this->recordBookSource($existingBook->id, $config->source_name, $sourceId);
            }

            $executionLog->addLogEntry("📌 فقط منبع جدید اضافه شد", [
                'book_id' => $existingBook->id,
                'source_id' => $sourceId,
                'reason' => 'no_updates_needed'
            ]);

            return $this->buildResult($sourceId, 'source_added', [
                'total_processed' => 1,
                'total_success' => 0,
                'total_failed' => 0,
                'total_duplicate' => 1,
                'total_enhanced' => 0
            ], $existingBook);
        }

        // 4. انجام آپدیت هوشمند
        $updateResult = $this->performIntelligentUpdate($existingBook, $newData, $updateAnalysis);

        // 5. ثبت منبع جدید (یا آپدیت زمان کشف)
        if (!$sourceExists) {
            $this->recordBookSource($existingBook->id, $config->source_name, $sourceId);
        }

        $executionLog->addLogEntry("✨ کتاب موجود بهبود یافت", [
            'book_id' => $existingBook->id,
            'source_id' => $sourceId,
            'title' => $existingBook->title,
            'update_summary' => $updateResult['summary'],
            'fields_updated' => $updateResult['updated_fields'],
            'database_changed' => $updateResult['database_updated'],
            'was_reprocessing' => $sourceStatus['needs_reprocessing'] ?? false
        ]);

        return $this->buildResult($sourceId, $updateResult['action'], [
            'total_processed' => 1,
            'total_success' => 0,
            'total_failed' => 0,
            'total_duplicate' => 0,
            'total_enhanced' => 1
        ], $existingBook);
    }

    // باقی متدها همان‌طور که قبلاً بودند...

    /**
     * متد قدیمی برای backward compatibility
     */
    public function isSourceAlreadyProcessed(string $sourceName, int $sourceId): bool
    {
        $status = $this->checkSourceProcessingStatus($sourceName, $sourceId);
        return $status['should_skip'] && !$status['needs_reprocessing'];
    }

    /**
     * آنالیز نیاز به آپدیت کتاب موجود
     */
    private function analyzeUpdateNeeds(Book $existingBook, array $newData): array
    {
        $analysis = [
            'needs_update' => false,
            'empty_fields' => [],
            'improvable_fields' => [],
            'new_authors' => [],
            'new_isbns' => [],
            'new_hashes' => [],
            'new_images' => [],
            'total_improvements' => 0
        ];

        // بررسی فیلدهای خالی که می‌توانند پر شوند
        $analysis['empty_fields'] = $this->findEmptyFieldsToFill($existingBook, $newData);
        if (!empty($analysis['empty_fields'])) {
            $analysis['needs_update'] = true;
            $analysis['total_improvements'] += count($analysis['empty_fields']);
        }

        // بررسی فیلدهایی که می‌توانند بهبود یابند
        $analysis['improvable_fields'] = $this->findImprovableFields($existingBook, $newData);
        if (!empty($analysis['improvable_fields'])) {
            $analysis['needs_update'] = true;
            $analysis['total_improvements'] += count($analysis['improvable_fields']);
        }

        // بررسی نویسندگان جدید
        if (!empty($newData['author'])) {
            $analysis['new_authors'] = $this->findNewAuthors($existingBook, $newData['author']);
            if (!empty($analysis['new_authors'])) {
                $analysis['needs_update'] = true;
                $analysis['total_improvements'] += count($analysis['new_authors']);
            }
        }

        // بررسی ISBN های جدید
        if (!empty($newData['isbn'])) {
            $analysis['new_isbns'] = $this->findNewIsbns($existingBook, $newData['isbn']);
            if (!empty($analysis['new_isbns'])) {
                $analysis['needs_update'] = true;
                $analysis['total_improvements'] += count($analysis['new_isbns']);
            }
        }

        // بررسی هش‌های جدید
        $analysis['new_hashes'] = $this->findNewHashes($existingBook, $newData);
        if (!empty($analysis['new_hashes'])) {
            $analysis['needs_update'] = true;
            $analysis['total_improvements'] += count($analysis['new_hashes']);
        }

        // بررسی تصاویر جدید
        if (!empty($newData['image_url'])) {
            $analysis['new_images'] = $this->findNewImages($existingBook, $newData['image_url']);
            if (!empty($analysis['new_images'])) {
                $analysis['needs_update'] = true;
                $analysis['total_improvements'] += count($analysis['new_images']);
            }
        }

        Log::debug("🔍 آنالیز آپدیت کامل شد", [
            'book_id' => $existingBook->id,
            'needs_update' => $analysis['needs_update'],
            'total_improvements' => $analysis['total_improvements'],
            'improvement_types' => array_keys(array_filter([
                'empty_fields' => !empty($analysis['empty_fields']),
                'improvable_fields' => !empty($analysis['improvable_fields']),
                'new_authors' => !empty($analysis['new_authors']),
                'new_isbns' => !empty($analysis['new_isbns']),
                'new_hashes' => !empty($analysis['new_hashes']),
                'new_images' => !empty($analysis['new_images'])
            ]))
        ]);

        return $analysis;
    }

    /**
     * یافتن فیلدهای خالی که می‌توانند پر شوند
     */
    private function findEmptyFieldsToFill(Book $existingBook, array $newData): array
    {
        $emptyFields = [];
        $fieldsToCheck = [
            'description', 'publication_year', 'pages_count', 'file_size',
            'language', 'format', 'isbn', 'publisher', 'category'
        ];

        foreach ($fieldsToCheck as $field) {
            $currentValue = $this->getBookFieldValue($existingBook, $field);
            $newValue = $newData[$field] ?? null;

            if ($this->isFieldEmpty($currentValue) && !$this->isFieldEmpty($newValue)) {
                $emptyFields[$field] = [
                    'current' => $currentValue,
                    'new' => $newValue,
                    'reason' => 'field_is_empty'
                ];
            }
        }

        return $emptyFields;
    }

    /**
     * یافتن فیلدهایی که می‌توانند بهبود یابند
     */
    private function findImprovableFields(Book $existingBook, array $newData): array
    {
        $improvableFields = [];

        // بررسی توضیحات برای بهبود
        if (isset($newData['description'])) {
            $currentDesc = $existingBook->description;
            $newDesc = $newData['description'];

            if (!$this->isFieldEmpty($currentDesc) && !$this->isFieldEmpty($newDesc)) {
                if ($this->isDescriptionBetter($currentDesc, $newDesc)) {
                    $improvableFields['description'] = [
                        'current_length' => strlen($currentDesc),
                        'new_length' => strlen($newDesc),
                        'reason' => 'longer_description'
                    ];
                }
            }
        }

        // بررسی سال انتشار برای دقت بیشتر
        if (isset($newData['publication_year'])) {
            $currentYear = $existingBook->publication_year;
            $newYear = $newData['publication_year'];

            if (!$this->isFieldEmpty($currentYear) && !$this->isFieldEmpty($newYear)) {
                if ($this->isYearMoreAccurate($currentYear, $newYear)) {
                    $improvableFields['publication_year'] = [
                        'current' => $currentYear,
                        'new' => $newYear,
                        'reason' => 'more_accurate_year'
                    ];
                }
            }
        }

        // بررسی تعداد صفحات برای دقت بیشتر
        if (isset($newData['pages_count'])) {
            $currentPages = $existingBook->pages_count;
            $newPages = $newData['pages_count'];

            if (!$this->isFieldEmpty($currentPages) && !$this->isFieldEmpty($newPages)) {
                if (is_numeric($newPages) && $newPages > 0 && $newPages > $currentPages && $newPages <= 10000) {
                    $improvableFields['pages_count'] = [
                        'current' => $currentPages,
                        'new' => $newPages,
                        'reason' => 'higher_page_count'
                    ];
                }
            }
        }

        // بررسی اندازه فایل برای دقت بیشتر
        if (isset($newData['file_size'])) {
            $currentSize = $existingBook->file_size;
            $newSize = $newData['file_size'];

            if (!$this->isFieldEmpty($currentSize) && !$this->isFieldEmpty($newSize)) {
                if (is_numeric($newSize) && $newSize > $currentSize) {
                    $improvableFields['file_size'] = [
                        'current' => $currentSize,
                        'new' => $newSize,
                        'reason' => 'larger_file_size'
                    ];
                }
            }
        }

        return $improvableFields;
    }

    /**
     * انجام آپدیت هوشمند
     */
    private function performIntelligentUpdate(Book $book, array $newData, array $analysis): array
    {
        $changes = [];
        $databaseUpdated = false;
        $updatedFields = [];

        Log::info("🔄 شروع آپدیت هوشمند کتاب", [
            'book_id' => $book->id,
            'improvements_count' => $analysis['total_improvements']
        ]);

        // آپدیت فیلدهای خالی
        foreach ($analysis['empty_fields'] as $field => $info) {
            $this->setBookFieldValue($book, $field, $info['new']);
            $changes['filled_fields'][] = [
                'field' => $field,
                'old_value' => $info['current'],
                'new_value' => $info['new']
            ];
            $updatedFields[] = $field;
            $databaseUpdated = true;

            Log::debug("✅ فیلد خالی پر شد", [
                'book_id' => $book->id,
                'field' => $field,
                'new_value' => $this->truncateValue($info['new'])
            ]);
        }

        // آپدیت فیلدهای قابل بهبود
        foreach ($analysis['improvable_fields'] as $field => $info) {
            $this->setBookFieldValue($book, $field, $info['new'] ?? $newData[$field]);
            $changes['improved_fields'][] = [
                'field' => $field,
                'old_value' => $info['current'],
                'new_value' => $info['new'] ?? $newData[$field],
                'reason' => $info['reason']
            ];
            $updatedFields[] = $field;
            $databaseUpdated = true;

            Log::debug("⬆️ فیلد بهبود یافت", [
                'book_id' => $book->id,
                'field' => $field,
                'reason' => $info['reason']
            ]);
        }

        // اضافه کردن نویسندگان جدید
        if (!empty($analysis['new_authors'])) {
            $addedAuthors = $book->addAuthorsArray($analysis['new_authors']);
            if (!empty($addedAuthors)) {
                $changes['new_authors'] = $addedAuthors;
                Log::debug("👥 نویسندگان جدید اضافه شدند", [
                    'book_id' => $book->id,
                    'new_authors' => $addedAuthors
                ]);
            }
        }

        // اضافه کردن ISBN های جدید
        if (!empty($analysis['new_isbns'])) {
            $this->addNewIsbns($book, $analysis['new_isbns']);
            $changes['new_isbns'] = $analysis['new_isbns'];
            $databaseUpdated = true;
            Log::debug("📚 ISBN های جدید اضافه شدند", [
                'book_id' => $book->id,
                'new_isbns' => $analysis['new_isbns']
            ]);
        }

        // ذخیره تغییرات فیلدهای اصلی
        if ($databaseUpdated) {
            $book->save();
            Log::info("💾 تغییرات کتاب ذخیره شد", [
                'book_id' => $book->id,
                'updated_fields' => $updatedFields
            ]);
        }

        // آپدیت هش‌ها
        $hashResult = $this->updateBookHashes($book, $analysis['new_hashes']);
        if ($hashResult['updated']) {
            $changes['updated_hashes'] = $hashResult;
        }

        // آپدیت تصاویر
        $imageResult = $this->updateBookImages($book, $analysis['new_images']);
        if ($imageResult['updated']) {
            $changes['updated_images'] = $imageResult;
        }

        $action = $this->determineUpdateAction($changes);
        $summary = $this->generateUpdateSummary($changes);

        Log::info("✅ آپدیت هوشمند تمام شد", [
            'book_id' => $book->id,
            'action' => $action,
            'database_updated' => $databaseUpdated,
            'changes_count' => count($changes),
            'summary' => $summary
        ]);

        return [
            'database_updated' => $databaseUpdated,
            'changes' => $changes,
            'action' => $action,
            'summary' => $summary,
            'updated_fields' => $updatedFields
        ];
    }

    /**
     * تولید خلاصه تغییرات
     */
    private function generateUpdateSummary(array $changes): string
    {
        $summary = [];

        if (isset($changes['filled_fields'])) {
            $summary[] = count($changes['filled_fields']) . " فیلد خالی پر شد";
        }

        if (isset($changes['improved_fields'])) {
            $summary[] = count($changes['improved_fields']) . " فیلد بهبود یافت";
        }

        if (isset($changes['new_authors'])) {
            $summary[] = count($changes['new_authors']) . " نویسنده جدید";
        }

        if (isset($changes['new_isbns'])) {
            $summary[] = count($changes['new_isbns']) . " ISBN جدید";
        }

        if (isset($changes['updated_hashes']['added_hashes'])) {
            $summary[] = count($changes['updated_hashes']['added_hashes']) . " هش جدید";
        }

        if (isset($changes['updated_images'])) {
            $summary[] = "تصویر جدید";
        }

        return !empty($summary) ? implode(', ', $summary) : 'بدون تغییر';
    }

    private function findNewAuthors(Book $book, string $newAuthorsString): array
    {
        if (empty($newAuthorsString)) {
            return [];
        }

        $existingAuthors = $book->authors()->pluck('name')->map(function($name) {
            return strtolower(trim($name));
        })->toArray();

        $newAuthors = $this->parseAuthors($newAuthorsString);
        $uniqueNewAuthors = [];

        foreach ($newAuthors as $author) {
            $normalizedAuthor = strtolower(trim($author));
            if (!in_array($normalizedAuthor, $existingAuthors) && strlen(trim($author)) >= 2) {
                $uniqueNewAuthors[] = trim($author);
            }
        }

        return $uniqueNewAuthors;
    }

    private function findNewIsbns(Book $book, string $newIsbnString): array
    {
        if (empty($newIsbnString)) {
            return [];
        }

        $existingIsbns = $book->isbn ? array_map('trim', explode(',', $book->isbn)) : [];
        $existingIsbnsCleaned = array_map(function($isbn) {
            return preg_replace('/[^0-9X]/i', '', $isbn);
        }, $existingIsbns);

        $newIsbns = array_map('trim', explode(',', $newIsbnString));
        $uniqueNewIsbns = [];

        foreach ($newIsbns as $isbn) {
            $cleanedIsbn = preg_replace('/[^0-9X]/i', '', $isbn);
            if (!in_array($cleanedIsbn, $existingIsbnsCleaned) && strlen($cleanedIsbn) >= 10) {
                $uniqueNewIsbns[] = trim($isbn);
            }
        }

        return $uniqueNewIsbns;
    }

    private function findNewHashes(Book $book, array $newData): array
    {
        if (!$book->hashes) {
            return [];
        }

        $newHashes = [];
        $hashFields = ['sha1', 'sha256', 'crc32', 'ed2k', 'btih', 'magnet'];

        foreach ($hashFields as $field) {
            if (!empty($newData[$field])) {
                $dbField = $field === 'ed2k' ? 'ed2k_hash' : ($field === 'magnet' ? 'magnet_link' : $field);

                if (empty($book->hashes->$dbField)) {
                    $newHashes[$field] = $newData[$field];
                }
            }
        }

        return $newHashes;
    }

    private function findNewImages(Book $book, string $imageUrl): array
    {
        if (empty($imageUrl) || !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            return [];
        }

        $imageExists = $book->images()->where('image_url', $imageUrl)->exists();

        return $imageExists ? [] : [$imageUrl];
    }

    private function isFieldEmpty($value): bool
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

    private function isDescriptionBetter($current, $new): bool
    {
        $currentLength = strlen(trim($current ?? ''));
        $newLength = strlen(trim($new ?? ''));

        // توضیحات جدید باید حداقل 50% طولانی‌تر باشد
        return $newLength > ($currentLength * 1.5);
    }

    private function isYearMoreAccurate($current, $new): bool
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

    private function addNewIsbns(Book $book, array $newIsbns): void
    {
        $currentIsbns = $book->isbn ? array_map('trim', explode(',', $book->isbn)) : [];
        $allIsbns = array_unique(array_merge($currentIsbns, $newIsbns));
        $book->isbn = implode(', ', $allIsbns);
    }

    private function parseAuthors(string $authorsString): array
    {
        $separators = [',', '،', ';', '؛', '&', 'and', 'و'];

        foreach ($separators as $separator) {
            $authorsString = str_ireplace($separator, ',', $authorsString);
        }

        return array_filter(array_map('trim', explode(',', $authorsString)));
    }

    private function getBookFieldValue(Book $book, string $field)
    {
        switch ($field) {
            case 'category':
                return $book->category?->name;
            case 'publisher':
                return $book->publisher?->name;
            default:
                return $book->$field;
        }
    }

    private function setBookFieldValue(Book $book, string $field, $value): void
    {
        switch ($field) {
            case 'category':
                // اگر دسته‌بندی جدید باشد، ایجاد کن
                if (!empty($value)) {
                    $category = \App\Models\Category::firstOrCreate(
                        ['name' => $value],
                        ['slug' => \Illuminate\Support\Str::slug($value . '_' . time()), 'is_active' => true]
                    );
                    $book->category_id = $category->id;
                }
                break;
            case 'publisher':
                // اگر ناشر جدید باشد، ایجاد کن
                if (!empty($value)) {
                    $publisher = \App\Models\Publisher::firstOrCreate(
                        ['name' => $value],
                        ['slug' => \Illuminate\Support\Str::slug($value . '_' . time()), 'is_active' => true]
                    );
                    $book->publisher_id = $publisher->id;
                }
                break;
            default:
                $book->$field = $value;
                break;
        }
    }

    private function truncateValue($value, int $length = 100): string
    {
        if (is_string($value) && strlen($value) > $length) {
            return substr($value, 0, $length) . '...';
        }
        return (string)$value;
    }

    private function determineUpdateAction(array $changes): string
    {
        if (empty($changes)) {
            return 'no_changes';
        }

        if (isset($changes['filled_fields']) && !empty($changes['filled_fields'])) {
            return 'enhanced';
        }

        if (isset($changes['improved_fields']) && !empty($changes['improved_fields'])) {
            return 'enriched';
        }

        if (isset($changes['new_authors']) || isset($changes['new_isbns'])) {
            return 'merged';
        }

        return 'updated';
    }

    private function handleNewBook(array $data, string $md5, int $sourceId, Config $config, ExecutionLog $executionLog): array
    {
        $hashData = $this->extractAllHashes($data, $md5);

        Log::info("📦 ایجاد کتاب جدید", [
            'source_id' => $sourceId,
            'title' => $data['title'],
            'hash_data' => array_keys($hashData)
        ]);

        $book = Book::createWithDetails(
            $data,
            $hashData,
            $config->source_name,
            (string)$sourceId
        );

        $executionLog->addLogEntry("✨ کتاب جدید ایجاد شد", [
            'source_id' => $sourceId,
            'book_id' => $book->id,
            'title' => $book->title,
            'md5' => $md5
        ]);

        return $this->buildResult($sourceId, 'created', [
            'total_processed' => 1,
            'total_success' => 1,
            'total_failed' => 0,
            'total_duplicate' => 0,
            'total_enhanced' => 0
        ], $book);
    }

    private function extractAllHashes(array $data, string $md5): array
    {
        $hashData = ['md5' => $md5];
        $hashFields = [
            'sha1' => 'sha1', 'sha256' => 'sha256', 'crc32' => 'crc32',
            'ed2k' => 'ed2k', 'ed2k_hash' => 'ed2k',
            'btih' => 'btih', 'magnet' => 'magnet', 'magnet_link' => 'magnet'
        ];

        foreach ($hashFields as $sourceKey => $targetKey) {
            if (!empty($data[$sourceKey])) {
                $hashValue = trim($data[$sourceKey]);
                if ($this->isValidHash($hashValue, $targetKey)) {
                    $hashData[$targetKey] = $hashValue;
                }
            }
        }

        return $hashData;
    }

    private function updateBookHashes(Book $book, array $newHashes): array
    {
        if (!$book->hashes || empty($newHashes)) {
            return ['updated' => false];
        }

        $updates = [];
        foreach ($newHashes as $field => $value) {
            $dbField = $field === 'ed2k' ? 'ed2k_hash' : ($field === 'magnet' ? 'magnet_link' : $field);
            $updates[$dbField] = $value;
        }

        if (!empty($updates)) {
            $book->hashes->update($updates);
            return ['updated' => true, 'added_hashes' => array_keys($newHashes)];
        }

        return ['updated' => false];
    }

    private function updateBookImages(Book $book, array $newImages): array
    {
        if (empty($newImages)) {
            return ['updated' => false];
        }

        foreach ($newImages as $imageUrl) {
            $book->images()->create(['image_url' => $imageUrl]);
        }

        return ['updated' => true, 'added_images' => count($newImages)];
    }

    private function isValidHash(string $hash, string $type): bool
    {
        $hash = trim($hash);
        if (empty($hash)) return false;

        switch ($type) {
            case 'md5': return preg_match('/^[a-f0-9]{32}$/i', $hash);
            case 'sha1': return preg_match('/^[a-f0-9]{40}$/i', $hash);
            case 'sha256': return preg_match('/^[a-f0-9]{64}$/i', $hash);
            case 'crc32': return preg_match('/^[a-f0-9]{8}$/i', $hash);
            case 'ed2k': return preg_match('/^[a-f0-9]{32}$/i', $hash);
            case 'btih': return preg_match('/^[a-f0-9]{40}$/i', $hash);
            case 'magnet': return str_starts_with(strtolower($hash), 'magnet:?xt=');
            default: return !empty($hash);
        }
    }

    private function extractHashesFromData(array $data): array
    {
        $hashes = [];
        $hashFields = ['md5', 'sha1', 'sha256', 'crc32', 'ed2k', 'btih', 'magnet'];

        foreach ($hashFields as $field) {
            if (!empty($data[$field])) {
                $hashes[$field] = substr($data[$field], 0, 16) . '...';
            }
        }

        return $hashes;
    }

    private function recordBookSource(int $bookId, string $sourceName, int $sourceId): void
    {
        BookSource::recordBookSource($bookId, $sourceName, (string)$sourceId);
    }

    private function buildResult(int $sourceId, string $action, array $stats, ?Book $book = null): array
    {
        $result = [
            'source_id' => $sourceId,
            'action' => $action,
            'stats' => $stats
        ];

        if ($book) {
            $result['book_id'] = $book->id;
            $result['title'] = $book->title;
        }

        return $result;
    }

    private function buildFailureResult(int $sourceId): array
    {
        return [
            'source_id' => $sourceId,
            'action' => 'failed',
            'stats' => [
                'total_processed' => 1,
                'total_success' => 0,
                'total_failed' => 1,
                'total_duplicate' => 0,
                'total_enhanced' => 0
            ]
        ];
    }
}
