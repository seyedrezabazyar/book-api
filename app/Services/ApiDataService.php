<?php

namespace App\Services;

use App\Models\Config;
use App\Models\Book;
use App\Models\Author;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\BookImage;
use App\Models\BookSource;
use App\Models\ExecutionLog;
use App\Models\ScrapingFailure;
use App\Jobs\ProcessSinglePageJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB; // اضافه کردن این خط

class ApiDataService
{
    private Config $config;
    private ?ExecutionLog $executionLog = null;
    private array $stats = ['total' => 0, 'success' => 0, 'failed' => 0, 'duplicate' => 0, 'updated' => 0];

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * اجرای کامل با استفاده از Job Queue (روش جدید - بر اساس source ID)
     */
    public function fetchDataAsync(?int $maxIds = null): array
    {
        $this->executionLog = ExecutionLog::createNew($this->config);
        $maxIds = $maxIds ?: $this->config->max_pages;

        try {
            $this->config->update(['is_running' => true]);

            $startId = $this->config->getSmartStartPage();
            $endId = $startId + $maxIds - 1;

            Log::info("🚀 شروع اجرای Async با source ID", [
                'config_id' => $this->config->id,
                'source_name' => $this->config->source_name,
                'start_id' => $startId,
                'end_id' => $endId,
                'total_ids' => $maxIds,
                'execution_id' => $this->executionLog->execution_id
            ]);

            // ایجاد Jobs برای هر source ID
            for ($sourceId = $startId; $sourceId <= $endId; $sourceId++) {
                ProcessSinglePageJob::dispatch(
                    $this->config->id,
                    $sourceId, // حالا بجای page number، source ID ارسال می‌شود
                    $this->executionLog->execution_id
                );
            }

            // Job نهایی برای تمام کردن اجرا
            ProcessSinglePageJob::dispatch(
                $this->config->id,
                -1, // شماره منفی = پایان اجرا
                $this->executionLog->execution_id
            )->delay(now()->addSeconds($this->config->delay_seconds * $maxIds + 60));

            return [
                'status' => 'queued',
                'execution_id' => $this->executionLog->execution_id,
                'ids_queued' => $maxIds,
                'start_id' => $startId,
                'end_id' => $endId,
                'message' => "تعداد {$maxIds} ID منبع ({$startId} تا {$endId}) در صف قرار گرفت"
            ];
        } catch (\Exception $e) {
            $this->executionLog->markFailed($e->getMessage());
            $this->config->update(['is_running' => false]);
            throw $e;
        }
    }

