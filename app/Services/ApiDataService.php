<?php

namespace App\Services;

use App\Models\Config;
use App\Models\Book;
use App\Models\Author;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\BookImage;
use App\Models\ExecutionLog;
use App\Jobs\ProcessSinglePageJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ApiDataService
{
    private Config $config;
    private ?ExecutionLog $executionLog = null;
    private array $stats = ['total' => 0, 'success' => 0, 'failed' => 0, 'duplicate' => 0];

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * اجرای کامل با استفاده از Job Queue (روش جدید)
     */
    public function fetchDataAsync(int $maxPages = 10): array
    {
        $this->executionLog = ExecutionLog::createNew($this->config);

        try {
            $this->config->update(['is_running' => true]);

            $crawlingSettings = $this->config->getCrawlingSettings();
            $currentPage = $this->getCurrentPage($crawlingSettings);

            Log::info("شروع اجرای Async", [
                'config_id' => $this->config->id,
                'start_page' => $currentPage,
                'max_pages' => $maxPages,
                'execution_id' => $this->executionLog->execution_id
            ]);

            // ایجاد Job برای هر صفحه
            for ($page = $currentPage; $page < $currentPage + $maxPages; $page++) {
                ProcessSinglePageJob::dispatch(
                    $this->config,
                    $page,
                    $this->executionLog->execution_id
                );
            }

            // تنظیم Job نهایی برای تمام کردن اجرا
            ProcessSinglePageJob::dispatch(
                $this->config,
                -1, // شماره صفحه منفی = پایان اجرا
                $this->executionLog->execution_id
            )->delay(now()->addSeconds($this->config->page_delay * $maxPages + 60));

            return [
                'status' => 'queued',
                'execution_id' => $this->executionLog->execution_id,
                'pages_queued' => $maxPages,
                'message' => "تعداد {$maxPages} صفحه در صف قرار گرفت"
            ];

        } catch (\Exception $e) {
            $this->executionLog->markFailed($e->getMessage());
            $this->config->update(['is_running' => false]);
            throw $e;
        }
    }

    /**
     * اجرای همزمان (روش قبلی برای اجرای فوری)
     */
    public function fetchData(): array
    {
        $this->executionLog = ExecutionLog::createNew($this->config);
        $startTime = microtime(true);

        try {
            $this->config->update(['is_running' => true]);
            $this->processApiData();

            $executionTime = round(microtime(true) - $startTime, 2);
            $this->stats['execution_time'] = $executionTime;

            $this->executionLog->markCompleted($this->stats);
            $this->config->update(['is_running' => false]);

        } catch (\Exception $e) {
            $this->executionLog->markFailed($e->getMessage());
            $this->config->update(['is_running' => false]);
            throw $e;
        }

        return $this->stats;
    }

    /**
     * پردازش یک صفحه منفرد (نسخه اصلاح شده)
     */
    public function processPage(int $pageNumber, ExecutionLog $executionLog): array
    {
        if ($pageNumber === -1) {
            // این Job برای پایان دادن به اجرا است
            $this->completeExecution($executionLog);
            return ['action' => 'completed'];
        }

        $startTime = microtime(true);
        $apiSettings = $this->config->getApiSettings();
        $generalSettings = $this->config->getGeneralSettings();

        $url = $this->buildApiUrl($apiSettings, $pageNumber);

        // ثبت شروع پردازش صفحه
        $executionLog->addLogEntry("🚀 شروع پردازش صفحه {$pageNumber}", [
            'page' => $pageNumber,
            'url' => $url,
            'started_at' => now()->toISOString()
        ]);

        Log::info("🚀 شروع پردازش صفحه {$pageNumber}", [
            'config_id' => $this->config->id,
            'execution_id' => $executionLog->execution_id,
            'url' => $url
        ]);

        try {
            $response = $this->makeHttpRequest($url, $apiSettings, $generalSettings);

            if (!$response->successful()) {
                $error = "خطای HTTP {$response->status()}: {$response->reason()}";
                $executionLog->addLogEntry("❌ خطا در صفحه {$pageNumber}: {$error}", [
                    'page' => $pageNumber,
                    'http_status' => $response->status(),
                    'error' => $error,
                    'url' => $url
                ]);
                throw new \Exception($error);
            }

            $data = $response->json();
            $books = $this->extractBooksFromApiData($data);

            if (empty($books)) {
                $executionLog->addLogEntry("⚪ صفحه {$pageNumber}: هیچ کتابی یافت نشد", [
                    'page' => $pageNumber,
                    'books_found' => 0,
                    'response_size' => strlen(json_encode($data))
                ]);
                return ['action' => 'no_more_data', 'page' => $pageNumber];
            }

            // پردازش کتاب‌ها
            $pageStats = $this->processBooksInPage($books, $pageNumber, $executionLog, $apiSettings);

            $pageProcessTime = round(microtime(true) - $startTime, 2);

            // بروزرسانی آمار کانفیگ
            Log::info("📊 قبل از بروزرسانی Config", [
                'config_id' => $this->config->id,
                'page' => $pageNumber,
                'page_stats' => $pageStats,
                'config_before' => [
                    'total_processed' => $this->config->total_processed,
                    'total_success' => $this->config->total_success,
                    'total_failed' => $this->config->total_failed
                ]
            ]);

            $this->config->updateProgress($pageNumber, $pageStats);

            // بروزرسانی ExecutionLog
            Log::info("📊 قبل از بروزرسانی ExecutionLog", [
                'log_id' => $executionLog->id,
                'page_stats' => $pageStats,
                'log_before' => [
                    'total_processed' => $executionLog->total_processed,
                    'total_success' => $executionLog->total_success,
                    'total_failed' => $executionLog->total_failed
                ]
            ]);

            $executionLog->updateProgress($pageStats);

            // محاسبه سرعت
            $recordsPerMinute = $pageProcessTime > 0 ? round((count($books) / $pageProcessTime) * 60, 1) : 0;

            // بروزرسانی فیلدهای اضافی ExecutionLog
            $executionLog->update([
                'current_page' => $pageNumber,
                'records_per_minute' => $recordsPerMinute,
                'last_activity_at' => now()
            ]);

            Log::info("✅ صفحه {$pageNumber} کامل شد", [
                'config_id' => $this->config->id,
                'execution_id' => $executionLog->execution_id,
                'page_stats' => $pageStats,
                'page_process_time' => $pageProcessTime,
                'records_per_minute' => $recordsPerMinute,
                'config_after' => [
                    'total_processed' => $this->config->fresh()->total_processed,
                    'total_success' => $this->config->fresh()->total_success,
                    'total_failed' => $this->config->fresh()->total_failed
                ],
                'log_after' => [
                    'total_processed' => $executionLog->fresh()->total_processed,
                    'total_success' => $executionLog->fresh()->total_success,
                    'total_failed' => $executionLog->fresh()->total_failed
                ]
            ]);

            return $pageStats;

        } catch (\Exception $e) {
            Log::error("❌ خطا در پردازش صفحه {$pageNumber}", [
                'config_id' => $this->config->id,
                'execution_id' => $executionLog->execution_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $executionLog->addLogEntry("❌ خطای کلی در صفحه {$pageNumber}", [
                'page' => $pageNumber,
                'error' => $e->getMessage(),
                'failed_at' => now()->toISOString()
            ]);

            throw $e;
        }
    }

    /**
     * پردازش کتاب‌های یک صفحه
     */
    private function processBooksInPage(array $books, int $pageNumber, ExecutionLog $executionLog, array $apiSettings): array
    {
        $pageStats = ['total' => 0, 'success' => 0, 'failed' => 0, 'duplicate' => 0];
        $bookDetails = [];

        Log::info("📚 شروع پردازش {count($books)} کتاب در صفحه {$pageNumber}", [
            'config_id' => $this->config->id,
            'page' => $pageNumber,
            'books_count' => count($books)
        ]);

        foreach ($books as $index => $bookData) {
            $pageStats['total']++;
            $bookStartTime = microtime(true);

            try {
                $result = $this->createBook($bookData, $apiSettings['field_mapping'] ?? []);
                $bookProcessTime = round((microtime(true) - $bookStartTime) * 1000, 2);

                $bookDetail = [
                    'index' => $index + 1,
                    'title' => $result['title'] ?? 'Unknown',
                    'status' => $result['status'],
                    'book_id' => $result['book_id'] ?? null,
                    'process_time_ms' => $bookProcessTime
                ];

                if ($result['status'] === 'created') {
                    $pageStats['success']++;
                    Log::info("✅ کتاب ایجاد شد", [
                        'page' => $pageNumber,
                        'index' => $index + 1,
                        'title' => $result['title'],
                        'book_id' => $result['book_id']
                    ]);
                } elseif ($result['status'] === 'duplicate') {
                    $pageStats['duplicate']++;
                    Log::info("🔄 کتاب تکراری", [
                        'page' => $pageNumber,
                        'index' => $index + 1,
                        'title' => $result['title'],
                        'book_id' => $result['book_id']
                    ]);
                } elseif ($result['status'] === 'updated') {
                    $pageStats['success']++; // به عنوان موفقیت حساب می‌شود
                    Log::info("📝 کتاب بروزرسانی شد", [
                        'page' => $pageNumber,
                        'index' => $index + 1,
                        'title' => $result['title'],
                        'book_id' => $result['book_id']
                    ]);
                }

                $bookDetails[] = $bookDetail;

            } catch (\Exception $e) {
                $pageStats['failed']++;
                $bookProcessTime = round((microtime(true) - $bookStartTime) * 1000, 2);

                $bookDetails[] = [
                    'index' => $index + 1,
                    'title' => $bookData['title'] ?? 'Unknown',
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'process_time_ms' => $bookProcessTime
                ];

                Log::error('❌ خطا در پردازش کتاب', [
                    'page' => $pageNumber,
                    'book_index' => $index + 1,
                    'error' => $e->getMessage(),
                    'book_data' => $bookData
                ]);
            }

            // تاخیر بین رکوردها
            if ($this->config->delay_seconds > 0) {
                sleep($this->config->delay_seconds);
            }
        }

        // ثبت جزئیات کامل در ExecutionLog
        $executionLog->addLogEntry("✅ صفحه {$pageNumber} پردازش شد", [
            'page' => $pageNumber,
            'page_stats' => $pageStats,
            'books_found' => count($books),
            'book_details' => $bookDetails
        ]);

        Log::info("✅ پردازش کتاب‌های صفحه {$pageNumber} تمام شد", [
            'config_id' => $this->config->id,
            'page' => $pageNumber,
            'page_stats' => $pageStats,
            'books_processed' => count($books)
        ]);

        return $pageStats;
    }

    /**
     * تکمیل اجرا
     */
    private function completeExecution(ExecutionLog $executionLog): void
    {
        // آمار نهایی از کانفیگ
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
            'final_stats' => $finalStats
        ]);
    }

    /**
     * دریافت آمار کل از جدول کانفیگ
     */
    private function getConfigTotalStats(): array
    {
        $config = $this->config->fresh(); // اطمینان از آخرین داده‌ها

        return [
            'total' => $config->total_processed,
            'success' => $config->total_success,
            'failed' => $config->total_failed,
            'duplicate' => 0, // این باید از execution log محاسبه شود
            'execution_time' => 0 // این در execution log محاسبه می‌شود
        ];
    }

    /**
     * بروزرسانی آمار با tracking بهتر
     */
    public function updateProgress(int $currentPage, array $stats): void
    {
        // اضافه کردن آمار جدید به آمار قبلی
        $this->config->increment('total_processed', $stats['total'] ?? 0);
        $this->config->increment('total_success', $stats['success'] ?? 0);
        $this->config->increment('total_failed', $stats['failed'] ?? 0);

        // بروزرسانی صفحه فعلی و زمان آخرین اجرا
        $this->config->update([
            'current_page' => $currentPage,
            'last_run_at' => now(),
        ]);

        Log::info("📊 آمار کانفیگ بروزرسانی شد", [
            'config_id' => $this->config->id,
            'page' => $currentPage,
            'page_stats' => $stats,
            'total_stats' => [
                'total_processed' => $this->config->fresh()->total_processed,
                'total_success' => $this->config->fresh()->total_success,
                'total_failed' => $this->config->fresh()->total_failed
            ]
        ]);
    }

    private function processApiData(): void
    {
        $apiSettings = $this->config->getApiSettings();
        $generalSettings = $this->config->getGeneralSettings();
        $crawlingSettings = $this->config->getCrawlingSettings();

        $currentPage = $this->getCurrentPage($crawlingSettings);
        $hasMorePages = true;

        while ($hasMorePages && $currentPage <= ($crawlingSettings['max_pages'] ?? 1000)) {
            $this->executionLog->addLogEntry("پردازش صفحه {$currentPage}");

            $url = $this->buildApiUrl($apiSettings, $currentPage);
            $response = $this->makeHttpRequest($url, $apiSettings, $generalSettings);

            if (!$response->successful()) {
                throw new \Exception("خطای HTTP {$response->status()}: {$response->reason()}");
            }

            $data = $response->json();
            $books = $this->extractBooksFromApiData($data);

            if (empty($books)) {
                $hasMorePages = false;
                break;
            }

            $this->processBooksPage($books, $apiSettings['field_mapping'] ?? []);
            $this->config->updateProgress($currentPage, $this->stats);

            // تاخیر بین صفحات
            if ($this->config->page_delay > 0) {
                sleep($this->config->page_delay);
            }

            $currentPage++;
        }
    }

    private function getCurrentPage(array $crawlingSettings): int
    {
        $mode = $crawlingSettings['mode'] ?? 'continue';

        return match($mode) {
            'restart' => $crawlingSettings['start_page'] ?? 1,
            'update' => $crawlingSettings['start_page'] ?? 1,
            default => $this->config->current_page ?? ($crawlingSettings['start_page'] ?? 1)
        };
    }

    private function processBooksPage(array $books, array $fieldMapping): void
    {
        foreach ($books as $bookData) {
            $this->stats['total']++;

            try {
                $result = $this->createBook($bookData, $fieldMapping);

                if ($result['status'] === 'created') {
                    $this->stats['success']++;
                } elseif ($result['status'] === 'duplicate') {
                    $this->stats['duplicate']++;
                }

            } catch (\Exception $e) {
                $this->stats['failed']++;
                Log::error('خطا در پردازش کتاب', ['error' => $e->getMessage(), 'book_data' => $bookData]);
            }

            if ($this->config->delay_seconds > 0) {
                sleep($this->config->delay_seconds);
            }
        }
    }

    private function createBook(array $bookData, array $fieldMapping): array
    {
        $extractedData = $this->extractFieldsFromData($bookData, $fieldMapping);

        if (empty($extractedData['title'])) {
            throw new \Exception('عنوان کتاب یافت نشد');
        }

        $crawlingSettings = $this->config->getCrawlingSettings();
        if ($crawlingSettings['mode'] === 'update') {
            $existingBook = Book::where('title', $extractedData['title'])->first();
            if ($existingBook) {
                $this->updateExistingBook($existingBook, $extractedData);
                return ['status' => 'updated', 'title' => $extractedData['title'], 'book_id' => $existingBook->id];
            }
        } else {
            $existingBook = Book::where('title', $extractedData['title'])->first();
            if ($existingBook) {
                return ['status' => 'duplicate', 'title' => $extractedData['title'], 'book_id' => $existingBook->id];
            }
        }

        return $this->createNewBook($extractedData);
    }

    private function updateExistingBook(Book $book, array $extractedData): void
    {
        DB::transaction(function () use ($book, $extractedData) {
            $book->update([
                'description' => $extractedData['description'] ?? $book->description,
                'isbn' => $extractedData['isbn'] ?? $book->isbn,
                'publication_year' => $extractedData['publication_year'] ?? $book->publication_year,
                'pages_count' => $extractedData['pages_count'] ?? $book->pages_count,
                'file_size' => $extractedData['file_size'] ?? $book->file_size,
            ]);

            if (!empty($extractedData['image_url'])) {
                $this->processImages($book, $extractedData['image_url']);
            }
        });
    }

    private function createNewBook(array $extractedData): array
    {
        DB::beginTransaction();

        try {
            $category = $this->findOrCreateCategory($extractedData['category'] ?? 'عمومی');
            $publisher = null;

            if (!empty($extractedData['publisher'])) {
                $publisherName = $this->extractPublisherName($extractedData['publisher']);
                if ($publisherName) {
                    $publisher = $this->findOrCreatePublisher($publisherName);
                }
            }

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
                'content_hash' => md5($extractedData['title'] . time() . rand()),
                'category_id' => $category->id,
                'publisher_id' => $publisher?->id,
                'downloads_count' => 0,
                'status' => 'active'
            ]);

            if (!empty($extractedData['author'])) {
                $this->processAuthors($book, $extractedData['author']);
            }

            if (!empty($extractedData['image_url'])) {
                $this->processImages($book, $extractedData['image_url']);
            }

            DB::commit();
            return ['status' => 'created', 'title' => $book->title, 'book_id' => $book->id];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function buildApiUrl(array $apiSettings, int $page): string
    {
        $baseUrl = rtrim($this->config->base_url, '/');
        $endpoint = $apiSettings['endpoint'] ?? '';
        $fullUrl = $baseUrl . ($endpoint ? '/' . ltrim($endpoint, '/') : '');

        $params = ['page' => $page, 'limit' => $this->config->records_per_run];
        if (!empty($apiSettings['params'])) {
            $params = array_merge($params, $apiSettings['params']);
        }

        return $fullUrl . '?' . http_build_query($params);
    }

    private function makeHttpRequest(string $url, array $apiSettings, array $generalSettings)
    {
        $httpClient = Http::timeout($this->config->timeout)->retry(3, 1000);

        if (!empty($generalSettings['user_agent'])) {
            $httpClient = $httpClient->withUserAgent($generalSettings['user_agent']);
        }

        if (!($generalSettings['verify_ssl'] ?? true)) {
            $httpClient = $httpClient->withoutVerifying();
        }

        if (($apiSettings['auth_type'] ?? '') === 'bearer' && !empty($apiSettings['auth_token'])) {
            $httpClient = $httpClient->withToken($apiSettings['auth_token']);
        }

        return $httpClient->get($url);
    }

    private function extractBooksFromApiData(array $data): array
    {
        if (isset($data['status'], $data['data']['books']) && $data['status'] === 'success') {
            return $data['data']['books'];
        }

        $possibleKeys = ['data', 'books', 'results', 'items'];
        foreach ($possibleKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && !empty($data[$key])) {
                return $data[$key];
            }
        }

        return isset($data['title']) ? [$data] : [];
    }

    private function extractFieldsFromData(array $data, array $fieldMapping): array
    {
        if (empty($fieldMapping)) {
            $fieldMapping = [
                'title' => 'title',
                'description' => 'description_en',
                'author' => 'authors',
                'category' => 'category.name',
                'publisher' => 'publisher',
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

        if ($path === 'authors' && is_array($value)) {
            $names = [];
            foreach ($value as $author) {
                if (is_array($author) && isset($author['name'])) {
                    $names[] = $author['name'];
                } elseif (is_string($author)) {
                    $names[] = $author;
                }
            }
            return implode(', ', $names);
        }

        return $value;
    }

    private function sanitizeValue($value, string $fieldType)
    {
        if ($value === null) return null;

        return match($fieldType) {
            'title', 'description', 'author', 'category' => trim((string) $value),
            'publisher' => $this->extractPublisherName($value),
            'publication_year' => is_numeric($value) && $value >= 1000 && $value <= date('Y') + 5 ? (int) $value : null,
            'pages_count', 'file_size' => is_numeric($value) && $value > 0 ? (int) $value : null,
            'isbn' => preg_replace('/[^0-9X-]/', '', (string) $value),
            'language' => $this->normalizeLanguage((string) $value),
            'format' => $this->normalizeFormat((string) $value),
            'image_url' => filter_var(trim((string) $value), FILTER_VALIDATE_URL) ?: null,
            default => trim((string) $value)
        };
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
