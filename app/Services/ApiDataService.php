<?php

namespace App\Services;

use App\Models\Config;
use App\Models\Book;
use App\Models\BookSource;
use App\Models\BookHash;
use App\Models\ExecutionLog;
use App\Models\ScrapingFailure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ApiDataService
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * پردازش یک source ID
     */
    public function processSourceId(int $sourceId, ExecutionLog $executionLog): array
    {
        if ($sourceId === -1) {
            return $this->completeExecution($executionLog);
        }

        Log::info("🔍 پردازش source ID {$sourceId}", [
            'config_id' => $this->config->id,
            'execution_id' => $executionLog->execution_id
        ]);

        $executionLog->addLogEntry("🔍 شروع پردازش source ID {$sourceId}", [
            'source_id' => $sourceId,
            'url' => $this->config->buildApiUrl($sourceId)
        ]);

        try {
            // بررسی اینکه آیا این source ID قبلاً پردازش شده
            if ($this->isSourceAlreadyProcessed($sourceId)) {
                $executionLog->addLogEntry("⏭️ Source ID {$sourceId} قبلاً پردازش شده", [
                    'source_id' => $sourceId,
                    'action' => 'skipped'
                ]);

                return $this->buildResult($sourceId, 'skipped', ['total' => 0, 'success' => 0, 'failed' => 0, 'duplicate' => 1]);
            }

            // ساخت URL
            $url = $this->config->buildApiUrl($sourceId);

            // درخواست HTTP
            $response = $this->makeHttpRequest($url);

            if (!$response->successful()) {
                $error = "HTTP {$response->status()}: {$response->reason()}";
                $this->logFailure($sourceId, $error);

                $executionLog->addLogEntry("❌ خطای HTTP در source ID {$sourceId}", [
                    'source_id' => $sourceId,
                    'http_status' => $response->status(),
                    'error' => $error
                ]);

                return $this->buildResult($sourceId, 'failed', ['total' => 1, 'success' => 0, 'failed' => 1, 'duplicate' => 0]);
            }

            // استخراج داده‌ها
            $data = $response->json();

            if (empty($data)) {
                $executionLog->addLogEntry("📭 پاسخ خالی از source ID {$sourceId}", [
                    'source_id' => $sourceId,
                    'response_size' => strlen($response->body())
                ]);

                $this->logFailure($sourceId, 'پاسخ API خالی است');
                return $this->buildResult($sourceId, 'failed', ['total' => 1, 'success' => 0, 'failed' => 1, 'duplicate' => 0]);
            }

            $bookData = $this->extractBookData($data, $sourceId);

            if (empty($bookData)) {
                $executionLog->addLogEntry("📭 ساختار کتاب در پاسخ یافت نشد", [
                    'source_id' => $sourceId,
                    'response_keys' => array_keys($data),
                    'sample_data' => array_slice($data, 0, 3, true)
                ]);

                $this->logFailure($sourceId, 'ساختار کتاب در پاسخ API یافت نشد');
                return $this->buildResult($sourceId, 'failed', ['total' => 1, 'success' => 0, 'failed' => 1, 'duplicate' => 0]);
            }

            // استخراج فیلدها
            $extractedData = $this->extractFields($bookData, $sourceId, $executionLog);

            if (empty($extractedData['title'])) {
                $executionLog->addLogEntry("📭 عنوان کتاب استخراج نشد", [
                    'source_id' => $sourceId,
                    'extracted_fields' => array_keys($extractedData),
                    'book_data_sample' => array_slice($bookData, 0, 5, true)
                ]);

                $this->logFailure($sourceId, 'عنوان کتاب یافت نشد');
                return $this->buildResult($sourceId, 'failed', ['total' => 1, 'success' => 0, 'failed' => 1, 'duplicate' => 0]);
            }

            // پردازش کتاب
            return $this->processBook($extractedData, $sourceId, $executionLog);

        } catch (\Exception $e) {
            Log::error("❌ خطا در پردازش source ID {$sourceId}", [
                'config_id' => $this->config->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $executionLog->addLogEntry("❌ خطای کلی در source ID {$sourceId}", [
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return $this->buildResult($sourceId, 'failed', ['total' => 1, 'success' => 0, 'failed' => 1, 'duplicate' => 0]);
        }
    }

    /**
     * بررسی اینکه source ID قبلاً پردازش شده
     */
    private function isSourceAlreadyProcessed(int $sourceId): bool
    {
        return BookSource::where('source_name', $this->config->source_name)
            ->where('source_id', (string)$sourceId)
            ->exists();
    }

    /**
     * پردازش کتاب
     */
    private function processBook(array $extractedData, int $sourceId, ExecutionLog $executionLog): array
    {
        try {
            // محاسبه MD5 برای شناسایی تکراری
            $md5 = Book::calculateContentMd5($extractedData);

            $executionLog->addLogEntry("🔐 محاسبه MD5 برای source ID {$sourceId}", [
                'source_id' => $sourceId,
                'md5' => $md5,
                'title' => $extractedData['title'] ?? 'نامشخص'
            ]);

            // بررسی وجود کتاب
            $existingBook = Book::findByMd5($md5);

            if ($existingBook) {
                // کتاب موجود - بروزرسانی
                $result = $this->updateExistingBook($existingBook, $extractedData, $sourceId, $executionLog);
                $this->recordSource($existingBook->id, $sourceId);

                return $this->buildResult($sourceId, $result['action'], ['total' => 1, 'success' => 0, 'failed' => 0, 'duplicate' => 1], $existingBook);
            } else {
                // کتاب جدید
                $book = $this->createNewBook($extractedData, $md5, $sourceId, $executionLog);

                return $this->buildResult($sourceId, 'created', ['total' => 1, 'success' => 1, 'failed' => 0, 'duplicate' => 0], $book);
            }

        } catch (\Exception $e) {
            Log::error("❌ خطا در پردازش کتاب", [
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
                'extracted_data' => $extractedData
            ]);

            $executionLog->addLogEntry("❌ خطا در پردازش کتاب source ID {$sourceId}", [
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
                'title' => $extractedData['title'] ?? 'نامشخص'
            ]);

            return $this->buildResult($sourceId, 'failed', ['total' => 1, 'success' => 0, 'failed' => 1, 'duplicate' => 0]);
        }
    }

    /**
     * بروزرسانی کتاب موجود
     */
    private function updateExistingBook(Book $book, array $newData, int $sourceId, ExecutionLog $executionLog): array
    {
        $options = [
            'fill_missing_fields' => $this->config->fill_missing_fields,
            'update_descriptions' => $this->config->update_descriptions,
        ];

        $result = $book->smartUpdate($newData, $options);

        $executionLog->addLogEntry("🔄 کتاب موجود بروزرسانی شد", [
            'source_id' => $sourceId,
            'book_id' => $book->id,
            'title' => $book->title,
            'changes' => $result['changes'],
            'action' => $result['action']
        ]);

        return $result;
    }

    /**
     * ایجاد کتاب جدید
     */
    private function createNewBook(array $data, string $md5, int $sourceId, ExecutionLog $executionLog): Book
    {
        // آماده‌سازی داده‌های هش
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
            $this->config->source_name,
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

    /**
     * ثبت منبع کتاب
     */
    private function recordSource(int $bookId, int $sourceId): void
    {
        BookSource::recordBookSource($bookId, $this->config->source_name, (string)$sourceId);
    }

    /**
     * استخراج داده‌های کتاب از پاسخ API
     */
    private function extractBookData(array $data, int $sourceId): array
    {
        Log::info("🔍 استخراج داده‌های کتاب از پاسخ API", [
            'source_id' => $sourceId,
            'data_structure' => array_keys($data),
            'has_status' => isset($data['status']),
            'status_value' => $data['status'] ?? null
        ]);

        // بررسی ساختار success/data/book
        if (isset($data['status']) && $data['status'] === 'success' && isset($data['data']['book'])) {
            Log::info("✅ ساختار success/data/book یافت شد", ['source_id' => $sourceId]);
            return $data['data']['book'];
        }

        // بررسی ساختار مستقیم data/book
        if (isset($data['data']['book'])) {
            Log::info("✅ ساختار data/book یافت شد", ['source_id' => $sourceId]);
            return $data['data']['book'];
        }

        // بررسی اینکه خود data یک کتاب است
        if (isset($data['id']) || isset($data['title'])) {
            Log::info("✅ ساختار مستقیم کتاب یافت شد", ['source_id' => $sourceId]);
            return $data;
        }

        // بررسی کلیدهای احتمالی
        $possibleKeys = ['data', 'book', 'result', 'item', 'response'];
        foreach ($possibleKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                Log::info("✅ ساختار با کلید '{$key}' یافت شد", ['source_id' => $sourceId]);
                return $data[$key];
            }
        }

        Log::warning("❌ هیچ ساختار کتاب معتبری یافت نشد", [
            'source_id' => $sourceId,
            'available_keys' => array_keys($data)
        ]);

        return [];
    }

    /**
     * استخراج فیلدها بر اساس نقشه‌برداری
     */
    private function extractFields(array $data, int $sourceId, ExecutionLog $executionLog): array
    {
        $apiSettings = $this->config->getApiSettings();
        $fieldMapping = $apiSettings['field_mapping'] ?? [];

        // اگر نقشه‌برداری خالی باشد، از پیش‌فرض استفاده کن
        if (empty($fieldMapping)) {
            $fieldMapping = $this->getDefaultFieldMapping();
        }

        Log::info("🗺️ شروع استخراج فیلدها", [
            'source_id' => $sourceId,
            'field_mapping_count' => count($fieldMapping),
            'available_data_keys' => array_keys($data)
        ]);

        $extracted = [];
        foreach ($fieldMapping as $bookField => $apiField) {
            if (empty($apiField)) continue;

            $value = $this->getNestedValue($data, $apiField);
            if ($value !== null) {
                $sanitized = $this->sanitizeValue($value, $bookField);
                if ($sanitized !== null) {
                    $extracted[$bookField] = $sanitized;
                }
            }
        }

        $executionLog->addLogEntry("🗺️ استخراج فیلدها تمام شد", [
            'source_id' => $sourceId,
            'extracted_fields' => array_keys($extracted),
            'title' => $extracted['title'] ?? 'یافت نشد'
        ]);

        Log::info("✅ فیلدها استخراج شدند", [
            'source_id' => $sourceId,
            'extracted_count' => count($extracted),
            'fields' => array_keys($extracted),
            'title' => $extracted['title'] ?? 'نامشخص'
        ]);

        return $extracted;
    }

    /**
     * نقشه‌برداری پیش‌فرض فیلدها
     */
    private function getDefaultFieldMapping(): array
    {
        return [
            'title' => 'title',
            'description' => 'description',
            'author' => 'authors',
            'category' => 'category',
            'publisher' => 'publisher',
            'isbn' => 'isbn',
            'publication_year' => 'publication_year',
            'pages_count' => 'pages_count',
            'language' => 'language',
            'format' => 'format',
            'file_size' => 'file_size',
            'image_url' => 'image_url',
            'sha1' => 'sha1',
            'sha256' => 'sha256',
            'crc32' => 'crc32',
            'ed2k' => 'ed2k_hash',
            'btih' => 'btih',
            'magnet' => 'magnet_link'
        ];
    }

    /**
     * دریافت مقدار nested از آرایه
     */
    private function getNestedValue(array $data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (is_array($value)) {
                $value = is_numeric($key) ? $value[(int)$key] ?? null : $value[$key] ?? null;
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * پاکسازی مقادیر
     */
    private function sanitizeValue($value, string $fieldType)
    {
        if ($value === null) return null;

        return match ($fieldType) {
            'title', 'description', 'category' => trim((string)$value),
            'author' => $this->extractAuthors($value),
            'publisher' => is_array($value) ? ($value['name'] ?? '') : trim((string)$value),
            'publication_year' => is_numeric($value) && $value >= 1000 && $value <= date('Y') + 5 ? (int)$value : null,
            'pages_count', 'file_size' => is_numeric($value) && $value > 0 ? (int)$value : null,
            'isbn' => preg_replace('/[^0-9X-]/', '', (string)$value),
            'language' => $this->normalizeLanguage((string)$value),
            'format' => $this->normalizeFormat((string)$value),
            'image_url' => filter_var(trim((string)$value), FILTER_VALIDATE_URL) ?: null,
            'sha1', 'sha256', 'crc32', 'ed2k', 'btih' => is_string($value) ? trim($value) : null,
            'magnet' => is_string($value) && str_starts_with($value, 'magnet:') ? trim($value) : null,
            default => trim((string)$value)
        };
    }

    private function extractAuthors($authorsData): ?string
    {
        if (is_string($authorsData)) {
            return trim($authorsData);
        }

        if (is_array($authorsData)) {
            $names = [];
            foreach ($authorsData as $author) {
                if (is_array($author) && isset($author['name'])) {
                    $names[] = trim($author['name']);
                } elseif (is_string($author)) {
                    $names[] = trim($author);
                }
            }
            return !empty($names) ? implode(', ', $names) : null;
        }

        return null;
    }

    private function normalizeLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        $langMap = ['persian' => 'fa', 'english' => 'en', 'فارسی' => 'fa'];
        return $langMap[$language] ?? substr($language, 0, 2);
    }

    private function normalizeFormat(string $format): string
    {
        $format = strtolower(trim($format));
        $allowedFormats = ['pdf', 'epub', 'mobi', 'djvu'];
        return in_array($format, $allowedFormats) ? $format : 'pdf';
    }

    /**
     * درخواست HTTP
     */
    private function makeHttpRequest(string $url)
    {
        $generalSettings = $this->config->getGeneralSettings();

        return Http::timeout($this->config->timeout)
            ->retry(2, 1000)
            ->when(!($generalSettings['verify_ssl'] ?? true), fn($client) => $client->withoutVerifying())
            ->get($url);
    }

    /**
     * ثبت شکست
     */
    private function logFailure(int $sourceId, string $reason): void
    {
        ScrapingFailure::create([
            'config_id' => $this->config->id,
            'url' => $this->config->buildApiUrl($sourceId),
            'error_message' => "Source ID {$sourceId}: {$reason}",
            'error_details' => [
                'source_id' => $sourceId,
                'source_name' => $this->config->source_name,
                'reason' => $reason
            ],
            'http_status' => 404,
            'retry_count' => 0,
            'last_attempt_at' => now()
        ]);
    }

    /**
     * ساخت نتیجه
     */
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

        // بروزرسانی آمار کانفیگ
        $this->config->updateProgress($sourceId, $stats);

        return $result;
    }

    /**
     * تمام کردن اجرا
     */
    private function completeExecution(ExecutionLog $executionLog): array
    {
        $finalStats = [
            'total' => $this->config->total_processed,
            'success' => $this->config->total_success,
            'failed' => $this->config->total_failed,
            'execution_time' => $executionLog->started_at ? now()->diffInSeconds($executionLog->started_at) : 0
        ];

        $executionLog->markCompleted($finalStats);
        $this->config->update(['is_running' => false]);

        Log::info("🎉 اجرا کامل شد", [
            'config_id' => $this->config->id,
            'execution_id' => $executionLog->execution_id,
            'final_stats' => $finalStats
        ]);

        return ['action' => 'completed'];
    }
}
