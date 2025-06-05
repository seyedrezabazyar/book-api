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
 * سرویس دریافت و پردازش اطلاعات از API
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

        Log::info("شروع دریافت اطلاعات از API: {$this->config->name}");

        try {
            $apiSettings = $this->config->getApiSettings();
            $generalSettings = $this->config->getGeneralSettings();

            // ساخت URL کامل
            $fullUrl = $this->buildFullUrl($apiSettings);

            // ساخت درخواست HTTP
            $response = $this->makeHttpRequest($fullUrl, $apiSettings, $generalSettings);

            if ($response->successful()) {
                $data = $response->json();
                $this->processApiData($data, $apiSettings['field_mapping']);
            } else {
                throw new \Exception("خطا در دریافت اطلاعات: HTTP {$response->status()}");
            }

        } catch (\Exception $e) {
            Log::error("خطا در دریافت اطلاعات از API {$this->config->name}: " . $e->getMessage());
            throw $e;
        }

        Log::info("پایان دریافت اطلاعات از API: {$this->config->name}", $this->stats);

        return $this->stats;
    }

    /**
     * ساخت URL کامل برای درخواست API
     */
    private function buildFullUrl(array $apiSettings): string
    {
        $baseUrl = rtrim($this->config->base_url, '/');
        $endpoint = ltrim($apiSettings['endpoint'], '/');

        return $baseUrl . '/' . $endpoint;
    }

    /**
     * ارسال درخواست HTTP به API
     */
    private function makeHttpRequest(string $url, array $apiSettings, array $generalSettings)
    {
        $httpClient = Http::timeout($this->config->timeout)
            ->retry($this->config->max_retries, $this->config->delay);

        // تنظیم User Agent
        if (!empty($generalSettings['user_agent'])) {
            $httpClient = $httpClient->withUserAgent($generalSettings['user_agent']);
        }

        // تنظیم SSL verification
        if (!$generalSettings['verify_ssl']) {
            $httpClient = $httpClient->withoutVerifying();
        }

        // تنظیم احراز هویت
        if ($apiSettings['auth_type'] === 'bearer') {
            $httpClient = $httpClient->withToken($apiSettings['auth_token']);
        } elseif ($apiSettings['auth_type'] === 'basic') {
            // فرض می‌کنیم توکن به فرمت username:password است
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
        if ($apiSettings['method'] === 'GET') {
            return $httpClient->get($url, $apiSettings['params'] ?? []);
        } elseif ($apiSettings['method'] === 'POST') {
            return $httpClient->post($url, $apiSettings['params'] ?? []);
        }

        throw new \InvalidArgumentException("متد HTTP پشتیبانی نشده: {$apiSettings['method']}");
    }

    /**
     * پردازش داده‌های دریافت شده از API
     */
    private function processApiData(array $data, array $fieldMapping): void
    {
        // تشخیص ساختار داده‌ها
        $books = $this->extractBooksFromData($data);

        foreach ($books as $bookData) {
            $this->stats['total']++;

            try {
                $this->processBookData($bookData, $fieldMapping);
                $this->stats['success']++;
            } catch (\Exception $e) {
                $this->stats['failed']++;
                Log::warning("خطا در پردازش کتاب: " . $e->getMessage(), ['book_data' => $bookData]);
            }
        }
    }

    /**
     * استخراج لیست کتاب‌ها از داده‌های API
     */
    private function extractBooksFromData(array $data): array
    {
        // اگر داده‌ها مستقیماً لیست کتاب‌ها هستند
        if ($this->isAssociativeArray($data)) {
            return [$data];
        }

        // اگر داده‌ها در یک کلید خاص هستند (مثل 'data', 'books', 'results')
        $possibleKeys = ['data', 'books', 'results', 'items', 'list'];
        foreach ($possibleKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        // اگر داده‌ها مستقیماً آرایه‌ای از کتاب‌ها هستند
        return $data;
    }

    /**
     * پردازش اطلاعات یک کتاب
     */
    private function processBookData(array $bookData, array $fieldMapping): void
    {
        // استخراج فیلدهای کتاب براساس نقشه‌برداری
        $extractedData = $this->extractFieldsFromData($bookData, $fieldMapping);

        // اعتبارسنجی داده‌های ضروری
        if (empty($extractedData['title'])) {
            throw new \Exception('عنوان کتاب یافت نشد');
        }

        // ایجاد content_hash
        $contentHash = $this->generateContentHash($extractedData);

        // بررسی وجود کتاب
        if (Book::where('content_hash', $contentHash)->exists()) {
            $this->stats['duplicate']++;
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

            // پردازش نویسندگان
            if (!empty($extractedData['author'])) {
                $this->processAuthors($book, $extractedData['author']);
            }

            // پردازش تصاویر
            if (!empty($extractedData['image_url'])) {
                $this->processImages($book, $extractedData['image_url']);
            }

            // پردازش hash ها
            $this->processHashes($book, $contentHash);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * استخراج فیلدها براساس نقشه‌برداری
     */
    private function extractFieldsFromData(array $data, array $fieldMapping): array
    {
        $extracted = [];

        foreach ($fieldMapping as $bookField => $apiField) {
            if (empty($apiField)) continue;

            // پشتیبانی از nested fields (مثل: author.name)
            $value = $this->getNestedValue($data, $apiField);

            if ($value !== null) {
                $extracted[$bookField] = $this->sanitizeValue($value, $bookField);
            }
        }

        return $extracted;
    }

    /**
     * دریافت مقدار از nested array
     */
    private function getNestedValue(array $data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } else {
                return null;
            }
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
                return is_numeric($value) ? (int) $value : null;

            case 'pages_count':
            case 'file_size':
                return is_numeric($value) ? (int) $value : null;

            case 'isbn':
                return preg_replace('/[^0-9X-]/', '', (string) $value);

            case 'language':
                return strtolower(substr((string) $value, 0, 2));

            case 'format':
                $format = strtolower((string) $value);
                $allowedFormats = ['pdf', 'epub', 'mobi', 'djvu', 'audio'];
                return in_array($format, $allowedFormats) ? $format : 'pdf';

            case 'price':
            case 'rating':
                return is_numeric($value) ? (float) $value : null;

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

    /**
     * بررسی آرایه انجمنی
     */
    private function isAssociativeArray(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * دریافت آمار پردازش
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}
