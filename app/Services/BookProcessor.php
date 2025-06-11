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

    public function processBook(array $bookData, int $sourceId, Config $config, ExecutionLog $executionLog): array
    {
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
                'extracted_hashes' => $this->extractHashesFromData($cleanedData)
            ]);

            // بررسی وجود کتاب با MD5
            $existingBook = Book::findByMd5($md5);

            if ($existingBook) {
                // کتاب موجود - اجرای منطق به‌روزرسانی هوشمند
                return $this->handleExistingBook($existingBook, $cleanedData, $sourceId, $config, $executionLog);
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
     * پردازش کتاب موجود - منطق هوشمند به‌روزرسانی
     */
    private function handleExistingBook(Book $existingBook, array $newData, int $sourceId, Config $config, ExecutionLog $executionLog): array
    {
        // 1. بررسی وجود منبع قبلی
        $sourceExists = BookSource::where('book_id', $existingBook->id)
            ->where('source_name', $config->source_name)
            ->where('source_id', (string)$sourceId)
            ->exists();

        if ($sourceExists) {
            // این source قبلاً ثبت شده - نیازی به پردازش مجدد نیست
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

        // 2. مقایسه فیلدها و تصمیم‌گیری برای به‌روزرسانی
        $comparisonResult = $this->compareBookFields($existingBook, $newData);

        $executionLog->addLogEntry("📊 مقایسه فیلدهای کتاب", [
            'book_id' => $existingBook->id,
            'source_id' => $sourceId,
            'title' => $existingBook->title,
            'fields_identical' => $comparisonResult['all_identical'],
            'missing_fields' => $comparisonResult['missing_fields'],
            'better_fields' => $comparisonResult['better_fields'],
            'new_authors' => $comparisonResult['new_authors'],
            'new_isbns' => $comparisonResult['new_isbns']
        ]);

        // 3. اگر همه فیلدها یکسان هستند
        if ($comparisonResult['all_identical']) {
            // فقط منبع جدید اضافه کن
            $this->recordBookSource($existingBook->id, $config->source_name, $sourceId);

            $executionLog->addLogEntry("🔄 کتاب یکسان، فقط منبع جدید اضافه شد", [
                'book_id' => $existingBook->id,
                'source_id' => $sourceId,
                'action' => 'source_only_added'
            ]);

            return $this->buildResult($sourceId, 'source_added', [
                'total_processed' => 1,
                'total_success' => 0,
                'total_failed' => 0,
                'total_duplicate' => 1,
                'total_enhanced' => 0
            ], $existingBook);
        }

        // 4. اگر نیاز به به‌روزرسانی هست
        $updateResult = $this->performSmartUpdate($existingBook, $newData, $comparisonResult);

        // 5. ثبت منبع جدید
        $this->recordBookSource($existingBook->id, $config->source_name, $sourceId);

        $executionLog->addLogEntry("✨ کتاب موجود بهبود یافت", [
            'book_id' => $existingBook->id,
            'source_id' => $sourceId,
            'title' => $existingBook->title,
            'update_action' => $updateResult['action'],
            'changes_count' => count($updateResult['changes']),
            'database_updated' => $updateResult['updated']
        ]);

        return $this->buildResult($sourceId, $updateResult['action'], [
            'total_processed' => 1,
            'total_success' => 0,
            'total_failed' => 0,
            'total_duplicate' => 0,
            'total_enhanced' => 1
        ], $existingBook);
    }

    /**
     * پردازش کتاب جدید
     */
    private function handleNewBook(array $data, string $md5, int $sourceId, Config $config, ExecutionLog $executionLog): array
    {
        // استخراج کامل هش‌ها
        $hashData = $this->extractAllHashes($data, $md5);

        Log::info("📦 ایجاد کتاب جدید با هش‌ها", [
            'source_id' => $sourceId,
            'title' => $data['title'],
            'hash_data' => $hashData
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
            'md5' => $md5,
            'all_hashes' => $hashData
        ]);

        return $this->buildResult($sourceId, 'created', [
            'total_processed' => 1,
            'total_success' => 1,
            'total_failed' => 0,
            'total_duplicate' => 0,
            'total_enhanced' => 0
        ], $book);
    }

    /**
     * مقایسه دقیق فیلدهای کتاب
     */
    private function compareBookFields(Book $existingBook, array $newData): array
    {
        $result = [
            'all_identical' => true,
            'missing_fields' => [],
            'better_fields' => [],
            'new_authors' => [],
            'new_isbns' => [],
            'identical_fields' => [],
            'different_fields' => []
        ];

        // فیلدهای اصلی برای مقایسه
        $fieldsToCompare = [
            'title', 'description', 'publication_year', 'pages_count',
            'language', 'format', 'file_size', 'category', 'publisher'
        ];

        foreach ($fieldsToCompare as $field) {
            $existingValue = $this->getBookFieldValue($existingBook, $field);
            $newValue = $newData[$field] ?? null;

            if ($this->isFieldEmpty($existingValue) && !$this->isFieldEmpty($newValue)) {
                // فیلد در دیتابیس خالی است اما داده جدید دارد
                $result['missing_fields'][] = $field;
                $result['all_identical'] = false;
            } elseif ($this->isNewFieldBetter($existingValue, $newValue, $field)) {
                // داده جدید بهتر یا کامل‌تر است
                $result['better_fields'][] = $field;
                $result['all_identical'] = false;
            } elseif ($this->normalizeForComparison($existingValue) === $this->normalizeForComparison($newValue)) {
                $result['identical_fields'][] = $field;
            } else {
                $result['different_fields'][] = [
                    'field' => $field,
                    'existing' => $existingValue,
                    'new' => $newValue
                ];
            }
        }

        // بررسی نویسندگان جدید
        if (!empty($newData['author'])) {
            $newAuthors = $this->findNewAuthors($existingBook, $newData['author']);
            if (!empty($newAuthors)) {
                $result['new_authors'] = $newAuthors;
                $result['all_identical'] = false;
            }
        }

        // بررسی ISBN های جدید
        if (!empty($newData['isbn'])) {
            $newIsbns = $this->findNewIsbns($existingBook, $newData['isbn']);
            if (!empty($newIsbns)) {
                $result['new_isbns'] = $newIsbns;
                $result['all_identical'] = false;
            }
        }

        return $result;
    }

    /**
     * انجام به‌روزرسانی هوشمند
     */
    private function performSmartUpdate(Book $book, array $newData, array $comparisonResult): array
    {
        $changes = [];
        $updated = false;

        // به‌روزرسانی فیلدهای خالی
        foreach ($comparisonResult['missing_fields'] as $field) {
            $newValue = $newData[$field];
            $this->setBookFieldValue($book, $field, $newValue);
            $changes['filled_fields'][] = [
                'field' => $field,
                'old_value' => null,
                'new_value' => $newValue
            ];
            $updated = true;
        }

        // به‌روزرسانی فیلدهای بهتر
        foreach ($comparisonResult['better_fields'] as $field) {
            $oldValue = $this->getBookFieldValue($book, $field);
            $newValue = $newData[$field];
            $this->setBookFieldValue($book, $field, $newValue);
            $changes['improved_fields'][] = [
                'field' => $field,
                'old_value' => $oldValue,
                'new_value' => $newValue
            ];
            $updated = true;
        }

        // اضافه کردن نویسندگان جدید
        if (!empty($comparisonResult['new_authors'])) {
            $addedAuthors = $book->addAuthorsWithTimestamps(implode(', ', $comparisonResult['new_authors']));
            if (!empty($addedAuthors)) {
                $changes['new_authors'] = $addedAuthors;
            }
        }

        // اضافه کردن ISBN های جدید
        if (!empty($comparisonResult['new_isbns'])) {
            $currentIsbns = $book->isbn ? explode(',', $book->isbn) : [];
            $allIsbns = array_unique(array_merge($currentIsbns, $comparisonResult['new_isbns']));
            $book->isbn = implode(', ', array_map('trim', $allIsbns));
            $changes['new_isbns'] = $comparisonResult['new_isbns'];
            $updated = true;
        }

        // ذخیره تغییرات
        if ($updated) {
            $book->save();
        }

        // به‌روزرسانی هش‌ها
        $hashResult = $this->updateBookHashes($book, $newData);
        if ($hashResult['updated']) {
            $changes['updated_hashes'] = $hashResult;
        }

        // به‌روزرسانی تصاویر
        if (!empty($newData['image_url'])) {
            $imageResult = $this->updateBookImages($book, $newData['image_url']);
            if ($imageResult['updated']) {
                $changes['updated_images'] = $imageResult;
            }
        }

        $action = $this->determineUpdateAction($changes);

        return [
            'updated' => $updated,
            'changes' => $changes,
            'action' => $action
        ];
    }

    /**
     * یافتن نویسندگان جدید
     */
    private function findNewAuthors(Book $book, string $newAuthorsString): array
    {
        if (empty($newAuthorsString)) {
            return [];
        }

        // دریافت نویسندگان موجود
        $existingAuthors = $book->authors()->pluck('name')->map(function($name) {
            return strtolower(trim($name));
        })->toArray();

        // پارس کردن نویسندگان جدید
        $newAuthors = $this->parseAuthors($newAuthorsString);
        $newAuthorsList = [];

        foreach ($newAuthors as $author) {
            $normalizedAuthor = strtolower(trim($author));
            if (!in_array($normalizedAuthor, $existingAuthors) && strlen(trim($author)) >= 2) {
                $newAuthorsList[] = trim($author);
            }
        }

        return $newAuthorsList;
    }

    /**
     * یافتن ISBN های جدید
     */
    private function findNewIsbns(Book $book, string $newIsbnString): array
    {
        if (empty($newIsbnString)) {
            return [];
        }

        // دریافت ISBN های موجود
        $existingIsbns = $book->isbn ? array_map('trim', explode(',', $book->isbn)) : [];
        $existingIsbnsCleaned = array_map(function($isbn) {
            return preg_replace('/[^0-9X]/i', '', $isbn);
        }, $existingIsbns);

        // پارس کردن ISBN های جدید
        $newIsbns = array_map('trim', explode(',', $newIsbnString));
        $newIsbnsList = [];

        foreach ($newIsbns as $isbn) {
            $cleanedIsbn = preg_replace('/[^0-9X]/i', '', $isbn);
            if (!in_array($cleanedIsbn, $existingIsbnsCleaned) && strlen($cleanedIsbn) >= 10) {
                $newIsbnsList[] = trim($isbn);
            }
        }

        return $newIsbnsList;
    }

    /**
     * پارس کردن نویسندگان
     */
    private function parseAuthors(string $authorsString): array
    {
        $separators = [',', '،', ';', '؛', '&', 'and', 'و'];

        foreach ($separators as $separator) {
            $authorsString = str_ireplace($separator, ',', $authorsString);
        }

        return array_filter(array_map('trim', explode(',', $authorsString)));
    }

    /**
     * بررسی خالی بودن فیلد
     */
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

    /**
     * بررسی بهتر بودن داده جدید
     */
    private function isNewFieldBetter($existingValue, $newValue, string $fieldType): bool
    {
        if ($this->isFieldEmpty($newValue)) {
            return false;
        }

        if ($this->isFieldEmpty($existingValue)) {
            return true;
        }

        switch ($fieldType) {
            case 'description':
                return strlen(trim($newValue)) > strlen(trim($existingValue)) * 1.2;

            case 'pages_count':
                return is_numeric($newValue) && is_numeric($existingValue) &&
                    $newValue > $existingValue && $newValue <= 10000;

            case 'file_size':
                return is_numeric($newValue) && is_numeric($existingValue) &&
                    $newValue > $existingValue;

            case 'publication_year':
                $currentYear = date('Y');
                return is_numeric($newValue) && $newValue >= 1000 &&
                    $newValue <= $currentYear &&
                    (abs($newValue - $currentYear) < abs($existingValue - $currentYear));

            default:
                return false;
        }
    }

    /**
     * نرمال‌سازی برای مقایسه
     */
    private function normalizeForComparison($value): string
    {
        if ($value === null) {
            return '';
        }

        return strtolower(trim((string)$value));
    }

    /**
     * دریافت مقدار فیلد کتاب
     */
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

    /**
     * تنظیم مقدار فیلد کتاب
     */
    private function setBookFieldValue(Book $book, string $field, $value): void
    {
        switch ($field) {
            case 'category':
                // منطق تنظیم دسته‌بندی
                break;
            case 'publisher':
                // منطق تنظیم ناشر
                break;
            default:
                $book->$field = $value;
                break;
        }
    }

    /**
     * تشخیص نوع عملیات به‌روزرسانی
     */
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

    public function isSourceAlreadyProcessed(string $sourceName, int $sourceId): bool
    {
        return BookSource::where('source_name', $sourceName)
            ->where('source_id', (string)$sourceId)
            ->exists();
    }

    // متدهای کمکی که قبلاً وجود داشتند...

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

    private function updateBookHashes(Book $book, array $data): array
    {
        if (!$book->hashes) {
            return ['updated' => false, 'reason' => 'no_hash_record'];
        }

        $hashFields = ['sha1', 'sha256', 'crc32', 'ed2k', 'btih', 'magnet'];
        $updates = [];
        $addedHashes = [];

        foreach ($hashFields as $field) {
            $dbField = $field === 'ed2k' ? 'ed2k_hash' : ($field === 'magnet' ? 'magnet_link' : $field);

            if (!empty($data[$field]) && empty($book->hashes->$dbField)) {
                $updates[$dbField] = $data[$field];
                $addedHashes[] = $field;
            }
        }

        if (!empty($updates)) {
            $book->hashes->update($updates);
            return ['updated' => true, 'added_hashes' => $addedHashes, 'updates' => $updates];
        }

        return ['updated' => false, 'reason' => 'no_new_hashes'];
    }

    private function updateBookImages(Book $book, string $imageUrl): array
    {
        if (empty($imageUrl) || !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            return ['updated' => false, 'reason' => 'invalid_url'];
        }

        if (!$book->images()->where('image_url', $imageUrl)->exists()) {
            $book->images()->create(['image_url' => $imageUrl]);
            return ['updated' => true, 'action' => 'added_new_image', 'image_url' => $imageUrl];
        }

        return ['updated' => false, 'reason' => 'image_exists'];
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
                $hashes[$field] = substr($data[$field], 0, 16) . (strlen($data[$field]) > 16 ? '...' : '');
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
