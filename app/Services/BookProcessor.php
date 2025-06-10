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

            // محاسبه MD5 برای شناسایی تکراری
            $md5 = Book::calculateContentMd5($cleanedData);

            $executionLog->addLogEntry("🔐 محاسبه MD5 برای source ID {$sourceId}", [
                'source_id' => $sourceId,
                'md5' => $md5,
                'title' => $cleanedData['title']
            ]);

            // بررسی وجود کتاب
            $existingBook = Book::findByMd5($md5);

            if ($existingBook) {
                // کتاب موجود - بروزرسانی هوشمند
                $result = $this->updateExistingBook($existingBook, $cleanedData, $sourceId, $config, $executionLog);
                $this->recordBookSource($existingBook->id, $config->source_name, $sourceId);

                return $this->buildUpdateResult($sourceId, $result, $existingBook);
            } else {
                // کتاب جدید
                $book = $this->createNewBook($cleanedData, $md5, $sourceId, $config, $executionLog);

                return $this->buildSuccessResult($sourceId, $book);
            }

        } catch (\Exception $e) {
            Log::error("❌ خطا در پردازش کتاب", [
                'source_id' => $sourceId,
                'error' => $e->getMessage()
            ]);

            return $this->buildFailureResult($sourceId);
        }
    }

    public function isSourceAlreadyProcessed(string $sourceName, int $sourceId): bool
    {
        return BookSource::where('source_name', $sourceName)
            ->where('source_id', (string)$sourceId)
            ->exists();
    }

    private function updateExistingBook(Book $book, array $newData, int $sourceId, Config $config, ExecutionLog $executionLog): array
    {
        $options = [
            'fill_missing_fields' => $config->fill_missing_fields ?? true,
            'update_descriptions' => $config->update_descriptions ?? true,
            'smart_merge' => true,
            'source_priority' => $this->getSourcePriority($config->source_name)
        ];

        $result = $book->smartUpdate($newData, $options);

        $executionLog->addLogEntry("🔄 بروزرسانی پیشرفته انجام شد", [
            'source_id' => $sourceId,
            'book_id' => $book->id,
            'title' => $book->title,
            'action' => $result['action'],
            'changes_count' => count($result['changes']),
            'updated_database' => $result['updated']
        ]);

        return $result;
    }

    private function createNewBook(array $data, string $md5, int $sourceId, Config $config, ExecutionLog $executionLog): Book
    {
        $hashData = [
            'md5' => $md5,
            'sha1' => $data['sha1'] ?? null,
            'sha256' => $data['sha256'] ?? null,
            'crc32' => $data['crc32'] ?? null,
            'ed2k' => $data['ed2k'] ?? null,
            'btih' => $data['btih'] ?? null,
            'magnet' => $data['magnet'] ?? null,
        ];

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

        return $book;
    }

    private function recordBookSource(int $bookId, string $sourceName, int $sourceId): void
    {
        BookSource::recordBookSource($bookId, $sourceName, (string)$sourceId);
    }

    private function getSourcePriority(string $sourceName): int
    {
        $priorities = [
            'libgen_rs' => 9,
            'zlib' => 8,
            'anna_archive' => 7,
            'gutenberg' => 6,
            'archive_org' => 5,
            'default' => 4
        ];

        return $priorities[$sourceName] ?? $priorities['default'];
    }

    private function buildSuccessResult(int $sourceId, Book $book): array
    {
        return [
            'source_id' => $sourceId,
            'action' => 'created',
            'stats' => ['total' => 1, 'success' => 1, 'failed' => 0, 'duplicate' => 0],
            'book_id' => $book->id,
            'title' => $book->title
        ];
    }

    private function buildUpdateResult(int $sourceId, array $updateResult, Book $book): array
    {
        $stats = $this->determineStatsFromUpdate($updateResult);

        return [
            'source_id' => $sourceId,
            'action' => $updateResult['action'],
            'stats' => $stats,
            'book_id' => $book->id,
            'title' => $book->title
        ];
    }

    private function buildFailureResult(int $sourceId): array
    {
        return [
            'source_id' => $sourceId,
            'action' => 'failed',
            'stats' => ['total' => 1, 'success' => 0, 'failed' => 1, 'duplicate' => 0]
        ];
    }

    private function determineStatsFromUpdate(array $updateResult): array
    {
        $action = $updateResult['action'];

        switch ($action) {
            case 'enhanced':
            case 'enriched':
            case 'merged':
                return [
                    'total' => 1,
                    'success' => 1,
                    'failed' => 0,
                    'duplicate' => 0,
                    'enhanced' => 1
                ];

            case 'updated':
                return [
                    'total' => 1,
                    'success' => 0,
                    'failed' => 0,
                    'duplicate' => 1,
                    'updated' => 1
                ];

            default:
                return [
                    'total' => 1,
                    'success' => 0,
                    'failed' => 0,
                    'duplicate' => 1
                ];
        }
    }
}
