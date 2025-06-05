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
 * سرویس بهینه‌شده دریافت و پردازش اطلاعات از API
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
        Log::info("شروع دریافت اطلاعات API", ['config' => $this->config->name]);

        try {
            $apiSettings = $this->config->getApiSettings();
            $generalSettings = $this->config->getGeneralSettings();

            // پردازش تنها یک صفحه (تعداد محدود)
            $recordsToProcess = min($this->config->records_per_run, 50);
            $this->processApiPage($apiSettings, $generalSettings, $recordsToProcess);

        } catch (\Exception $e) {
            Log::error("خطا در API", ['error' => $e->getMessage()]);
            throw $e;
        }

        Log::info("پایان دریافت API", ['stats' => $this->stats]);
        return $this->stats;
    }

    /**
     * پردازش یک صفحه از API
     */
    private function processApiPage(array $apiSettings, array $generalSettings, int $limit): void
    {
        // ساخت URL با محدودیت تعداد
        $fullUrl = $this->buildApiUrl($apiSettings, $limit);

        Log::info("درخواست به API", ['url' => $fullUrl]);

        // ارسال درخواست
        $response = $this->makeHttpRequest($fullUrl, $apiSettings, $generalSettings);

        if (!$response->successful()) {
            throw new \Exception("خطای HTTP {$response->status()}");
        }

        $data = $response->json();

        // استخراج کتاب‌ها
        $books = $this->extractBooksFromApiData($data);

        Log::info("کتاب‌های یافته", ['count' => count($books)]);

        if (empty($books)) {
            Log::warning("هیچ کتابی یافت نشد");
            return;
        }

        // پردازش هر کتاب
        foreach ($books as $index => $bookData) {
            $this->stats['total']++;

            try {
                $this->processBookData($bookData, $apiSettings['field_mapping'] ?? []);
                $this->stats['success']++;

                Log::info("کتاب پردازش شد", ['index' => $index, 'title' => $bookData['title'] ?? 'نامشخص']);

            } catch (\Exception $e) {
                $this->stats['failed']++;
                Log::warning("خطا در پردازش کتاب", [
                    'index' => $index,
                    'error' => $e->getMessage(),
                    'title' => $bookData['title'] ?? 'نامشخص'
                ]);
            }
        }
    }

    /**
     * ساخت URL API
     */
    private function buildApiUrl(array $apiSettings, int $limit): string
    {
        $baseUrl = rtrim($this->config->base_url, '/');
        $endpoint = $apiSettings['endpoint'] ?? '';

        $fullUrl = $baseUrl . ($endpoint ? '/' . ltrim($endpoint, '/') : '');

        // اضافه کردن پارامترها
        $params = ['limit' => $limit];
        if (!empty($apiSettings['params'])) {
            $params = array_merge($params, $apiSettings['params']);
        }

        return $fullUrl . '?' . http_build_query($params);
    }

    /**
     * ارسال درخواست HTTP
     */
    private function makeHttpRequest(string $url, array $apiSettings, array $generalSettings)
    {
        $httpClient = Http::timeout($this->config->timeout)
            ->retry($this->config->max_retries, 1);

        // تنظیمات عمومی
        if (!empty($generalSettings['user_agent'])) {
            $httpClient = $httpClient->withUserAgent($generalSettings['user_agent']);
        }

        if (!($generalSettings['verify_ssl'] ?? true)) {
            $httpClient = $httpClient->withoutVerifying();
        }

        // احراز هویت
        if (($apiSettings['auth_type'] ?? '') === 'bearer' && !empty($apiSettings['auth_token'])) {
            $httpClient = $httpClient->withToken($apiSettings['auth_token']);
        }

        return $httpClient->get($url);
    }

    /**
     * استخراج کتاب‌ها از پاسخ API
     */
    private function extractBooksFromApiData(array $data): array
    {
        // ساختار balyan.ir
        if (isset($data['status'], $data['data']['books']) && $data['status'] === 'success') {
            return $data['data']['books'];
        }

        // ساختارهای متداول
        $possibleKeys = ['data', 'books', 'results', 'items'];
        foreach ($possibleKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && !empty($data[$key])) {
                $firstItem = $data[$key][0] ?? null;
                if (is_array($firstItem) && (isset($firstItem['title']) || isset($firstItem['id']))) {
                    return $data[$key];
                }
            }
        }

        // کتاب منفرد
        if (isset($data['title']) || isset($data['id'])) {
            return [$data];
        }

        return [];
    }

    /**
     * پردازش اطلاعات یک کتاب
     */
    private function processBookData(array $bookData, array $fieldMapping): void
    {
        // استخراج فیلدها
        $extractedData = $this->extractFieldsFromData($bookData, $fieldMapping);

        if (empty($extractedData['title'])) {
            throw new \Exception('عنوان کتاب یافت نشد');
        }

        // ایجاد hash یکتا
        $contentHash = $this->generateContentHash($extractedData);

        // بررسی تکراری بودن
        if (Book::where('content_hash', $contentHash)->exists()) {
            $this->stats['duplicate']++;
            Log::debug("کتاب تکراری", ['title' => $extractedData['title']]);
            return;
        }

        DB::beginTransaction();

        try {
            // ایجاد/پیدا کردن روابط
            $category = $this->findOrCreateCategory($extractedData['category'] ?? 'عمومی');
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

            Log::info("کتاب جدید ایجاد شد", [
                'book_id' => $book->id,
                'title' => $book->title
            ]);

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
        if (empty($fieldMapping)) {
            $fieldMapping = $this->getDefaultFieldMapping();
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

    /**
     * نقشه‌برداری پیش‌فرض
     */
    private function getDefaultFieldMapping(): array
    {
        return [
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
                    $value = $value[$key] ?? null;
                } else {
                    $value = $value[$key] ?? null;
                }
            } else {
                return null;
            }
        }

        // پردازش نویسندگان
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
            return $value[0] ?? null;
        }

        return $value;
    }

    /**
     * پاک‌سازی مقادیر
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
     * دریافت آمار
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}
