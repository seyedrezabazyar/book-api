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
                'title' => $cleanedData['title'],
                'extracted_hashes' => $this->extractHashesFromData($cleanedData)
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
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
            'updated_database' => $result['updated'],
            'available_hashes' => $this->extractHashesFromData($newData)
        ]);

        return $result;
    }

    private function createNewBook(array $data, string $md5, int $sourceId, Config $config, ExecutionLog $executionLog): Book
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

        return $book;
    }

    /**
     * استخراج کامل تمام هش‌ها از داده‌ها
     */
    private function extractAllHashes(array $data, string $md5): array
    {
        $hashData = [
            'md5' => $md5, // اولویت با MD5 محاسبه شده
        ];

        // هش‌های مختلف که ممکن است در داده‌ها باشند
        $hashFields = [
            'sha1' => 'sha1',
            'sha256' => 'sha256',
            'crc32' => 'crc32',
            'ed2k' => 'ed2k',
            'ed2k_hash' => 'ed2k',
            'btih' => 'btih',
            'magnet' => 'magnet',
            'magnet_link' => 'magnet'
        ];

        foreach ($hashFields as $sourceKey => $targetKey) {
            if (!empty($data[$sourceKey])) {
                $hashValue = trim($data[$sourceKey]);

                // اعتبارسنجی هش بر اساس نوع
                if ($this->isValidHash($hashValue, $targetKey)) {
                    $hashData[$targetKey] = $hashValue;

                    Log::debug("✅ هش معتبر یافت شد", [
                        'type' => $targetKey,
                        'value' => $hashValue,
                        'source_key' => $sourceKey
                    ]);
                } else {
                    Log::warning("⚠️ هش نامعتبر رد شد", [
                        'type' => $targetKey,
                        'value' => $hashValue,
                        'source_key' => $sourceKey
                    ]);
                }
            }
        }

        // بررسی هش‌های تودرتو در ساختار پیچیده‌تر
        if (isset($data['hashes']) && is_array($data['hashes'])) {
            foreach ($data['hashes'] as $key => $value) {
                if (!empty($value) && isset($hashFields[$key])) {
                    $targetKey = $hashFields[$key];
                    $hashValue = trim($value);

                    if ($this->isValidHash($hashValue, $targetKey) && !isset($hashData[$targetKey])) {
                        $hashData[$targetKey] = $hashValue;

                        Log::debug("✅ هش از ساختار تودرتو یافت شد", [
                            'type' => $targetKey,
                            'value' => $hashValue
                        ]);
                    }
                }
            }
        }

        // اگر MD5 اصلی در داده‌ها موجود است و با محاسبه شده متفاوت است
        if (!empty($data['md5']) && $data['md5'] !== $md5) {
            Log::warning("⚠️ MD5 دریافتی با محاسبه شده متفاوت است", [
                'received_md5' => $data['md5'],
                'calculated_md5' => $md5,
                'using' => 'calculated'
            ]);
        }

        Log::info("🔐 هش‌های استخراج شده", [
            'hash_count' => count($hashData),
            'hash_types' => array_keys($hashData),
            'has_extended_hashes' => count($hashData) > 1
        ]);

        return $hashData;
    }

    /**
     * اعتبارسنجی هش بر اساس نوع
     */
    private function isValidHash(string $hash, string $type): bool
    {
        $hash = trim($hash);

        if (empty($hash)) {
            return false;
        }

        switch ($type) {
            case 'md5':
                return preg_match('/^[a-f0-9]{32}$/i', $hash);

            case 'sha1':
                return preg_match('/^[a-f0-9]{40}$/i', $hash);

            case 'sha256':
                return preg_match('/^[a-f0-9]{64}$/i', $hash);

            case 'crc32':
                return preg_match('/^[a-f0-9]{8}$/i', $hash);

            case 'ed2k':
                return preg_match('/^[a-f0-9]{32}$/i', $hash);

            case 'btih':
                return preg_match('/^[a-f0-9]{40}$/i', $hash);

            case 'magnet':
                return str_starts_with(strtolower($hash), 'magnet:?xt=');

            default:
                return !empty($hash);
        }
    }

    /**
     * استخراج خلاصه هش‌ها برای لاگ
     */
    private function extractHashesFromData(array $data): array
    {
        $hashes = [];

        $hashFields = ['md5', 'sha1', 'sha256', 'crc32', 'ed2k', 'btih', 'magnet'];

        foreach ($hashFields as $field) {
            if (!empty($data[$field])) {
                $hashes[$field] = substr($data[$field], 0, 16) . (strlen($data[$field]) > 16 ? '...' : '');
            }
        }

        // بررسی ساختار تودرتو
        if (isset($data['hashes']) && is_array($data['hashes'])) {
            foreach ($data['hashes'] as $key => $value) {
                if (!empty($value) && in_array($key, $hashFields)) {
                    $hashes[$key] = substr($value, 0, 16) . (strlen($value) > 16 ? '...' : '');
                }
            }
        }

        return $hashes;
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
            'stats' => [
                'total_processed' => 1,
                'total_success' => 1,
                'total_failed' => 0,
                'total_duplicate' => 0,
                'total_enhanced' => 0
            ],
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
            'stats' => [
                'total_processed' => 1,
                'total_success' => 0,
                'total_failed' => 1,
                'total_duplicate' => 0,
                'total_enhanced' => 0
            ]
        ];
    }

    /**
     * تعیین آمار بر اساس نتیجه بروزرسانی - اصلاح شده
     */
    private function determineStatsFromUpdate(array $updateResult): array
    {
        $action = $updateResult['action'];

        switch ($action) {
            case 'enhanced':
            case 'enriched':
            case 'merged':
                return [
                    'total_processed' => 1,
                    'total_success' => 0,
                    'total_failed' => 0,
                    'total_duplicate' => 0,
                    'total_enhanced' => 1
                ];

            case 'updated':
                return [
                    'total_processed' => 1,
                    'total_success' => 0,
                    'total_failed' => 0,
                    'total_duplicate' => 1,
                    'total_enhanced' => 0
                ];

            case 'no_changes':
            case 'unchanged':
            default:
                return [
                    'total_processed' => 1,
                    'total_success' => 0,
                    'total_failed' => 0,
                    'total_duplicate' => 1,
                    'total_enhanced' => 0
                ];
        }
    }
}
