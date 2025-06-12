<?php

namespace App\Services;

use App\Models\Book;
use App\Models\BookSource;
use App\Models\Config;
use App\Models\ExecutionLog;
use Illuminate\Support\Facades\Log;

class BookProcessor
{
    public function __construct(
        private FieldExtractor $fieldExtractor,
        private DataValidator $dataValidator
    ) {}

    public function processBook(
        array $bookData,
        int $sourceId,
        Config $config,
        ExecutionLog $executionLog,
        array $sourceStatus = null
    ): array {
        try {
            // استخراج و اعتبارسنجی داده‌ها
            $extractedData = $this->fieldExtractor->extractFields($bookData, $config);

            if (empty($extractedData['title'])) {
                Log::warning("عنوان کتاب استخراج نشد", ['source_id' => $sourceId]);
                return $this->buildFailureResult($sourceId);
            }

            $cleanedData = $this->dataValidator->cleanAndValidate($extractedData);
            $md5 = Book::calculateContentMd5($cleanedData);

            $executionLog->addLogEntry("🔐 محاسبه MD5", [
                'source_id' => $sourceId,
                'md5' => $md5,
                'title' => $cleanedData['title']
            ]);

            // بررسی وجود کتاب
            $existingBook = Book::findByMd5($md5);

            if ($existingBook) {
                return $this->handleExistingBook(
                    $existingBook,
                    $cleanedData,
                    $sourceId,
                    $config,
                    $executionLog,
                    $sourceStatus
                );
            } else {
                return $this->handleNewBook($cleanedData, $md5, $sourceId, $config, $executionLog);
            }

        } catch (\Exception $e) {
            Log::error("❌ خطا در پردازش کتاب", [
                'source_id' => $sourceId,
                'error' => $e->getMessage()
            ]);
            return $this->buildFailureResult($sourceId);
        }
    }