    /**
     * پردازش یک source ID منفرد
     */
    public function processSourceId(int $sourceId, ExecutionLog $executionLog): array
    {
        if ($sourceId === -1) {
            // این Job برای پایان دادن به اجرا است
            $this->completeExecution($executionLog);
            return ['action' => 'completed'];
        }

        $startTime = microtime(true);
        $apiSettings = $this->config->getApiSettings();
        $generalSettings = $this->config->getGeneralSettings();

        // ساخت URL برای source ID خاص
        $url = $this->buildApiUrlForSourceId($sourceId);

        $executionLog->addLogEntry("🔍 پردازش source ID {$sourceId}", [
            'source_id' => $sourceId,
            'url' => $url,
            'started_at' => now()->toISOString()
        ]);

        Log::info("🔍 شروع پردازش source ID {$sourceId}", [
            'config_id' => $this->config->id,
            'execution_id' => $executionLog->execution_id,
            'url' => $url
        ]);

        try {
            // بررسی اینکه آیا این ID قبلاً پردازش شده
            if ($this->config->isSourceIdProcessed($sourceId)) {
                $executionLog->addLogEntry("⏭️ Source ID {$sourceId} قبلاً پردازش شده", [
                    'source_id' => $sourceId,
                    'action' => 'skipped'
                ]);

                // بروزرسانی last_source_id
                $this->config->updateLastSourceId($sourceId);

                return [
                    'source_id' => $sourceId,
                    'action' => 'skipped',
                    'reason' => 'already_processed'
                ];
            }

            $response = $this->makeHttpRequest($url, $apiSettings, $generalSettings);

            if (!$response->successful()) {
                $error = "خطای HTTP {$response->status()}: {$response->reason()}";

                // ثبت شکست
                $this->config->logSourceIdFailure($sourceId, "HTTP {$response->status()}");

                $executionLog->addLogEntry("❌ خطا در source ID {$sourceId}: {$error}", [
                    'source_id' => $sourceId,
                    'http_status' => $response->status(),
                    'error' => $error,
                    'url' => $url
                ]);

                throw new \Exception($error);
            }

            $data = $response->json();
            $bookData = $this->extractBookFromApiData($data, $sourceId);

            if (empty($bookData)) {
                // این ID کتابی ندارد - ثبت شکست
                $this->config->logSourceIdFailure($sourceId, 'No book data found');

                $executionLog->addLogEntry("📭 Source ID {$sourceId}: کتابی یافت نشد", [
                    'source_id' => $sourceId,
                    'response_size' => strlen(json_encode($data)),
                    'action' => 'no_book_found'
                ]);

                return [
                    'source_id' => $sourceId,
                    'action' => 'no_book_found',
                    'stats' => ['total' => 1, 'success' => 0, 'failed' => 1, 'duplicate' => 0]
                ];
            }

            // پردازش کتاب
            $bookResult = $this->processBookWithSource($bookData, $sourceId, $executionLog, $apiSettings);
            $processTime = round(microtime(true) - $startTime, 2);

            // بروزرسانی آمار کانفیگ
            $this->config->updateProgress($sourceId, $bookResult['stats']);

            // بروزرسانی ExecutionLog
            $executionLog->updateProgress($bookResult['stats']);

            Log::info("✅ Source ID {$sourceId} کامل شد", [
                'config_id' => $this->config->id,
                'execution_id' => $executionLog->execution_id,
                'result' => $bookResult,
                'process_time' => $processTime
            ]);

            return $bookResult;
        } catch (\Exception $e) {
            Log::error("❌ خطا در پردازش source ID {$sourceId}", [
                'config_id' => $this->config->id,
                'execution_id' => $executionLog->execution_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $executionLog->addLogEntry("❌ خطای کلی در source ID {$sourceId}", [
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
                'failed_at' => now()->toISOString()
            ]);

            throw $e;
        }
    }

    /**
     * پردازش کتاب با منبع
     */
    private function processBookWithSource(array $bookData, int $sourceId, ExecutionLog $executionLog, array $apiSettings): array
    {
        $stats = ['total' => 1, 'success' => 0, 'failed' => 0, 'duplicate' => 0, 'updated' => 0];

        try {
            $extractedData = $this->extractFieldsFromData($bookData, $apiSettings['field_mapping'] ?? []);

            if (empty($extractedData['title'])) {
                throw new \Exception('عنوان کتاب یافت نشد');
            }

            // محاسبه MD5 برای کتاب
            $contentHash = $this->calculateBookHash($extractedData);

            // بررسی وجود کتاب با همین MD5
            $existingBook = Book::where('content_hash', $contentHash)->first();

            if ($existingBook) {
                // کتاب با همین MD5 وجود دارد
                $result = $this->handleExistingBook($existingBook, $extractedData, $sourceId);
                $stats[$result['action']]++;

                $executionLog->addLogEntry("🔄 کتاب موجود پردازش شد", [
                    'source_id' => $sourceId,
                    'book_id' => $existingBook->id,
                    'title' => $existingBook->title,
                    'action' => $result['action'],
                    'changes' => $result['changes'] ?? []
                ]);

                return [
                    'source_id' => $sourceId,
                    'action' => $result['action'],
                    'book_id' => $existingBook->id,
                    'title' => $existingBook->title,
                    'stats' => $stats
                ];
            } else {
                // کتاب جدید
                $book = $this->createNewBookWithSource($extractedData, $sourceId, $contentHash);
                $stats['success']++;

                $executionLog->addLogEntry("✨ کتاب جدید ایجاد شد", [
                    'source_id' => $sourceId,
                    'book_id' => $book->id,
                    'title' => $book->title,
                    'content_hash' => $contentHash
                ]);

                return [
                    'source_id' => $sourceId,
                    'action' => 'created',
                    'book_id' => $book->id,
                    'title' => $book->title,
                    'stats' => $stats
                ];
            }
        } catch (\Exception $e) {
            $stats['failed']++;
            Log::error('❌ خطا در پردازش کتاب', [
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
                'book_data' => $bookData
            ]);

            return [
                'source_id' => $sourceId,
                'action' => 'failed',
                'error' => $e->getMessage(),
                'stats' => $stats
            ];
        }
    }

    /**
     * مدیریت کتاب موجود
     */
    private function handleExistingBook(Book $existingBook, array $newData, int $sourceId): array
    {
        $changes = [];
        $needsUpdate = false;

        // اضافه کردن منبع جدید
        $this->addBookSource($existingBook, $sourceId);

        // بررسی فیلدهای قابل تکمیل
        if ($this->config->fill_missing_fields) {
            $fillableFields = ['description', 'isbn', 'publication_year', 'pages_count', 'file_size', 'language', 'format'];

            foreach ($fillableFields as $field) {
                if (empty($existingBook->$field) && !empty($newData[$field])) {
                    $existingBook->$field = $newData[$field];
                    $changes[] = "filled_{$field}";
                    $needsUpdate = true;
                }
            }
        }

        // بررسی توضیحات بهتر
        if ($this->config->update_descriptions && !empty($newData['description'])) {
            $existingLength = strlen($existingBook->description ?? '');
            $newLength = strlen($newData['description']);

            if ($newLength > $existingLength * 1.2) { // 20% بیشتر
                $existingBook->description = $newData['description'];
                $changes[] = 'updated_description';
                $needsUpdate = true;
            }
        }

        // پردازش تصاویر جدید
        if (!empty($newData['image_url'])) {
            $this->processImages($existingBook, $newData['image_url']);
            $changes[] = 'updated_images';
        }

        // پردازش نویسندگان جدید
        if (!empty($newData['author'])) {
            $this->processAuthors($existingBook, $newData['author']);
            $changes[] = 'updated_authors';
        }

        if ($needsUpdate) {
            $existingBook->save();
            return ['action' => 'updated', 'changes' => $changes];
        } else {
            return ['action' => 'duplicate', 'changes' => ['added_source']];
        }
    }

    /**
     * ایجاد کتاب جدید با منبع
     */
    private function createNewBookWithSource(array $extractedData, int $sourceId, string $contentHash): Book
    {
        return DB::transaction(function () use ($extractedData, $sourceId, $contentHash) {
            // ایجاد category و publisher
            $category = $this->findOrCreateCategory($extractedData['category'] ?? 'عمومی');
            $publisher = null;

            if (!empty($extractedData['publisher'])) {
                $publisherName = $this->extractPublisherName($extractedData['publisher']);
                if ($publisherName) {
                    $publisher = $this->findOrCreatePublisher($publisherName);
                }
            }

            // ایجاد کتاب
            $book = Book::create([
                'title' => $extractedData['title'],
                'description' => $extractedData['description'] ?? null,
                'excerpt' => Str::limit($extractedData['description'] ?? $extractedData['title'], 200),
                'slug' => Str::slug($extractedData['title'] . '_' . time()),
                'isbn' => $extractedData['isbn'] ?? null,
                'publication_year' => $extractedData['publication_year'] ?? null,
                'pages_count' => $extractedData['pages_count'] ?? null,
                'language' => $extractedData['language'] ?? 'fa',
                'format' => $extractedData['format'] ?? 'pdf',
                'file_size' => $extractedData['file_size'] ?? null,
                'content_hash' => $contentHash,
                'category_id' => $category->id,
                'publisher_id' => $publisher?->id,
                'downloads_count' => 0,
                'status' => 'active'
            ]);

            // اضافه کردن منبع
            $this->addBookSource($book, $sourceId);

            // پردازش نویسندگان
            if (!empty($extractedData['author'])) {
                $this->processAuthors($book, $extractedData['author']);
            }

            // پردازش تصاویر
            if (!empty($extractedData['image_url'])) {
                $this->processImages($book, $extractedData['image_url']);
            }

            return $book;
        });
    }

    /**
     * اضافه کردن منبع کتاب
     */
    private function addBookSource(Book $book, int $sourceId): void
    {
        BookSource::updateOrCreate(
            [
                'book_id' => $book->id,
                'source_type' => $this->config->source_type,
                'source_id' => (string) $sourceId
            ],
            [
                'source_url' => $this->buildApiUrlForSourceId($sourceId),
                'source_updated_at' => now(),
                'is_active' => true,
                'priority' => 1
            ]
        );

        Log::info("📝 منبع کتاب ثبت شد", [
            'book_id' => $book->id,
            'source_type' => $this->config->source_type,
            'source_id' => $sourceId,
            'source_name' => $this->config->source_name
        ]);
    }

    /**
     * محاسبه hash کتاب بر اساس اطلاعات اصلی
     */
    private function calculateBookHash(array $data): string
    {
        $hashData = [
            'title' => $data['title'] ?? '',
            'author' => $data['author'] ?? '',
            'isbn' => $data['isbn'] ?? '',
            'publication_year' => $data['publication_year'] ?? '',
            'pages_count' => $data['pages_count'] ?? ''
        ];

        // حذف فاصله‌های اضافی و تبدیل به lowercase
        $normalizedData = array_map(function ($value) {
            return strtolower(trim(preg_replace('/\s+/', ' ', $value)));
        }, $hashData);

        return md5(json_encode($normalizedData, JSON_UNESCAPED_UNICODE));
    }

    /**
     * ساخت URL API برای source ID خاص
     */
    private function buildApiUrlForSourceId(int $sourceId): string
    {
        return $this->config->buildApiUrl($sourceId);
    }

    /**
     * استخراج داده کتاب از پاسخ API
     */
    private function extractBookFromApiData(array $data, int $sourceId): array
    {
        // بررسی status
        if (isset($data['status']) && $data['status'] === 'success' && isset($data['data']['book'])) {
            return $data['data']['book'];
        }

        // اگر خود data یک کتاب است
        if (isset($data['id']) || isset($data['title'])) {
            return $data;
        }

        // بررسی ساختارهای مختلف
        $possibleKeys = ['data', 'book', 'result', 'item'];
        foreach ($possibleKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        Log::warning("📚 ساختار نامعلوم برای source ID {$sourceId}", [
            'data_keys' => array_keys($data),
            'source_id' => $sourceId
        ]);

        return [];
    }

    // تکمیل اجرا
    private function completeExecution(ExecutionLog $executionLog): void
    {
        $config = $this->config->fresh();
        $finalStats = [
            'total' => $config->total_processed,
            'success' => $config->total_success,
            'failed' => $config->total_failed,
            'duplicate' => $executionLog->total_duplicate,
            'execution_time' => $executionLog->started_at ? now()->diffInSeconds($executionLog->started_at) : 0
        ];

        $executionLog->markCompleted($finalStats);
        $this->config->update(['is_running' => false]);

        Log::info("🎉 اجرا کامل شد", [
            'config_id' => $this->config->id,
            'execution_id' => $executionLog->execution_id,
            'final_stats' => $finalStats,
            'last_source_id' => $this->config->last_source_id
        ]);
    }

    // متدهای کمکی
    private function makeHttpRequest(string $url, array $apiSettings, array $generalSettings)
    {
        $httpClient = Http::timeout($this->config->timeout)->retry(3, 1000);

        if (!empty($generalSettings['user_agent'])) {
            $httpClient = $httpClient->withUserAgent($generalSettings['user_agent']);
        }

        if (!($generalSettings['verify_ssl'] ?? true)) {
            $httpClient = $httpClient->withoutVerifying();
        }

        return $httpClient->get($url);
    }

    private function extractFieldsFromData(array $data, array $fieldMapping): array
    {
        if (empty($fieldMapping)) {
            $fieldMapping = [
                'title' => 'title',
                'description' => 'description_en',
                'author' => 'authors',
                'category' => 'category.name',
                'publisher' => 'publisher.name',
                'isbn' => 'isbn',
                'publication_year' => 'publication_year',
                'pages_count' => 'pages_count',
                'language' => 'language',
                'format' => 'format',
                'file_size' => 'file_size',
                'image_url' => 'image_url.0'
            ];
        }

        $extracted = [];
        foreach ($fieldMapping as $bookField => $apiField) {
            if (empty($apiField)) continue;

            $value = $this->getNestedValue($data, $apiField);
            if ($value !== null) {
                $extracted[$bookField] = $this->sanitizeValue($value, $bookField);
            }
        }

        return $extracted;
    }

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

    private function sanitizeValue($value, string $fieldType)
    {
        if ($value === null) return null;

        return match ($fieldType) {
            'title', 'description', 'category' => trim((string) $value),
            'author' => $this->extractAuthors($value),
            'publisher' => is_array($value) && isset($value['name']) ? trim($value['name']) : $this->extractPublisherName($value),
            'publication_year' => is_numeric($value) && $value >= 1000 && $value <= date('Y') + 5 ? (int) $value : null,
            'pages_count', 'file_size' => is_numeric($value) && $value > 0 ? (int) $value : null,
            'isbn' => preg_replace('/[^0-9X-]/', '', (string) $value),
            'language' => $this->normalizeLanguage((string) $value),
            'format' => $this->normalizeFormat((string) $value),
            'image_url' => $this->extractImageUrl($value),
            default => trim((string) $value)
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

    private function extractImageUrl($imageData): ?string
    {
        if (is_string($imageData)) {
            return filter_var(trim($imageData), FILTER_VALIDATE_URL) ?: null;
        }

        if (is_array($imageData)) {
            foreach ($imageData as $url) {
                if (is_string($url) && filter_var(trim($url), FILTER_VALIDATE_URL)) {
                    return trim($url);
                }
            }
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

    private function extractPublisherName($publisherData): ?string
    {
        if (is_string($publisherData)) return trim($publisherData);

        if (is_array($publisherData)) {
            if (isset($publisherData['name'])) return trim($publisherData['name']);
            foreach ($publisherData as $value) {
                if (is_string($value) && !empty(trim($value))) return trim($value);
            }
        }

        return null;
    }

    private function findOrCreateCategory(string $categoryName): Category
    {
        return Category::firstOrCreate(
            ['name' => $categoryName],
            ['slug' => Str::slug($categoryName . '_' . time()), 'is_active' => true, 'books_count' => 0]
        );
    }

    private function findOrCreatePublisher(string $publisherName): Publisher
    {
        return Publisher::firstOrCreate(
            ['name' => $publisherName],
            ['slug' => Str::slug($publisherName . '_' . time()), 'is_active' => true, 'books_count' => 0]
        );
    }

    private function processAuthors(Book $book, string $authorString): void
    {
        $authorNames = array_map('trim', explode(',', $authorString));
        foreach ($authorNames as $authorName) {
            if (empty($authorName)) continue;
            $author = Author::firstOrCreate(
                ['name' => $authorName],
                ['slug' => Str::slug($authorName . '_' . time()), 'is_active' => true, 'books_count' => 0]
            );
            $book->authors()->syncWithoutDetaching([$author->id]);
        }
    }

    private function processImages(Book $book, string $imageUrl): void
    {
        if (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            BookImage::updateOrCreate(
                ['book_id' => $book->id],
                ['image_url' => $imageUrl]
            );
        }
    }
}
