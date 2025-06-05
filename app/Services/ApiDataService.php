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
use Illuminate\Support\Facades\Cache;

/**
 * سرویس بهبود یافته دریافت و پردازش اطلاعات از API
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
    }

    /**
     * اجرای فرآیند دریافت اطلاعات از API
     */
    public function fetchData(): array
    {
        if (!$this->config->isApiSource()) {
            throw new \InvalidArgumentException('کانفیگ مشخص شده از نوع API نیست');
        }

        Log::info("شروع دریافت اطلاعات از API", [
            'config_name' => $this->config->name,
            'config_id' => $this->config->id,
            'base_url' => $this->config->base_url
        ]);

        try {
            $apiSettings = $this->config->getApiSettings();
            $generalSettings = $this->config->getGeneralSettings();

            // دریافت داده‌ها از صفحات مختلف (در صورت وجود pagination)
            $this->fetchAllPages($apiSettings, $generalSettings);

        } catch (\Exception $e) {
            Log::error("خطا در دریافت اطلاعات از API", [
                'config_name' => $this->config->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // ذخیره خطا در cache برای نمایش در UI
            Cache::put("config_error_{$this->config->id}", [
                'message' => $e->getMessage(),
                'time' => now()->toDateTimeString(),
                'details' => [
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]
            ], 86400);

            throw $e;
        }

        Log::info("پایان دریافت اطلاعات از API", [
            'config_name' => $this->config->name,
            'stats' => $this->stats
        ]);

        return $this->stats;
    }

    /**
     * تست URL مشخص
     */
    public function testUrl(string $testUrl): array
    {
        Log::info("تست API URL", [
            'config_name' => $this->config->name,
            'test_url' => $testUrl
        ]);

        try {
            $apiSettings = $this->config->getApiSettings();
            $generalSettings = $this->config->getGeneralSettings();

            // ارسال درخواست HTTP
            $response = $this->makeHttpRequest($testUrl, $apiSettings, $generalSettings);

            if (!$response->successful()) {
                throw new \Exception("خطای HTTP {$response->status()}: " . $response->reason());
            }

            $data = $response->json();

            // تحلیل ساختار داده
            $analysis = $this->analyzeApiStructure($data);

            // استخراج اولین کتاب برای نمایش
            $books = $this->extractBooksFromData($data);
            $firstBook = !empty($books) ? $books[0] : null;

            // استخراج فیلدها از اولین کتاب
            $extractedData = [];
            if ($firstBook) {
                $extractedData = $this->extractFieldsFromData($firstBook, $apiSettings['field_mapping'] ?? []);
            }

            return [
                'config_name' => $this->config->name,
                'source_type' => 'API',
                'test_url' => $testUrl,
                'response_status' => $response->status(),
                'total_books_found' => count($books),
                'structure_analysis' => $analysis,
                'extracted_data' => $extractedData,
                'raw_data' => $firstBook,
                'sample_data' => $this->getSampleData($data)
            ];

        } catch (\Exception $e) {
            throw new \Exception("خطا در تست API: " . $e->getMessage());
        }
    }

    /**
     * Debug API call
     */
    public function debugApiCall(): array
    {
        $apiSettings = $this->config->getApiSettings();
        $generalSettings = $this->config->getGeneralSettings();

        // ساخت URL کامل
        $fullUrl = $this->buildFullUrl($apiSettings, 1);

        Log::info("Debug API call", [
            'url' => $fullUrl,
            'method' => $apiSettings['method'] ?? 'GET'
        ]);

        try {
            $response = $this->makeHttpRequest($fullUrl, $apiSettings, $generalSettings);

            $debugData = [
                'request' => [
                    'url' => $fullUrl,
                    'method' => $apiSettings['method'] ?? 'GET',
                    'timeout' => $this->config->timeout,
                    'auth_type' => $apiSettings['auth_type'] ?? 'none'
                ]
            ];

            if ($response->successful()) {
                $data = $response->json();

                // تحلیل ساختار
                $debugData['data_analysis'] = $this->analyzeApiStructure($data);

                // استخراج کتاب‌ها
                $books = $this->extractBooksFromData($data);

                $debugData['extracted_books'] = [
                    'count' => count($books),
                    'first_book' => !empty($books) ? $books[0] : null
                ];

                // تست استخراج فیلدها
                if (!empty($books)) {
                    $debugData['extracted_books']['sample_extraction'] = $this->testFieldExtraction($books[0], $apiSettings['field_mapping'] ?? []);
                }

            } else {
                $debugData['error'] = [
                    'status' => $response->status(),
                    'body' => $response->body()
                ];
            }

            return $debugData;

        } catch (\Exception $e) {
            throw new \Exception("خطا در debug API: " . $e->getMessage());
        }
    }

    /**
     * تحلیل ساختار API
     */
    private function analyzeApiStructure(array $data): array
    {
        $analysis = [
            'root_keys' => array_keys($data),
            'structure_type' => 'unknown',
            'potential_book_paths' => [],
            'book_count' => 0,
            'sample_item_keys' => []
        ];

        // شناسایی ساختار balyan.ir
        if (isset($data['status']) && $data['status'] === 'success' && isset($data['data']['books'])) {
            $analysis['structure_type'] = 'balyan_ir';
            $analysis['potential_book_paths'] = ['data.books'];
            $analysis['book_count'] = count($data['data']['books']);

            if (!empty($data['data']['books'])) {
                $analysis['sample_item_keys'] = array_keys($data['data']['books'][0]);
            }
        } else {
            // بررسی سایر ساختارها
            $possibleKeys = ['data', 'books', 'results', 'items', 'list', 'content'];

            foreach ($possibleKeys as $key) {
                if (isset($data[$key]) && is_array($data[$key]) && !empty($data[$key])) {
                    $firstItem = $data[$key][0] ?? null;
                    if (is_array($firstItem) && (isset($firstItem['title']) || isset($firstItem['id']))) {
                        $analysis['structure_type'] = 'standard';
                        $analysis['potential_book_paths'][] = $key;
                        $analysis['book_count'] = count($data[$key]);
                        $analysis['sample_item_keys'] = array_keys($firstItem);
                        break;
                    }
                }
            }
        }

        return $analysis;
    }

    /**
     * تست استخراج فیلدها
     */
    private function testFieldExtraction(array $bookData, array $fieldMapping): array
    {
        $result = [
            'extracted_fields' => [],
            'errors' => [],
            'available_keys' => $this->getAllKeys($bookData)
        ];

        // استفاده از نقشه‌برداری پیش‌فرض اگر خالی باشد
        if (empty($fieldMapping)) {
            $fieldMapping = $this->getDefaultFieldMapping();
        }

        foreach ($fieldMapping as $bookField => $apiField) {
            try {
                $value = $this->getNestedValue($bookData, $apiField);
                $result['extracted_fields'][$bookField] = [
                    'path' => $apiField,
                    'found' => $value !== null,
                    'type' => gettype($value),
                    'raw_value' => $value
                ];
            } catch (\Exception $e) {
                $result['errors'][$bookField] = [
                    'path' => $apiField,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $result;
    }

    /**
     * دریافت همه کلیدها از داده‌های nested
     */
    private function getAllKeys(array $data, string $prefix = ''): array
    {
        $keys = [];

        foreach ($data as $key => $value) {
            $fullKey = $prefix ? $prefix . '.' . $key : $key;
            $keys[] = $fullKey;

            if (is_array($value) && !is_numeric(array_keys($value)[0] ?? '')) {
                $keys = array_merge($keys, $this->getAllKeys($value, $fullKey));
            }
        }

        return $keys;
    }

    /**
     * دریافت اطلاعات از تمام صفحات
     */
    private function fetchAllPages(array $apiSettings, array $generalSettings): void
    {
        $currentPage = 1;
        $maxPages = 10; // محدودیت امنیتی
        $hasMorePages = true;

        while ($hasMorePages && $currentPage <= $maxPages) {
            Log::info("دریافت صفحه {$currentPage}");

            try {
                // ساخت URL کامل با شماره صفحه
                $fullUrl = $this->buildFullUrl($apiSettings, $currentPage);

                Log::info("درخواست به URL", ['url' => $fullUrl]);

                // ارسال درخواست HTTP
                $response = $this->makeHttpRequest($fullUrl, $apiSettings, $generalSettings);

                if ($response->successful()) {
                    $data = $response->json();

                    Log::info("پاسخ دریافت شد", [
                        'status' => $response->status(),
                        'data_keys' => array_keys($data),
                        'data_sample' => $this->getSampleData($data)
                    ]);

                    // پردازش داده‌های صفحه
                    $pageBooks = $this->processApiData($data, $apiSettings['field_mapping'] ?? []);

                    // بررسی وجود صفحه بعد
                    $hasMorePages = $this->hasNextPage($data, $pageBooks);

                    if ($pageBooks === 0) {
                        Log::info("هیچ کتابی در صفحه {$currentPage} یافت نشد، توقف pagination");
                        break;
                    }

                } else {
                    Log::error("خطا در درخواست HTTP", [
                        'status' => $response->status(),
                        'body' => $response->body()
                    ]);
                    throw new \Exception("خطا در دریافت اطلاعات: HTTP {$response->status()}");
                }

                $currentPage++;

                // تاخیر بین درخواست‌ها
                if ($this->config->delay_seconds > 0) {
                    sleep($this->config->delay_seconds);
                }

            } catch (\Exception $e) {
                Log::error("خطا در پردازش صفحه {$currentPage}", [
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }
    }

    /**
     * ساخت URL کامل برای درخواست API با pagination
     */
    private function buildFullUrl(array $apiSettings, int $page = 1): string
    {
        $baseUrl = rtrim($this->config->base_url, '/');
        $endpoint = ltrim($apiSettings['endpoint'] ?? '', '/');
        $fullUrl = $baseUrl . ($endpoint ? '/' . $endpoint : '');

        // اضافه کردن پارامتر صفحه
        $params = $apiSettings['params'] ?? [];
        $params['page'] = $page;

        if (!empty($params)) {
            $separator = strpos($fullUrl, '?') !== false ? '&' : '?';
            $fullUrl .= $separator . http_build_query($params);
        }

        return $fullUrl;
    }

    /**
     * ارسال درخواست HTTP به API
     */
    private function makeHttpRequest(string $url, array $apiSettings, array $generalSettings)
    {
        $httpClient = Http::timeout($this->config->timeout)
            ->retry($this->config->max_retries, $this->config->delay_seconds);

        // تنظیم User Agent
        if (!empty($generalSettings['user_agent'])) {
            $httpClient = $httpClient->withUserAgent($generalSettings['user_agent']);
        }

        // تنظیم SSL verification
        if (!($generalSettings['verify_ssl'] ?? true)) {
            $httpClient = $httpClient->withoutVerifying();
        }

        // تنظیم احراز هویت
        if (($apiSettings['auth_type'] ?? '') === 'bearer' && !empty($apiSettings['auth_token'])) {
            $httpClient = $httpClient->withToken($apiSettings['auth_token']);
        } elseif (($apiSettings['auth_type'] ?? '') === 'basic' && !empty($apiSettings['auth_token'])) {
            $credentials = explode(':', $apiSettings['auth_token'], 2);
            if (count($credentials) === 2) {
                $httpClient = $httpClient->withBasicAuth($credentials[0], $credentials[1]);
            }
        }

        // افزودن headers سفارشی
        if (!empty($apiSettings['headers'])) {
            $httpClient = $httpClient->withHeaders($apiSettings['headers']);
        }

        // ارسال درخواست
        if (($apiSettings['method'] ?? 'GET') === 'GET') {
            return $httpClient->get($url);
        } elseif ($apiSettings['method'] === 'POST') {
            return $httpClient->post($url);
        }

        throw new \InvalidArgumentException("متد HTTP پشتیبانی نشده: {$apiSettings['method']}");
    }

    /**
     * دریافت نمونه داده برای لاگ
     */
    private function getSampleData($data): array
    {
        if (is_array($data)) {
            $sample = [];
            foreach (array_slice(array_keys($data), 0, 5) as $key) {
                if (is_array($data[$key])) {
                    $sample[$key] = '[Array with ' . count($data[$key]) . ' items]';
                } else {
                    $sample[$key] = Str::limit((string)$data[$key], 50);
                }
            }
            return $sample;
        }
        return ['type' => gettype($data)];
    }

    /**
     * پردازش داده‌های دریافت شده از API
     */
    private function processApiData(array $data, array $fieldMapping): int
    {
        // استخراج کتاب‌ها از ساختار API
        $books = $this->extractBooksFromData($data);

        Log::info("کتاب‌های استخراج شده", [
            'total_books' => count($books),
            'first_book_sample' => !empty($books) ? $this->getSampleData($books[0]) : null
        ]);

        if (empty($books)) {
            Log::warning("هیچ کتابی در پاسخ API یافت نشد", [
                'data_structure' => array_keys($data),
                'data_sample' => $this->getSampleData($data)
            ]);
            return 0;
        }

        $processedCount = 0;

        foreach ($books as $index => $bookData) {
            $this->stats['total']++;

            try {
                Log::debug("پردازش کتاب شماره " . ($index + 1), [
                    'book_sample' => $this->getSampleData($bookData)
                ]);

                $this->processBookData($bookData, $fieldMapping);
                $this->stats['success']++;
                $processedCount++;

            } catch (\Exception $e) {
                $this->stats['failed']++;
                Log::warning("خطا در پردازش کتاب شماره " . ($index + 1), [
                    'error' => $e->getMessage(),
                    'book_data_sample' => $this->getSampleData($bookData),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        return $processedCount;
    }

    /**
     * استخراج لیست کتاب‌ها از داده‌های API
     */
    private function extractBooksFromData(array $data): array
    {
        // برای API balyan.ir
        if (isset($data['status']) && $data['status'] === 'success' && isset($data['data']['books'])) {
            Log::info("ساختار API balyan.ir شناسایی شد");
            return $data['data']['books'];
        }

        // سایر ساختارهای متداول
        $possibleKeys = ['data', 'books', 'results', 'items', 'list', 'content'];
        foreach ($possibleKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && !empty($data[$key])) {
                // بررسی اینکه آیا اولین element یک کتاب است
                $firstItem = $data[$key][0] ?? null;
                if (is_array($firstItem) && (isset($firstItem['title']) || isset($firstItem['id']))) {
                    Log::info("ساختار API با کلید '{$key}' شناسایی شد");
                    return $data[$key];
                }
            }
        }

        // اگر خود data یک آرایه از کتاب‌ها باشد
        if (isset($data[0]) && is_array($data[0]) && (isset($data[0]['title']) || isset($data[0]['id']))) {
            Log::info("ساختار API مستقیم شناسایی شد");
            return $data;
        }

        // اگر یک کتاب منفرد است
        if (isset($data['title']) || isset($data['id'])) {
            Log::info("کتاب منفرد شناسایی شد");
            return [$data];
        }

        Log::error("ساختار API شناسایی نشد", [
            'available_keys' => array_keys($data),
            'data_sample' => $this->getSampleData($data)
        ]);

        return [];
    }

    /**
     * بررسی وجود صفحه بعد
     */
    private function hasNextPage(array $data, int $currentPageBooks): bool
    {
        // اگر تعداد کتاب‌های صفحه کمتر از حد انتظار باشد
        if ($currentPageBooks < 50) { // معمولاً هر صفحه 100 کتاب دارد
            return false;
        }

        // بررسی فیلدهای pagination در پاسخ
        if (isset($data['pagination'])) {
            return $data['pagination']['has_next'] ?? false;
        }

        if (isset($data['meta'])) {
            return isset($data['meta']['next_page']) ||
                (isset($data['meta']['current_page']) && isset($data['meta']['last_page']) &&
                    $data['meta']['current_page'] < $data['meta']['last_page']);
        }

        // پیش‌فرض: ادامه تا زمانی که کتاب موجود باشد
        return $currentPageBooks > 0;
    }

    /**
     * پردازش اطلاعات یک کتاب
     */
    private function processBookData(array $bookData, array $fieldMapping): void
    {
        // استخراج فیلدهای کتاب براساس نقشه‌برداری
        $extractedData = $this->extractFieldsFromData($bookData, $fieldMapping);

        Log::debug("داده‌های استخراج شده از کتاب", [
            'extracted_fields' => array_keys($extractedData),
            'extracted_data' => $extractedData
        ]);

        // اعتبارسنجی داده‌های ضروری
        if (empty($extractedData['title'])) {
            throw new \Exception('عنوان کتاب یافت نشد یا خالی است');
        }

        // ایجاد content_hash
        $contentHash = $this->generateContentHash($extractedData);

        // بررسی وجود کتاب
        if (Book::where('content_hash', $contentHash)->exists()) {
            $this->stats['duplicate']++;
            Log::debug("کتاب تکراری تشخیص داده شد", [
                'title' => $extractedData['title'],
                'hash' => $contentHash
            ]);
            return;
        }

        DB::beginTransaction();

        try {
            // پیدا کردن یا ایجاد دسته‌بندی
            $category = $this->findOrCreateCategory($extractedData['category'] ?? 'عمومی');

            // پیدا کردن یا ایجاد ناشر
            $publisher = null;
            if (!empty($extractedData['publisher'])) {
                $publisher = $this->findOrCreatePublisher($extractedData['publisher']);
            }

            // ایجاد کتاب
            $book = $this->createBook($extractedData, $contentHash, $category, $publisher);

            Log::info("کتاب جدید ایجاد شد", [
                'book_id' => $book->id,
                'title' => $book->title,
                'category' => $category->name,
                'publisher' => $publisher?->name
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
            $this->processHashes($book, $extractedData);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("خطا در ذخیره کتاب", [
                'title' => $extractedData['title'] ?? 'نامشخص',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * استخراج فیلدها براساس نقشه‌برداری
     */
    private function extractFieldsFromData(array $data, array $fieldMapping): array
    {
        $extracted = [];

        if (empty($fieldMapping)) {
            Log::warning("نقشه‌برداری فیلدها خالی است، استفاده از نقشه‌برداری پیش‌فرض");
            $fieldMapping = $this->getDefaultFieldMapping();
        }

        foreach ($fieldMapping as $bookField => $apiField) {
            if (empty($apiField)) continue;

            try {
                $value = $this->getNestedValue($data, $apiField);
                if ($value !== null) {
                    $extracted[$bookField] = $this->sanitizeValue($value, $bookField);
                    Log::debug("فیلد استخراج شد", [
                        'book_field' => $bookField,
                        'api_field' => $apiField,
                        'value' => $extracted[$bookField]
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning("خطا در استخراج فیلد", [
                    'book_field' => $bookField,
                    'api_field' => $apiField,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $extracted;
    }

    /**
     * نقشه‌برداری پیش‌فرض برای API balyan.ir
     */
    private function getDefaultFieldMapping(): array
    {
        return [
            'title' => 'title',
            'description' => 'description_en',
            'isbn' => 'isbn',
            'publication_year' => 'publication_year',
            'pages_count' => 'pages_count',
            'language' => 'language',
            'format' => 'format',
            'file_size' => 'file_size',
            'author' => 'authors',
            'category' => 'category.name',
            'publisher' => 'publisher.name',
            'image_url' => 'image_url.0'
        ];
    }

    /**
     * دریافت مقدار از nested array
     */
    private function getNestedValue(array $data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (is_array($value)) {
                if (is_numeric($key)) {
                    $key = (int) $key;
                    if (isset($value[$key])) {
                        $value = $value[$key];
                    } else {
                        return null;
                    }
                } elseif (isset($value[$key])) {
                    $value = $value[$key];
                } else {
                    return null;
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

        // پردازش آرایه تصاویر
        if (str_contains($path, 'image_url') && is_array($value)) {
            return $value[0] ?? null; // اولین تصویر
        }

        return $value;
    }

    /**
     * پاک‌سازی و اعتبارسنجی مقادیر
     */
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
                $isbn = preg_replace('/[^0-9X-]/', '', (string) $value);
                return !empty($isbn) ? $isbn : null;

            case 'language':
                $language = strtolower(trim((string) $value));
                $langMap = [
                    'persian' => 'fa',
                    'english' => 'en',
                    'arabic' => 'ar',
                    'فارسی' => 'fa',
                    'انگلیسی' => 'en',
                    'عربی' => 'ar'
                ];
                return $langMap[$language] ?? substr($language, 0, 2);

            case 'format':
                $format = strtolower(trim((string) $value));
                $allowedFormats = ['pdf', 'epub', 'mobi', 'djvu', 'audio'];
                return in_array($format, $allowedFormats) ? $format : 'pdf';

            case 'image_url':
                $url = trim((string) $value);
                return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;

            default:
                return trim((string) $value);
        }
    }

    /**
     * تولید content hash
     */
    private function generateContentHash(array $data): string
    {
        $hashData = ($data['title'] ?? '') .
            ($data['author'] ?? '') .
            ($data['isbn'] ?? '') .
            ($data['publication_year'] ?? '');

        return md5($hashData);
    }

    /**
     * پیدا کردن یا ایجاد دسته‌بندی
     */
    private function findOrCreateCategory(string $categoryName): Category
    {
        $slug = Str::slug($categoryName);

        return Category::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $categoryName,
                'is_active' => true,
                'books_count' => 0
            ]
        );
    }

    /**
     * پیدا کردن یا ایجاد ناشر
     */
    private function findOrCreatePublisher(string $publisherName): Publisher
    {
        $slug = Str::slug($publisherName);

        return Publisher::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $publisherName,
                'is_active' => true,
                'books_count' => 0
            ]
        );
    }

    /**
     * ایجاد کتاب
     */
    private function createBook(array $data, string $contentHash, Category $category, ?Publisher $publisher): Book
    {
        return Book::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'excerpt' => $data['excerpt'] ?? Str::limit($data['description'] ?? '', 200),
            'slug' => Str::slug($data['title']),
            'isbn' => $data['isbn'] ?? null,
            'publication_year' => $data['publication_year'] ?? null,
            'pages_count' => $data['pages_count'] ?? null,
            'language' => $data['language'] ?? 'fa',
            'format' => $data['format'] ?? 'pdf',
            'file_size' => $data['file_size'] ?? null,
            'content_hash' => $contentHash,
            'category_id' => $category->id,
            'publisher_id' => $publisher?->id,
            'downloads_count' => 0,
            'status' => 'active'
        ]);
    }

    /**
     * پردازش نویسندگان
     */
    private function processAuthors(Book $book, string $authorString): void
    {
        $authorNames = array_map('trim', explode(',', $authorString));

        foreach ($authorNames as $authorName) {
            if (empty($authorName)) continue;

            $author = Author::firstOrCreate(
                ['slug' => Str::slug($authorName)],
                [
                    'name' => $authorName,
                    'is_active' => true,
                    'books_count' => 0
                ]
            );

            $book->authors()->attach($author->id);
        }
    }

    /**
     * پردازش تصاویر
     */
    private function processImages(Book $book, string $imageUrl): void
    {
        if (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            BookImage::create([
                'book_id' => $book->id,
                'image_url' => $imageUrl
            ]);
        }
    }

    /**
     * پردازش hash ها
     */
    private function processHashes(Book $book, array $data): void
    {
        $md5 = $data['md5'] ?? $book->content_hash;

        BookHash::create([
            'book_id' => $book->id,
            'book_hash' => $book->content_hash,
            'md5' => $md5,
            'sha1' => sha1($book->title . $book->description),
            'sha256' => hash('sha256', $book->title . $book->description)
        ]);
    }

    /**
     * دریافت آمار پردازش
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}