    public function checkSourceProcessingStatus(string $sourceName, int $sourceId, Config $config = null): array
    {
        $existingSource = BookSource::where('source_name', $sourceName)
            ->where('source_id', (string)$sourceId)
            ->with('book')
            ->first();

        if (!$existingSource) {
            return [
                'should_skip' => false,
                'needs_reprocessing' => false,
                'reason' => 'new_source',
                'action' => 'process_new'
            ];
        }

        $book = $existingSource->book;
        if (!$book) {
            return [
                'should_skip' => false,
                'needs_reprocessing' => true,
                'reason' => 'book_not_found',
                'action' => 'reprocess'
            ];
        }

        // بررسی نیاز به آپدیت
        $needsUpdate = $this->shouldUpdateBook($book, $config);

        if ($needsUpdate) {
            return [
                'should_skip' => false,
                'needs_reprocessing' => true,
                'reason' => 'needs_update',
                'action' => 'reprocess_for_update',
                'book_id' => $book->id
            ];
        }

        return [
            'should_skip' => true,
            'needs_reprocessing' => false,
            'reason' => 'book_already_complete',
            'action' => 'already_processed',
            'book_id' => $book->id
        ];
    }

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
            'source_id' => $sourceId
        ]);

        // بررسی وجود منبع
        $sourceExists = BookSource::where('book_id', $existingBook->id)
            ->where('source_name', $config->source_name)
            ->where('source_id', (string)$sourceId)
            ->exists();

        if ($sourceExists && !($sourceStatus['needs_reprocessing'] ?? false)) {
            $executionLog->addLogEntry("⏭️ Source قبلاً ثبت شده", [
                'book_id' => $existingBook->id,
                'source_id' => $sourceId
            ]);

            return $this->buildResult($sourceId, 'already_processed', [
                'total_processed' => 1,
                'total_success' => 0,
                'total_failed' => 0,
                'total_duplicate' => 1,
                'total_enhanced' => 0
            ], $existingBook);
        }

        // بررسی نیاز به آپدیت
        $updateResult = $this->updateBookIfNeeded($existingBook, $newData);

        // ثبت/آپدیت منبع
        if (!$sourceExists) {
            BookSource::recordBookSource($existingBook->id, $config->source_name, (string)$sourceId);
        } else {
            BookSource::where('book_id', $existingBook->id)
                ->where('source_name', $config->source_name)
                ->where('source_id', (string)$sourceId)
                ->update(['discovered_at' => now()]);
        }

        $action = $updateResult['updated'] ? 'enhanced' : 'source_added';
        $enhanced = $updateResult['updated'] ? 1 : 0;

        $executionLog->addLogEntry("✨ کتاب موجود پردازش شد", [
            'book_id' => $existingBook->id,
            'source_id' => $sourceId,
            'updated' => $updateResult['updated'],
            'changes' => $updateResult['changes'] ?? []
        ]);

        return $this->buildResult($sourceId, $action, [
            'total_processed' => 1,
            'total_success' => 0,
            'total_failed' => 0,
            'total_duplicate' => $enhanced ? 0 : 1,
            'total_enhanced' => $enhanced
        ], $existingBook);
    }

    private function handleNewBook(array $data, string $md5, int $sourceId, Config $config, ExecutionLog $executionLog): array
    {
        $hashData = $this->extractAllHashes($data, $md5);

        Log::info("📦 ایجاد کتاب جدید", [
            'source_id' => $sourceId,
            'title' => $data['title']
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
            'title' => $book->title
        ]);

        return $this->buildResult($sourceId, 'created', [
            'total_processed' => 1,
            'total_success' => 1,
            'total_failed' => 0,
            'total_duplicate' => 0,
            'total_enhanced' => 0
        ], $book);
    }

    private function shouldUpdateBook(Book $book, Config $config = null): bool
    {
        // بررسی force update
        if ($config && $this->shouldForceUpdate($config)) {
            return true;
        }

        // بررسی فیلدهای خالی مهم
        $emptyFields = 0;
        if (empty($book->description)) $emptyFields++;
        if (empty($book->publication_year)) $emptyFields++;
        if (empty($book->pages_count)) $emptyFields++;
        if (empty($book->isbn)) $emptyFields++;
        if (!$book->hashes || empty($book->hashes->md5)) $emptyFields++;
        if ($book->authors()->count() === 0) $emptyFields++;

        return $emptyFields >= 2; // اگر 2 یا بیشتر فیلد مهم خالی باشد
    }

    private function shouldForceUpdate(Config $config): bool
    {
        $generalSettings = $config->getGeneralSettings();
        return !empty($generalSettings['force_reprocess']) ||
            $config->fill_missing_fields ||
            $config->update_descriptions;
    }

    private function updateBookIfNeeded(Book $book, array $newData): array
    {
        $changes = [];
        $updated = false;

        // آپدیت فیلدهای خالی
        $fieldsToUpdate = [
            'description', 'publication_year', 'pages_count',
            'file_size', 'language', 'format', 'isbn'
        ];

        foreach ($fieldsToUpdate as $field) {
            if (empty($book->$field) && !empty($newData[$field])) {
                $book->$field = $newData[$field];
                $changes[] = $field;
                $updated = true;
            }
        }

        // آپدیت ناشر و دسته‌بندی
        if (empty($book->publisher_id) && !empty($newData['publisher'])) {
            $publisher = \App\Models\Publisher::firstOrCreate(
                ['name' => $newData['publisher']],
                ['slug' => \Illuminate\Support\Str::slug($newData['publisher'] . '_' . time()), 'is_active' => true]
            );
            $book->publisher_id = $publisher->id;
            $changes[] = 'publisher';
            $updated = true;
        }

        if (empty($book->category_id) && !empty($newData['category'])) {
            $category = \App\Models\Category::firstOrCreate(
                ['name' => $newData['category']],
                ['slug' => \Illuminate\Support\Str::slug($newData['category'] . '_' . time()), 'is_active' => true]
            );
            $book->category_id = $category->id;
            $changes[] = 'category';
            $updated = true;
        }

        // آپدیت نویسندگان جدید
        if (!empty($newData['author'])) {
            $newAuthors = $this->findNewAuthors($book, $newData['author']);
            if (!empty($newAuthors)) {
                $addedAuthors = $book->addAuthorsArray($newAuthors);
                if (!empty($addedAuthors)) {
                    $changes[] = 'authors';
                    $updated = true;
                }
            }
        }

        // آپدیت هش‌ها
        if ($book->hashes) {
            $hashUpdated = $this->updateBookHashes($book, $newData);
            if ($hashUpdated) {
                $changes[] = 'hashes';
                $updated = true;
            }
        }

        // ذخیره تغییرات
        if ($updated) {
            $book->save();
            Log::info("📝 کتاب آپدیت شد", [
                'book_id' => $book->id,
                'changes' => $changes
            ]);
        }

        return [
            'updated' => $updated,
            'changes' => $changes
        ];
    }

    private function findNewAuthors(Book $book, string $newAuthorsString): array
    {
        if (empty($newAuthorsString)) return [];

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

    private function parseAuthors(string $authorsString): array
    {
        $separators = [',', '،', ';', '؛', '&', 'and', 'و'];
        foreach ($separators as $separator) {
            $authorsString = str_ireplace($separator, ',', $authorsString);
        }
        return array_filter(array_map('trim', explode(',', $authorsString)));
    }

    private function updateBookHashes(Book $book, array $newData): bool
    {
        $hashFields = ['sha1', 'sha256', 'crc32', 'ed2k', 'btih'];
        $updates = [];

        foreach ($hashFields as $field) {
            $dbField = $field === 'ed2k' ? 'ed2k_hash' : $field;
            if (empty($book->hashes->$dbField) && !empty($newData[$field])) {
                $updates[$dbField] = $newData[$field];
            }
        }

        // مگنت لینک
        if (empty($book->hashes->magnet_link) && !empty($newData['magnet'])) {
            $updates['magnet_link'] = $newData['magnet'];
        }

        if (!empty($updates)) {
            $book->hashes->update($updates);
            return true;
        }

        return false;
    }

    private function extractAllHashes(array $data, string $md5): array
    {
        $hashData = ['md5' => $md5];
        $hashFields = [
            'sha1' => 'sha1', 'sha256' => 'sha256', 'crc32' => 'crc32',
            'ed2k' => 'ed2k', 'btih' => 'btih', 'magnet' => 'magnet'
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

    private function isValidHash(string $hash, string $type): bool
    {
        $hash = trim($hash);
        if (empty($hash)) return false;

        return match ($type) {
            'sha1' => preg_match('/^[a-f0-9]{40}$/i', $hash),
            'sha256' => preg_match('/^[a-f0-9]{64}$/i', $hash),
            'crc32' => preg_match('/^[a-f0-9]{8}$/i', $hash),
            'ed2k' => preg_match('/^[a-f0-9]{32}$/i', $hash),
            'btih' => preg_match('/^[a-f0-9]{40}$/i', $hash),
            'magnet' => str_starts_with(strtolower($hash), 'magnet:?xt='),
            default => !empty($hash)
        };
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

    // متد قدیمی برای backward compatibility
    public function isSourceAlreadyProcessed(string $sourceName, int $sourceId): bool
    {
        $status = $this->checkSourceProcessingStatus($sourceName, $sourceId);
        return $status['should_skip'] && !$status['needs_reprocessing'];
    }
}
