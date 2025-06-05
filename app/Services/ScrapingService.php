<?php

namespace App\Services;

use App\Models\Config;
use App\Models\Book;
use App\Models\Author;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\BookImage;
use App\Models\ScrapingFailure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class ScrapingService
{
    private Config $config;
    private array $urlQueue = [];
    private int $currentPage = 1;
    private int $currentId = 1;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->initializePosition();
    }

    /**
     * پردازش رکورد بعدی
     */
    public function processNext(): bool
    {
        $nextUrl = $this->getNextUrl();

        if (!$nextUrl) {
            Log::info("پایان اسکرپ: {$this->config->name}");
            return false; // پایان داده‌ها
        }

        try {
            Log::info("پردازش URL: {$nextUrl}", ['config' => $this->config->name]);

            if ($this->config->isApiSource()) {
                return $this->processApiUrl($nextUrl);
            } else {
                return $this->processCrawlerUrl($nextUrl);
            }

        } catch (\Exception $e) {
            Log::error("خطا در پردازش URL: {$nextUrl}", [
                'config' => $this->config->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // ثبت شکست
            ScrapingFailure::logFailure(
                $this->config->id,
                $nextUrl,
                $e->getMessage(),
                [
                    'line' => $e->getLine(),
                    'file' => basename($e->getFile()),
                    'class' => get_class($e)
                ],
                null,
                null
            );

            $this->config->updateStats(false);

            // ادامه با URL بعدی
            return true;
        }
    }

    /**
     * مقداردهی اولیه موقعیت
     */
    private function initializePosition(): void
    {
        $currentUrl = $this->config->current_url;

        if ($currentUrl) {
            // ادامه از جایی که قطع شده
            if ($this->config->isApiSource()) {
                $this->currentPage = $this->extractPageFromUrl($currentUrl);
            } else {
                $this->currentId = $this->extractIdFromUrl($currentUrl);
            }
        } else {
            // شروع از اول
            $this->currentPage = 1;
            $this->currentId = 1;
        }

        Log::info("موقعیت اولیه", [
            'config' => $this->config->name,
            'current_page' => $this->currentPage,
            'current_id' => $this->currentId,
            'current_url' => $currentUrl
        ]);
    }

    /**
     * دریافت URL بعدی
     */
    private function getNextUrl(): ?string
    {
        if ($this->config->isApiSource()) {
            return $this->getNextApiUrl();
        } else {
            return $this->getNextCrawlerUrl();
        }
    }

    /**
     * دریافت URL بعدی برای API
     */
    private function getNextApiUrl(): ?string
    {
        // محدودیت امنیتی: حداکثر 1000 صفحه
        if ($this->currentPage > 1000) {
            Log::info("رسیدن به حد مجاز صفحات", ['config' => $this->config->name]);
            return null;
        }

        $baseUrl = rtrim($this->config->base_url, '/');
        $apiSettings = $this->config->config_data['api'] ?? [];
        $endpoint = $apiSettings['endpoint'] ?? '/api/books';

        // ساخت URL با صفحه فعلی
        $url = $baseUrl . $endpoint . '?page=' . $this->currentPage;

        // اضافه کردن پارامترهای اضافی
        $params = $apiSettings['params'] ?? [];
        if (!empty($params)) {
            $url .= '&' . http_build_query($params);
        }

        // ذخیره URL فعلی و افزایش صفحه
        $this->config->update(['current_url' => $url]);
        $this->currentPage++;

        return $url;
    }

    /**
     * دریافت URL بعدی برای Crawler
     */
    private function getNextCrawlerUrl(): ?string
    {
        $crawlerSettings = $this->config->config_data['crawler'] ?? [];
        $urlPattern = $crawlerSettings['url_pattern'] ?? '';

        if (empty($urlPattern)) {
            // اگر الگو نداریم، فقط یک بار URL پایه رو برمی‌گردونیم
            if ($this->currentId > 1) {
                return null;
            }
            $url = $this->config->base_url;
        } else {
            // محدودیت امنیتی: حداکثر 10000 ID
            if ($this->currentId > 10000) {
                Log::info("رسیدن به حد مجاز IDها", ['config' => $this->config->name]);
                return null;
            }

            // جایگزینی {id} با شماره فعلی
            $url = str_replace('{id}', $this->currentId, $urlPattern);

            // اگر {id} وجود نداشت، از base_url استفاده کن
            if (!str_contains($urlPattern, '{id}')) {
                $url = $this->config->base_url . '/' . $this->currentId;
            }
        }

        // ذخیره URL فعلی و افزایش ID
        $this->config->update(['current_url' => $url]);
        $this->currentId++;

        return $url;
    }

    /**
     * پردازش URL از API
     */
    private function processApiUrl(string $url): bool
    {
        $response = $this->makeRequest($url);

        if (!$response->successful()) {
            throw new \Exception("خطای HTTP {$response->status()}: " . $response->reason());
        }

        $data = $response->json();

        if (!is_array($data)) {
            throw new \Exception("پاسخ API معتبر نیست");
        }

        $books = $this->extractBooksFromApiData($data);

        if (empty($books)) {
            Log::info("صفحه خالی یافت شد، پایان اسکرپ", [
                'config' => $this->config->name,
                'url' => $url
            ]);
            return false; // پایان داده‌ها
        }

        Log::info("کتاب‌های یافت شده", [
            'config' => $this->config->name,
            'count' => count($books),
            'url' => $url
        ]);

        // پردازش هر کتاب
        foreach ($books as $index => $bookData) {
            try {
                $this->processBookData($bookData, 'api', $url);
            } catch (\Exception $e) {
                Log::warning("خطا در پردازش کتاب {$index}", [
                    'config' => $this->config->name,
                    'error' => $e->getMessage(),
                    'book_data' => $this->getSafeBookData($bookData)
                ]);

                ScrapingFailure::logFailure(
                    $this->config->id,
                    $url . "#book_{$index}",
                    "خطا در پردازش کتاب: " . $e->getMessage(),
                    ['book_index' => $index, 'book_data' => $this->getSafeBookData($bookData)]
                );

                $this->config->updateStats(false);
            }
        }

        return true;
    }

    /**
     * پردازش URL از Crawler
     */
    private function processCrawlerUrl(string $url): bool
    {
        $response = $this->makeRequest($url);

        if (!$response->successful()) {
            // برای 404 در crawler، ممکنه ID وجود نداشته باشه
            if ($response->status() === 404) {
                Log::info("صفحه 404 یافت شد", [
                    'config' => $this->config->name,
                    'url' => $url
                ]);
                return true; // ادامه با ID بعدی
            }
            throw new \Exception("خطای HTTP {$response->status()}: " . $response->reason());
        }

        $html = $response->body();

        if (empty($html)) {
            throw new \Exception("محتوای صفحه خالی است");
        }

        $crawler = new Crawler($html);
        $bookData = $this->extractBookDataFromHtml($crawler, $url);

        if (empty($bookData['title'])) {
            throw new \Exception("عنوان کتاب یافت نشد");
        }

        $this->processBookData($bookData, 'crawler', $url);
        return true;
    }

    /**
     * ارسال درخواست HTTP
     */
    private function makeRequest(string $url)
    {
        $client = Http::timeout($this->config->timeout)
            ->retry($this->config->max_retries, 1000);

        // تنظیمات عمومی
        $generalSettings = $this->config->config_data['general'] ?? [];

        if (!empty($generalSettings['user_agent'])) {
            $client = $client->withUserAgent($generalSettings['user_agent']);
        }

        if (!($generalSettings['verify_ssl'] ?? true)) {
            $client = $client->withoutVerifying();
        }

        // احراز هویت برای API
        if ($this->config->isApiSource()) {
            $apiSettings = $this->config->config_data['api'] ?? [];

            if (($apiSettings['auth_type'] ?? '') === 'bearer' && !empty($apiSettings['auth_token'])) {
                $client = $client->withToken($apiSettings['auth_token']);
            } elseif (($apiSettings['auth_type'] ?? '') === 'basic' && !empty($apiSettings['auth_token'])) {
                $credentials = explode(':', $apiSettings['auth_token'], 2);
                if (count($credentials) === 2) {
                    $client = $client->withBasicAuth($credentials[0], $credentials[1]);
                }
            }

            // Headers سفارشی
            if (!empty($apiSettings['headers'])) {
                $client = $client->withHeaders($apiSettings['headers']);
            }
        }

        return $client->get($url);
    }

    /**
     * استخراج کتاب‌ها از API
     */
    private function extractBooksFromApiData(array $data): array
    {
        // ساختار balyan.ir
        if (isset($data['status'], $data['data']['books']) && $data['status'] === 'success') {
            return $data['data']['books'];
        }

        // ساختارهای متداول
        $possibleKeys = ['data', 'books', 'results', 'items', 'list', 'content'];

        foreach ($possibleKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && !empty($data[$key])) {
                // بررسی اینکه آیا آرایه‌ای از کتاب‌هاست
                $firstItem = $data[$key][0] ?? null;
                if (is_array($firstItem) && (isset($firstItem['title']) || isset($firstItem['id']) || isset($firstItem['name']))) {
                    return $data[$key];
                }
            }
        }

        // اگر خود data آرایه‌ای از کتاب‌هاست
        if (isset($data[0]) && is_array($data[0])) {
            return $data;
        }

        // کتاب منفرد
        if (isset($data['title']) || isset($data['id']) || isset($data['name'])) {
            return [$data];
        }

        return [];
    }

    /**
     * استخراج اطلاعات کتاب از HTML
     */
    private function extractBookDataFromHtml(Crawler $crawler, string $url): array
    {
        $selectors = $this->config->config_data['crawler']['selectors'] ?? [];
        $data = [];

        foreach ($selectors as $field => $selector) {
            if (empty($selector)) continue;

            try {
                $elements = $crawler->filter($selector);
                if ($elements->count() > 0) {
                    $data[$field] = $this->extractValueFromElement($elements, $field);
                }
            } catch (\Exception $e) {
                Log::debug("خطا در selector {$field}: {$selector}", [
                    'error' => $e->getMessage(),
                    'url' => $url
                ]);
            }
        }

        // اگر selector تعریف نشده، تلاش خودکار
        if (empty($selectors)) {
            $data = $this->autoExtractFromHtml($crawler);
        }

        return $data;
    }

    /**
     * استخراج مقدار از element
     */
    private function extractValueFromElement($elements, string $field): string
    {
        $element = $elements->first();

        switch ($field) {
            case 'image_url':
                if ($element->nodeName() === 'img') {
                    return $element->attr('src') ?: $element->attr('data-src') ?: '';
                }
                $img = $element->filter('img');
                if ($img->count() > 0) {
                    return $img->attr('src') ?: $img->attr('data-src') ?: '';
                }
                break;

            case 'download_url':
                if ($element->nodeName() === 'a') {
                    return $element->attr('href') ?: '';
                }
                $link = $element->filter('a');
                if ($link->count() > 0) {
                    return $link->attr('href') ?: '';
                }
                break;

            default:
                return trim($element->text());
        }

        return '';
    }

    /**
     * استخراج خودکار از HTML
     */
    private function autoExtractFromHtml(Crawler $crawler): array
    {
        $data = [];

        // الگوهای متداول
        $patterns = [
            'title' => ['h1', '.title', '#title', '.book-title', '.product-title'],
            'description' => ['.description', '.summary', '.content', '.product-description'],
            'author' => ['.author', '.writer', '.by-author'],
            'category' => ['.category', '.genre', '.classification'],
            'publisher' => ['.publisher', '.publication'],
            'image_url' => ['.book-cover img', '.product-image img', 'img.cover']
        ];

        foreach ($patterns as $field => $selectors) {
            foreach ($selectors as $selector) {
                try {
                    $elements = $crawler->filter($selector);
                    if ($elements->count() > 0) {
                        $value = $this->extractValueFromElement($elements, $field);
                        if (!empty(trim($value))) {
                            $data[$field] = trim($value);
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return $data;
    }

    /**
     * پردازش داده‌های کتاب
     */
    private function processBookData(array $bookData, string $source, string $sourceUrl): void
    {
        DB::beginTransaction();

        try {
            // پاک‌سازی و اعتبارسنجی داده‌ها
            $cleanData = $this->cleanBookData($bookData);

            // تولید hash یکتا
            $contentHash = $this->generateContentHash($cleanData);

            // بررسی تکراری بودن
            if (Book::where('content_hash', $contentHash)->exists()) {
                Log::debug("کتاب تکراری", [
                    'title' => $cleanData['title'],
                    'hash' => $contentHash
                ]);
                $this->config->updateStats(true);
                DB::commit();
                return;
            }

            // ایجاد/پیدا کردن روابط
            $category = $this->findOrCreateCategory($cleanData['category'] ?? 'عمومی');
            $publisher = null;
            if (!empty($cleanData['publisher'])) {
                $publisher = $this->findOrCreatePublisher($cleanData['publisher']);
            }

            // ایجاد کتاب
            $book = Book::create([
                'title' => $cleanData['title'],
                'description' => $cleanData['description'] ?? null,
                'excerpt' => $cleanData['excerpt'] ?? Str::limit($cleanData['description'] ?? '', 200),
                'slug' => Str::slug($cleanData['title']),
                'isbn' => $cleanData['isbn'] ?? null,
                'publication_year' => $cleanData['publication_year'] ?? null,
                'pages_count' => $cleanData['pages_count'] ?? null,
                'language' => $cleanData['language'] ?? 'fa',
                'format' => $cleanData['format'] ?? 'pdf',
                'file_size' => $cleanData['file_size'] ?? null,
                'content_hash' => $contentHash,
                'category_id' => $category->id,
                'publisher_id' => $publisher?->id,
                'downloads_count' => 0,
                'status' => 'active'
            ]);

            // پردازش نویسندگان
            if (!empty($cleanData['author'])) {
                $this->processAuthors($book, $cleanData['author']);
            }

            // پردازش تصاویر
            if (!empty($cleanData['image_url'])) {
                $this->processImages($book, $cleanData['image_url'], $sourceUrl);
            }

            Log::info("کتاب جدید ایجاد شد", [
                'config' => $this->config->name,
                'book_id' => $book->id,
                'title' => $book->title,
                'source' => $source
            ]);

            $this->config->updateStats(true);
            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("خطا در ذخیره کتاب", [
                'config' => $this->config->name,
                'title' => $bookData['title'] ?? 'نامشخص',
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * پاک‌سازی داده‌های کتاب
     */
    private function cleanBookData(array $data): array
    {
        $cleaned = [];

        // عنوان (الزامی)
        $cleaned['title'] = $this->cleanString($data['title'] ?? '');
        if (empty($cleaned['title'])) {
            throw new \Exception('عنوان کتاب خالی یا نامعتبر است');
        }

        // سایر فیلدها
        $cleaned['description'] = $this->cleanString($data['description'] ?? '');
        $cleaned['author'] = $this->cleanString($data['author'] ?? '');
        $cleaned['category'] = $this->cleanString($data['category'] ?? '');
        $cleaned['publisher'] = $this->cleanString($data['publisher'] ?? '');
        $cleaned['isbn'] = $this->cleanIsbn($data['isbn'] ?? '');
        $cleaned['publication_year'] = $this->extractYear($data['publication_year'] ?? '');
        $cleaned['pages_count'] = $this->extractNumber($data['pages_count'] ?? '');
        $cleaned['file_size'] = $this->extractNumber($data['file_size'] ?? '');
        $cleaned['language'] = $this->cleanLanguage($data['language'] ?? '');
        $cleaned['format'] = $this->cleanFormat($data['format'] ?? '');
        $cleaned['image_url'] = $this->cleanUrl($data['image_url'] ?? '');

        return array_filter($cleaned); // حذف مقادیر خالی
    }

    /**
     * پاک‌سازی رشته
     */
    private function cleanString(string $value): string
    {
        return trim(strip_tags($value));
    }

    /**
     * پاک‌سازی ISBN
     */
    private function cleanIsbn(string $value): string
    {
        return preg_replace('/[^0-9X-]/', '', strtoupper($value));
    }

    /**
     * استخراج سال
     */
    private function extractYear(string $value): ?int
    {
        if (preg_match('/(\d{4})/', $value, $matches)) {
            $year = (int)$matches[1];
            return ($year >= 1000 && $year <= date('Y') + 5) ? $year : null;
        }
        return null;
    }

    /**
     * استخراج عدد
     */
    private function extractNumber(string $value): ?int
    {
        if (preg_match('/(\d+)/', $value, $matches)) {
            return (int)$matches[1];
        }
        return null;
    }

    /**
     * پاک‌سازی زبان
     */
    private function cleanLanguage(string $value): string
    {
        $value = strtolower(trim($value));
        $langMap = [
            'persian' => 'fa', 'فارسی' => 'fa', 'farsi' => 'fa',
            'english' => 'en', 'انگلیسی' => 'en',
            'arabic' => 'ar', 'عربی' => 'ar'
        ];
        return $langMap[$value] ?? substr($value, 0, 2) ?: 'fa';
    }

    /**
     * پاک‌سازی فرمت
     */
    private function cleanFormat(string $value): string
    {
        $value = strtolower(trim($value));
        $allowedFormats = ['pdf', 'epub', 'mobi', 'djvu', 'audio', 'txt'];
        return in_array($value, $allowedFormats) ? $value : 'pdf';
    }

    /**
     * پاک‌سازی URL
     */
    private function cleanUrl(string $value): string
    {
        $value = trim($value);

        // تبدیل URL نسبی به مطلق
        if (!empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
            $baseUrl = parse_url($this->config->base_url, PHP_URL_SCHEME) . '://' .
                parse_url($this->config->base_url, PHP_URL_HOST);

            if (strpos($value, '/') === 0) {
                $value = $baseUrl . $value;
            } else {
                $value = $baseUrl . '/' . $value;
            }
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
    }

    /**
     * تولید content hash
     */
    private function generateContentHash(array $data): string
    {
        $hashString = ($data['title'] ?? '') .
            ($data['author'] ?? '') .
            ($data['isbn'] ?? '') .
            ($data['publication_year'] ?? '');

        return md5($hashString);
    }

    /**
     * پیدا کردن یا ایجاد دسته‌بندی
     */
    private function findOrCreateCategory(string $name): Category
    {
        $slug = Str::slug($name);
        return Category::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'is_active' => true, 'books_count' => 0]
        );
    }

    /**
     * پیدا کردن یا ایجاد ناشر
     */
    private function findOrCreatePublisher(string $name): Publisher
    {
        $slug = Str::slug($name);
        return Publisher::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'is_active' => true, 'books_count' => 0]
        );
    }

    /**
     * پردازش نویسندگان
     */
    private function processAuthors(Book $book, string $authorString): void
    {
        $authors = array_map('trim', explode(',', $authorString));

        foreach ($authors as $authorName) {
            if (empty($authorName)) continue;

            $author = Author::firstOrCreate(
                ['slug' => Str::slug($authorName)],
                ['name' => $authorName, 'is_active' => true, 'books_count' => 0]
            );

            $book->authors()->syncWithoutDetaching([$author->id]);
        }
    }

    /**
     * پردازش تصاویر
     */
    private function processImages(Book $book, string $imageUrl, string $sourceUrl): void
    {
        if (!empty($imageUrl)) {
            BookImage::create([
                'book_id' => $book->id,
                'image_url' => $imageUrl
            ]);
        }
    }

    /**
     * استخراج شماره صفحه از URL
     */
    private function extractPageFromUrl(string $url): int
    {
        if (preg_match('/[?&]page=(\d+)/', $url, $matches)) {
            return (int)$matches[1] + 1;
        }
        return 1;
    }

    /**
     * استخراج ID از URL
     */
    private function extractIdFromUrl(string $url): int
    {
        if (preg_match('/\/(\d+)(?:[\/\?]|$)/', $url, $matches)) {
            return (int)$matches[1] + 1;
        }
        return 1;
    }

    /**
     * دریافت داده‌های امن کتاب برای لاگ
     */
    private function getSafeBookData(array $bookData): array
    {
        return [
            'title' => $bookData['title'] ?? 'نامشخص',
            'author' => $bookData['author'] ?? 'نامشخص',
            'has_description' => !empty($bookData['description']),
            'field_count' => count($bookData)
        ];
    }
}
