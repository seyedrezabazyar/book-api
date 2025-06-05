<?php

namespace App\Services;

use App\Models\Config;
use App\Models\Book;
use App\Models\Author;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\BookImage;
use App\Models\BookHash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

/**
 * سرویس API با نقشه‌برداری صحیح فیلدها
 */
class ApiDataService
{
    private Config $config;
    private array $stats = [
        'total' => 0,
        'success' => 0,
        'failed' => 0,
        'duplicate' => 0
    ];

    public function __construct(Config $config)
    {
        $this->config = $config;

        Log::info("🚀 ApiDataService ایجاد شد", [
            'config_id' => $this->config->id,
            'config_name' => $this->config->name,
            'version' => 'CORRECTED_V2.0'
        ]);
    }

    /**
     * اجرای فرآیند دریافت اطلاعات از API
     */
    public function fetchData(): array
    {
        Log::info("📡 شروع fetchData", [
            'config_id' => $this->config->id,
            'config_name' => $this->config->name
        ]);

        try {
            $apiSettings = $this->config->getApiSettings();
            $generalSettings = $this->config->getGeneralSettings();

            Log::info("⚙️ تنظیمات دریافت شده", [
                'config_id' => $this->config->id,
                'api_settings' => $apiSettings,
                'field_mapping' => $apiSettings['field_mapping'] ?? 'پیش‌فرض'
            ]);

            $recordsToProcess = min($this->config->records_per_run, 50);
            $this->processApiPage($apiSettings, $generalSettings, $recordsToProcess);

        } catch (\Exception $e) {
            Log::error("❌ خطا در fetchData", [
                'config_id' => $this->config->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }

        Log::info("✅ پایان fetchData", [
            'config_id' => $this->config->id,
            'stats' => $this->stats
        ]);

        return $this->stats;
    }

    /**
     * پردازش یک صفحه از API
     */
    private function processApiPage(array $apiSettings, array $generalSettings, int $limit): void
    {
        // ساخت URL
        $fullUrl = $this->buildApiUrl($apiSettings, $limit);

        Log::info("🌐 درخواست به API", [
            'config_id' => $this->config->id,
            'url' => $fullUrl,
            'limit' => $limit
        ]);

        // ارسال درخواست
        $response = $this->makeHttpRequest($fullUrl, $apiSettings, $generalSettings);

        if (!$response->successful()) {
            throw new \Exception("خطای HTTP {$response->status()}: {$response->reason()}");
        }

        $data = $response->json();

        Log::info("📊 پاسخ API دریافت شد", [
            'config_id' => $this->config->id,
            'response_keys' => array_keys($data),
            'status' => $data['status'] ?? 'نامشخص'
        ]);

        // استخراج کتاب‌ها
        $books = $this->extractBooksFromApiData($data);

        Log::info("📚 کتاب‌های استخراج شده", [
            'config_id' => $this->config->id,
            'books_count' => count($books),
            'sample_book_keys' => !empty($books) ? array_keys($books[0]) : [],
            'first_book_title' => $books[0]['title'] ?? 'نامشخص'
        ]);

        if (empty($books)) {
            Log::warning("⚠️ هیچ کتابی یافت نشد", [
                'config_id' => $this->config->id,
                'raw_response' => $data
            ]);
            return;
        }

        // پردازش هر کتاب
        foreach ($books as $index => $bookData) {
            $this->stats['total']++;

            try {
                Log::info("📖 شروع پردازش کتاب", [
                    'config_id' => $this->config->id,
                    'book_index' => $index,
                    'book_title' => $bookData['title'] ?? 'نامشخص',
                    'book_keys' => array_keys($bookData)
                ]);

                $result = $this->createBookDirectly($bookData, $apiSettings['field_mapping'] ?? [], $index);

                if ($result['status'] === 'created') {
                    $this->stats['success']++;
                    Log::info("✅ کتاب ایجاد شد", [
                        'config_id' => $this->config->id,
                        'book_id' => $result['book_id'],
                        'title' => $result['title']
                    ]);
                }

            } catch (\Exception $e) {
                $this->stats['failed']++;
                Log::error("💥 خطا در پردازش کتاب", [
                    'config_id' => $this->config->id,
                    'book_index' => $index,
                    'book_title' => $bookData['title'] ?? 'نامشخص',
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
        }
    }

    /**
     * ایجاد مستقیم کتاب
     */
    private function createBookDirectly(array $bookData, array $fieldMapping, int $index): array
    {
        // استخراج فیلدها با نقشه‌برداری صحیح
        $extractedData = $this->extractFieldsFromData($bookData, $fieldMapping);

        Log::info("🔍 فیلدهای استخراج شده", [
            'config_id' => $this->config->id,
            'book_index' => $index,
            'extracted_data' => $extractedData,
            'field_mapping_used' => $fieldMapping
        ]);

        if (empty($extractedData['title'])) {
            Log::error("❌ عنوان کتاب یافت نشد", [
                'config_id' => $this->config->id,
                'raw_book_data' => $bookData,
                'extracted_data' => $extractedData
            ]);
            throw new \Exception('عنوان کتاب یافت نشد');
        }

        // تولید hash یکتا
        $uniqueHash = md5($this->config->id . '_' . $extractedData['title'] . '_' . time() . '_' . rand(1000, 9999));

        DB::beginTransaction();

        try {
            // ایجاد دسته‌بندی
            $categoryName = $extractedData['category'] ?? 'عمومی';
            $category = $this->findOrCreateCategory($categoryName);

            Log::info("📂 دسته‌بندی آماده", [
                'config_id' => $this->config->id,
                'category_name' => $categoryName,
                'category_id' => $category->id
            ]);

            // ایجاد ناشر
            $publisher = null;
            if (!empty($extractedData['publisher'])) {
                $publisher = $this->findOrCreatePublisher($extractedData['publisher']);
                Log::info("🏢 ناشر آماده", [
                    'config_id' => $this->config->id,
                    'publisher_name' => $extractedData['publisher'],
                    'publisher_id' => $publisher->id
                ]);
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
                'content_hash' => $uniqueHash,
                'category_id' => $category->id,
                'publisher_id' => $publisher?->id,
                'downloads_count' => 0,
                'status' => 'active'
            ]);

            Log::info("📚 کتاب در دیتابیس ایجاد شد", [
                'config_id' => $this->config->id,
                'book_id' => $book->id,
                'title' => $book->title
            ]);

            // پردازش نویسندگان
            if (!empty($extractedData['author'])) {
                $this->processAuthors($book, $extractedData['author']);
            }

            // پردازش تصاویر
            if (!empty($extractedData['image_url'])) {
                $this->processImages($book, $extractedData['image_url']);
            }

            // پردازش hash ها
            $this->processHashes($book, $uniqueHash);

            DB::commit();

            return [
                'status' => 'created',
                'title' => $book->title,
                'book_id' => $book->id,
                'hash' => $uniqueHash
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("💥 Rollback - خطا در ایجاد کتاب", [
                'config_id' => $this->config->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw $e;
        }
    }

    private function buildApiUrl(array $apiSettings, int $limit): string
    {
        $baseUrl = rtrim($this->config->base_url, '/');
        $endpoint = $apiSettings['endpoint'] ?? '';
        $fullUrl = $baseUrl . ($endpoint ? '/' . ltrim($endpoint, '/') : '');

        // استفاده از page به جای limit برای API balyan.ir
        $params = ['page' => 1, 'limit' => $limit];

        if (!empty($apiSettings['params'])) {
            $params = array_merge($params, $apiSettings['params']);
        }

        return $fullUrl . '?' . http_build_query($params);
    }

    private function makeHttpRequest(string $url, array $apiSettings, array $generalSettings)
    {
        $httpClient = Http::timeout($this->config->timeout)
            ->retry($this->config->max_retries, 1);

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
                $firstItem = $data[$key][0] ?? null;
                if (is_array($firstItem) && (isset($firstItem['title']) || isset($firstItem['id']))) {
                    return $data[$key];
                }
            }
        }

        if (isset($data['title']) || isset($data['id'])) {
            return [$data];
        }

        return [];
    }

    private function extractFieldsFromData(array $data, array $fieldMapping): array
    {
        // اگر نقشه‌برداری خالی است، از پیش‌فرض استفاده کن
        if (empty($fieldMapping)) {
            $fieldMapping = $this->getDefaultFieldMapping();
        }

        Log::info("🗺️ نقشه‌برداری فیلدها", [
            'config_id' => $this->config->id,
            'field_mapping' => $fieldMapping
        ]);

        $extracted = [];
        foreach ($fieldMapping as $bookField => $apiField) {
            if (empty($apiField)) continue;

            $value = $this->getNestedValue($data, $apiField);

            Log::debug("🔍 استخراج فیلد", [
                'book_field' => $bookField,
                'api_field' => $apiField,
                'extracted_value' => $value
            ]);

            if ($value !== null) {
                $extracted[$bookField] = $this->sanitizeValue($value, $bookField);
            }
        }

        return $extracted;
    }

    /**
     * نقشه‌برداری پیش‌فرض مطابق ساختار balyan.ir
     */
    private function getDefaultFieldMapping(): array
    {
        return [
            'title' => 'title',
            'description' => 'description_en',
            'author' => 'authors', // آرایه از نویسندگان
            'category' => 'category.name',
            'publisher' => 'publisher.name',
            'isbn' => 'isbn',
            'publication_year' => 'publication_year',
            'pages_count' => 'pages_count',
            'language' => 'language',
            'format' => 'format',
            'file_size' => 'file_size',
            'image_url' => 'image_url.0' // اولین تصویر از آرایه
        ];
    }

    private function getNestedValue(array $data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (is_array($value)) {
                if (is_numeric($key)) {
                    $key = (int) $key;
                    $value = $value[$key] ?? null;
                } else {
                    $value = $value[$key] ?? null;
                }
            } else {
                return null;
            }
        }

        // پردازش خاص برای نویسندگان balyan.ir
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

        // پردازش تصاویر
        if (str_contains($path, 'image_url') && is_array($value)) {
            return $value[0] ?? null;
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
            case 'publisher':
            case 'category':
                return is_string($value) ? trim($value) : (string) $value;
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

    private function findOrCreateCategory(string $categoryName): Category
    {
        $slug = Str::slug($categoryName . '_' . time());
        return Category::firstOrCreate(
            ['name' => $categoryName],
            [
                'slug' => $slug,
                'is_active' => true,
                'books_count' => 0
            ]
        );
    }

    private function findOrCreatePublisher(string $publisherName): Publisher
    {
        $slug = Str::slug($publisherName . '_' . time());
        return Publisher::firstOrCreate(
            ['name' => $publisherName],
            [
                'slug' => $slug,
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

    private function processHashes(Book $book, string $contentHash): void
    {
        BookHash::create([
            'book_id' => $book->id,
            'book_hash' => $contentHash,
            'md5' => $contentHash,
            'sha1' => sha1($book->title . $book->description),
            'sha256' => hash('sha256', $book->title . $book->description)
        ]);
    }

    public function getStats(): array
    {
        return $this->stats;
    }
}
