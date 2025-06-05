<?php

namespace App\Services;

use App\Models\Config;
use App\Models\Book;
use App\Models\Author;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\BookImage;
use App\Models\BookHash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

/**
 * سرویس دریافت و پردازش اطلاعات با Web Crawler
 */
class CrawlerDataService
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
     * اجرای فرآیند Crawling
     */
    public function crawlData(): array
    {
        if (!$this->config->isCrawlerSource()) {
            throw new \InvalidArgumentException('کانفیگ مشخص شده از نوع Crawler نیست');
        }

        Log::info("شروع crawling: {$this->config->name}");

        try {
            $crawlerSettings = $this->config->getCrawlerSettings();
            $generalSettings = $this->config->getGeneralSettings();

            // شروع crawling از صفحه اول
            $this->crawlPages($crawlerSettings, $generalSettings);

        } catch (\Exception $e) {
            Log::error("خطا در crawling {$this->config->name}: " . $e->getMessage());
            throw $e;
        }

        Log::info("پایان crawling: {$this->config->name}", $this->stats);

        return $this->stats;
    }

    /**
     * تست URL مشخص
     */
    public function testUrl(string $testUrl): array
    {
        Log::info("تست Crawler URL", [
            'config_name' => $this->config->name,
            'test_url' => $testUrl
        ]);

        try {
            $crawlerSettings = $this->config->getCrawlerSettings();
            $generalSettings = $this->config->getGeneralSettings();

            // دریافت محتوای صفحه
            $html = $this->fetchPageContent($testUrl, $generalSettings);

            if (empty($html)) {
                throw new \Exception('محتوای صفحه خالی است');
            }

            $crawler = new Crawler($html);

            // استخراج اطلاعات کتاب
            $extractedData = $this->extractBookDataFromHtml($crawler, $testUrl);

            // تحلیل صفحه
            $pageTitle = '';
            try {
                $titleNode = $crawler->filter('title');
                if ($titleNode->count() > 0) {
                    $pageTitle = $titleNode->text();
                }
            } catch (\Exception $e) {
                // در صورت عدم وجود title
            }

            return [
                'config_name' => $this->config->name,
                'source_type' => 'Crawler',
                'test_url' => $testUrl,
                'page_title' => $pageTitle,
                'response_status' => 200,
                'extracted_data' => $extractedData,
                'raw_data' => [
                    'html_length' => strlen($html),
                    'page_title' => $pageTitle,
                    'extracted_fields' => array_keys($extractedData)
                ]
            ];

        } catch (\Exception $e) {
            throw new \Exception("خطا در تست Crawler: " . $e->getMessage());
        }
    }

    /**
     * Crawl کردن صفحات
     */
    private function crawlPages(array $crawlerSettings, array $generalSettings): void
    {
        $currentPage = 1;
        $maxPages = $crawlerSettings['pagination']['max_pages'] ?? 1;
        $hasNextPage = true;

        while ($hasNextPage && $currentPage <= $maxPages) {
            Log::info("Crawling صفحه {$currentPage}");

            try {
                $url = $this->buildPageUrl($currentPage);
                $html = $this->fetchPageContent($url, $generalSettings);

                if (empty($html)) {
                    Log::warning("محتوای خالی برای صفحه {$currentPage}");
                    break;
                }

                $crawler = new Crawler($html);

                // انتظار برای بارگذاری عنصر خاص (در صورت نیاز)
                if (!empty($crawlerSettings['wait_for_element'])) {
                    if ($crawler->filter($crawlerSettings['wait_for_element'])->count() === 0) {
                        Log::warning("عنصر انتظار یافت نشد: {$crawlerSettings['wait_for_element']}");
                    }
                }

                // استخراج اطلاعات کتاب‌ها از صفحه
                $this->extractBooksFromPage($crawler, $crawlerSettings['selectors'] ?? []);

                // بررسی وجود صفحه بعد
                if (isset($crawlerSettings['pagination']['enabled']) && $crawlerSettings['pagination']['enabled']) {
                    $hasNextPage = $this->hasNextPage($crawler, $crawlerSettings['pagination']);
                } else {
                    $hasNextPage = false;
                }

                $currentPage++;

                // تاخیر بین درخواست‌ها
                if ($this->config->delay_seconds > 0) {
                    sleep($this->config->delay_seconds);
                }

            } catch (\Exception $e) {
                Log::error("خطا در crawling صفحه {$currentPage}: " . $e->getMessage());
                break;
            }
        }
    }

    /**
     * ساخت URL صفحه براساس شماره صفحه
     */
    private function buildPageUrl(int $page): string
    {
        $baseUrl = rtrim($this->config->base_url, '/');

        // اگر صفحه اول است، همان URL پایه را برگردان
        if ($page === 1) {
            return $baseUrl;
        }

        // اضافه کردن پارامتر صفحه (معمولاً page یا p)
        $separator = strpos($baseUrl, '?') !== false ? '&' : '?';
        return $baseUrl . $separator . "page={$page}";
    }

    /**
     * دریافت محتوای صفحه
     */
    private function fetchPageContent(string $url, array $generalSettings): string
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

        // تنظیم follow redirects
        if ($generalSettings['follow_redirects'] ?? true) {
            $httpClient = $httpClient->withOptions(['allow_redirects' => true]);
        }

        $response = $httpClient->get($url);

        if ($response->successful()) {
            return $response->body();
        }

        throw new \Exception("خطا در دریافت صفحه: HTTP {$response->status()}");
    }

    /**
     * استخراج اطلاعات کتاب‌ها از صفحه
     */
    private function extractBooksFromPage(Crawler $crawler, array $selectors): void
    {
        // تلاش برای یافتن عنصر container کتاب‌ها
        $bookContainers = $this->findBookContainers($crawler, $selectors);

        if ($bookContainers->count() === 0) {
            Log::warning('هیچ container کتابی یافت نشد');
            return;
        }

        $bookContainers->each(function (Crawler $bookContainer, $index) use ($selectors) {
            $this->stats['total']++;

            try {
                $bookData = $this->extractBookData($bookContainer, $selectors);

                if (!empty($bookData['title'])) {
                    $this->processBookData($bookData);
                    $this->stats['success']++;
                } else {
                    Log::warning("عنوان کتاب یافت نشد در container {$index}");
                    $this->stats['failed']++;
                }

            } catch (\Exception $e) {
                $this->stats['failed']++;
                Log::warning("خطا در پردازش کتاب {$index}: " . $e->getMessage());
            }
        });
    }

    /**
     * یافتن container های کتاب
     */
    private function findBookContainers(Crawler $crawler, array $selectors): Crawler
    {
        // جستجو برای container patterns متداول
        $possibleContainerSelectors = [
            '.book', '.book-item', '.book-container',
            '.item', '.product', '.card',
            '[data-book]', '[data-item]'
        ];

        foreach ($possibleContainerSelectors as $selector) {
            $containers = $crawler->filter($selector);
            if ($containers->count() > 0) {
                return $containers;
            }
        }

        // اگر container مشخصی یافت نشد، کل صفحه را به عنوان یک item در نظر بگیر
        return new Crawler([$crawler->getNode(0)]);
    }

    /**
     * استخراج اطلاعات یک کتاب
     */
    private function extractBookData(Crawler $bookContainer, array $selectors): array
    {
        $extractedData = [];

        foreach ($selectors as $field => $selector) {
            if (empty($selector)) continue;

            try {
                $value = $this->extractValueBySelector($bookContainer, $selector, $field);
                if ($value !== null) {
                    $extractedData[$field] = $this->sanitizeValue($value, $field);
                }
            } catch (\Exception $e) {
                Log::debug("خطا در استخراج فیلد {$field}: " . $e->getMessage());
            }
        }

        return $extractedData;
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
     * استخراج مقدار با سلکتور
     */
    private function extractValueBySelector(Crawler $container, string $selector, string $field): ?string
    {
        $elements = $container->filter($selector);

        if ($elements->count() === 0) {
            return null;
        }

        // انتخاب روش استخراج براساس نوع فیلد
        return match($field) {
            'image_url' => $this->extractImageUrl($elements),
            'download_url' => $this->extractDownloadUrl($elements),
            default => $this->extractTextContent($elements)
        };
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
     * استخراج URL تصویر
     */
    private function extractImageUrl(Crawler $elements): ?string
    {
        $element = $elements->first();

        // بررسی تگ img
        if ($element->nodeName() === 'img') {
            return $element->attr('src') ?: $element->attr('data-src');
        }

        // جستجو برای img داخل element
        $img = $element->filter('img');
        if ($img->count() > 0) {
            return $img->attr('src') ?: $img->attr('data-src');
        }

        // بررسی background-image در style
        $style = $element->attr('style');
        if ($style && preg_match('/background-image:\s*url\(["\']?([^"\']+)["\']?\)/', $style, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * استخراج URL دانلود
     */
    private function extractDownloadUrl(Crawler $elements): ?string
    {
        $element = $elements->first();

        // بررسی تگ a
        if ($element->nodeName() === 'a') {
            return $element->attr('href');
        }

        // جستجو برای link داخل element
        $link = $element->filter('a');
        if ($link->count() > 0) {
            return $link->attr('href');
        }

        return null;
    }

    /**
     * استخراج محتوای متنی
     */
    private function extractTextContent(Crawler $elements): ?string
    {
        $text = trim($elements->first()->text());
        return !empty($text) ? $text : null;
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
     * بررسی وجود صفحه بعد
     */
    private function hasNextPage(Crawler $crawler, array $paginationSettings): bool
    {
        if (!($paginationSettings['enabled'] ?? false) || empty($paginationSettings['selector'])) {
            return false;
        }

        $nextElements = $crawler->filter($paginationSettings['selector']);
        return $nextElements->count() > 0;
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
                // استخراج سال از متن
                if (preg_match('/(\d{4})/', (string) $value, $matches)) {
                    return (int) $matches[1];
                }
                return null;

            case 'pages_count':
            case 'file_size':
                // استخراج عدد از متن
                if (preg_match('/(\d+)/', (string) $value, $matches)) {
                    return (int) $matches[1];
                }
                return null;

            case 'isbn':
                return preg_replace('/[^0-9X-]/', '', (string) $value);

            case 'language':
                return strtolower(substr((string) $value, 0, 2));

            case 'format':
                $format = strtolower((string) $value);
                $allowedFormats = ['pdf', 'epub', 'mobi', 'djvu', 'audio'];
                return in_array($format, $allowedFormats) ? $format : 'pdf';

            case 'image_url':
            case 'download_url':
                $url = trim((string) $value);
                // تبدیل URL نسبی به مطلق
                if (!empty($url) && !filter_var($url, FILTER_VALIDATE_URL)) {
                    $baseUrl = rtrim($this->config->base_url, '/');
                    if (strpos($url, '/') === 0) {
                        $url = $baseUrl . $url;
                    } else {
                        $url = $baseUrl . '/' . $url;
                    }
                }
                return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;

            default:
                return trim((string) $value);
        }
    }

    /**
     * پردازش اطلاعات یک کتاب
     */
    private function processBookData(array $bookData): void
    {
        // اعتبارسنجی داده‌های ضروری
        if (empty($bookData['title'])) {
            throw new \Exception('عنوان کتاب یافت نشد');
        }

        // ایجاد content_hash
        $contentHash = $this->generateContentHash($bookData);

        // بررسی وجود کتاب
        if (Book::where('content_hash', $contentHash)->exists()) {
            $this->stats['duplicate']++;
            return;
        }

        DB::beginTransaction();

        try {
            // پیدا کردن یا ایجاد دسته‌بندی
            $category = $this->findOrCreateCategory($bookData['category'] ?? 'عمومی');

            // پیدا کردن یا ایجاد ناشر
            $publisher = null;
            if (!empty($bookData['publisher'])) {
                $publisher = $this->findOrCreatePublisher($bookData['publisher']);
            }

            // ایجاد کتاب
            $book = $this->createBook($bookData, $contentHash, $category, $publisher);

            // پردازش نویسندگان
            if (!empty($bookData['author'])) {
                $this->processAuthors($book, $bookData['author']);
            }

            // پردازش تصاویر
            if (!empty($bookData['image_url'])) {
                $this->processImages($book, $bookData['image_url']);
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
     * دریافت آمار پردازش
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}
