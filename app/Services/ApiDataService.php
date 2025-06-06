<?php

namespace App\Services;

use App\Models\Config;
use App\Models\Book;
use App\Models\Author;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\BookImage;
use App\Models\ExecutionLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ApiDataService
{
    private Config $config;
    private ExecutionLog $executionLog;
    private array $stats = [
        'total' => 0,
        'success' => 0,
        'failed' => 0,
        'duplicate' => 0
    ];

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function fetchData(): array
    {
        // ایجاد لاگ اجرا
        $this->executionLog = ExecutionLog::createNew($this->config);
        $this->executionLog->addLogEntry("🚀 شروع اجرای فوری", [
            'config_name' => $this->config->name,
            'records_per_run' => $this->config->records_per_run
        ]);

        $startTime = microtime(true);

        try {
            $this->processApiData();

            $executionTime = round(microtime(true) - $startTime, 2);
            $this->stats['execution_time'] = $executionTime;

            $this->executionLog->addLogEntry("✅ اجرا با موفقیت تمام شد", $this->stats);
            $this->executionLog->markCompleted($this->stats);

            Log::info("✅ اجرای فوری موفق", [
                'config_id' => $this->config->id,
                'stats' => $this->stats,
                'execution_time' => $executionTime
            ]);

        } catch (\Exception $e) {
            $executionTime = round(microtime(true) - $startTime, 2);
            $this->stats['execution_time'] = $executionTime;

            $this->executionLog->addLogEntry("❌ خطا در اجرا", [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            $this->executionLog->markFailed($e->getMessage());

            Log::error("❌ خطا در اجرای فوری", [
                'config_id' => $this->config->id,
                'error' => $e->getMessage(),
                'stats' => $this->stats
            ]);

            throw $e;
        }

        return $this->stats;
    }

    private function processApiData(): void
    {
        $apiSettings = $this->config->getApiSettings();
        $generalSettings = $this->config->getGeneralSettings();

        $this->executionLog->addLogEntry("📡 شروع دریافت از API", [
            'base_url' => $this->config->base_url,
            'endpoint' => $apiSettings['endpoint'] ?? '',
            'limit' => $this->config->records_per_run
        ]);

        // ساخت URL
        $fullUrl = $this->buildApiUrl($apiSettings, $this->config->records_per_run);

        // ارسال درخواست
        $response = $this->makeHttpRequest($fullUrl, $apiSettings, $generalSettings);

        if (!$response->successful()) {
            throw new \Exception("خطای HTTP {$response->status()}: {$response->reason()}");
        }

        $data = $response->json();

        $this->executionLog->addLogEntry("📊 پاسخ API دریافت شد", [
            'response_status' => $response->status(),
            'response_keys' => array_keys($data),
            'data_type' => gettype($data)
        ]);

        // استخراج کتاب‌ها
        $books = $this->extractBooksFromApiData($data);

        $this->executionLog->addLogEntry("📚 کتاب‌ها استخراج شدند", [
            'books_count' => count($books),
            'first_book_keys' => !empty($books) ? array_keys($books[0]) : []
        ]);

        if (empty($books)) {
            $this->executionLog->addLogEntry("⚠️ هیچ کتابی یافت نشد", ['raw_data' => $data]);
            return;
        }

        // پردازش هر کتاب
        foreach ($books as $index => $bookData) {
            $this->stats['total']++;

            try {
                $this->executionLog->addLogEntry("📖 پردازش کتاب {$index}", [
                    'book_title' => $bookData['title'] ?? 'نامشخص'
                ]);

                $result = $this->createBook($bookData, $apiSettings['field_mapping'] ?? []);

                if ($result['status'] === 'created') {
                    $this->stats['success']++;
                    $this->executionLog->addLogEntry("✅ کتاب ایجاد شد", [
                        'book_id' => $result['book_id'],
                        'title' => $result['title']
                    ]);
                } elseif ($result['status'] === 'duplicate') {
                    $this->stats['duplicate']++;
                    $this->executionLog->addLogEntry("🔄 کتاب تکراری", [
                        'title' => $result['title']
                    ]);
                }

            } catch (\Exception $e) {
                $this->stats['failed']++;
                $this->executionLog->addLogEntry("💥 خطا در پردازش کتاب {$index}", [
                    'error' => $e->getMessage(),
                    'book_data' => $bookData
                ]);
            }
        }
    }

    private function createBook(array $bookData, array $fieldMapping): array
    {
        $extractedData = $this->extractFieldsFromData($bookData, $fieldMapping);

        if (empty($extractedData['title'])) {
            throw new \Exception('عنوان کتاب یافت نشد');
        }

        // بررسی تکراری
        $existingBook = Book::where('title', $extractedData['title'])->first();
        if ($existingBook) {
            return [
                'status' => 'duplicate',
                'title' => $extractedData['title'],
                'book_id' => $existingBook->id
            ];
        }

        DB::beginTransaction();

        try {
            // ایجاد دسته‌بندی
            $category = $this->findOrCreateCategory($extractedData['category'] ?? 'عمومی');

            // ایجاد ناشر
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
                'content_hash' => md5($extractedData['title'] . time() . rand()),
                'category_id' => $category->id,
                'publisher_id' => $publisher?->id,
                'downloads_count' => 0,
                'status' => 'active'
            ]);

            // پردازش نویسندگان
            if (!empty($extractedData['author'])) {
                $this->processAuthors($book, $extractedData['author']);
            }

            // پردازش تصاویر
            if (!empty($extractedData['image_url'])) {
                $this->processImages($book, $extractedData['image_url']);
            }

            DB::commit();

            return [
                'status' => 'created',
                'title' => $book->title,
                'book_id' => $book->id
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function buildApiUrl(array $apiSettings, int $limit): string
    {
        $baseUrl = rtrim($this->config->base_url, '/');
        $endpoint = $apiSettings['endpoint'] ?? '';
        $fullUrl = $baseUrl . ($endpoint ? '/' . ltrim($endpoint, '/') : '');

        $params = ['page' => 1, 'limit' => $limit];
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
        // ساختار خاص balyan.ir
        if (isset($data['status'], $data['data']['books']) && $data['status'] === 'success') {
            return $data['data']['books'];
        }

        // ساختارهای متداول دیگر
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
                if (is_numeric($key)) {
                    $value = $value[(int)$key] ?? null;
                } else {
                    $value = $value[$key] ?? null;
                }
            } else {
                return null;
            }
        }

        // پردازش خاص برای نویسندگان
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

        switch ($fieldType) {
            case 'title':
            case 'description':
            case 'author':
            case 'category':
                return is_string($value) ? trim($value) : (string) $value;

            case 'publisher':
                return $this->extractPublisherName($value);

            case 'publication_year':
                if (is_numeric($value)) {
                    $year = (int) $value;
                    return ($year >= 1000 && $year <= date('Y') + 5) ? $year : null;
                }
                return null;

            case 'pages_count':
            case 'file_size':
                return is_numeric($value) && $value > 0 ? (int) $value : null;

            case 'isbn':
                return preg_replace('/[^0-9X-]/', '', (string) $value);

            case 'language':
                $language = strtolower(trim((string) $value));
                $langMap = ['persian' => 'fa', 'english' => 'en', 'فارسی' => 'fa'];
                return $langMap[$language] ?? substr($language, 0, 2);

            case 'format':
                $format = strtolower(trim((string) $value));
                $allowedFormats = ['pdf', 'epub', 'mobi', 'djvu'];
                return in_array($format, $allowedFormats) ? $format : 'pdf';

            case 'image_url':
                $url = trim((string) $value);
                return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;

            default:
                return trim((string) $value);
        }
    }

    private function extractPublisherName($publisherData): ?string
    {
        if (is_string($publisherData)) {
            return trim($publisherData);
        }

        if (is_array($publisherData)) {
            if (isset($publisherData['name'])) {
                return trim($publisherData['name']);
            }
            foreach ($publisherData as $value) {
                if (is_string($value) && !empty(trim($value))) {
                    return trim($value);
                }
            }
        }

        return null;
    }

    private function findOrCreateCategory(string $categoryName): Category
    {
        return Category::firstOrCreate(
            ['name' => $categoryName],
            [
                'slug' => Str::slug($categoryName . '_' . time()),
                'is_active' => true,
                'books_count' => 0
            ]
        );
    }

    private function findOrCreatePublisher(string $publisherName): Publisher
    {
        return Publisher::firstOrCreate(
            ['name' => $publisherName],
            [
                'slug' => Str::slug($publisherName . '_' . time()),
                'is_active' => true,
                'books_count' => 0
            ]
        );
    }

    private function processAuthors(Book $book, string $authorString): void
    {
        $authorNames = array_map('trim', explode(',', $authorString));
        foreach ($authorNames as $authorName) {
            if (empty($authorName)) continue;
            $author = Author::firstOrCreate(
                ['name' => $authorName],
                [
                    'slug' => Str::slug($authorName . '_' . time()),
                    'is_active' => true,
                    'books_count' => 0
                ]
            );
            $book->authors()->attach($author->id);
        }
    }

    private function processImages(Book $book, string $imageUrl): void
    {
        if (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            BookImage::create([
                'book_id' => $book->id,
                'image_url' => $imageUrl
            ]);
        }
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * تست URL خاص برای نمایش در صفحه تست
     */
    public function testUrl(string $testUrl): array
    {
        try {
            $apiSettings = $this->config->getApiSettings();
            $generalSettings = $this->config->getGeneralSettings();

            $response = $this->makeHttpRequest($testUrl, $apiSettings, $generalSettings);

            if (!$response->successful()) {
                throw new \Exception("خطای HTTP {$response->status()}: {$response->reason()}");
            }

            $data = $response->json();
            $books = $this->extractBooksFromApiData($data);

            if (empty($books)) {
                throw new \Exception('هیچ کتابی در پاسخ یافت نشد');
            }

            $firstBook = $books[0];
            $extractedData = $this->extractFieldsFromData($firstBook, $apiSettings['field_mapping'] ?? []);

            return [
                'config_name' => $this->config->name,
                'test_url' => $testUrl,
                'response_status' => $response->status(),
                'total_books_found' => count($books),
                'extracted_data' => $extractedData,
                'raw_data' => $firstBook
            ];

        } catch (\Exception $e) {
            throw new \Exception("خطا در تست URL: " . $e->getMessage());
        }
    }
}
