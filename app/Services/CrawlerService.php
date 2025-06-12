<?php

namespace App\Services;

use App\Models\Config;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

class CrawlerService
{
    private Config $config;
    private array $headers;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->setupHeaders();
    }

    private function setupHeaders(): void
    {
        $this->headers = [
            'User-Agent' => $this->config->user_agent ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
            'Accept-Encoding' => 'gzip, deflate',
            'Connection' => 'keep-alive',
            'Cache-Control' => 'no-cache',
        ];

        // اضافه کردن headers اضافی از config
        if ($this->config->headers) {
            $customHeaders = json_decode($this->config->headers, true);
            if (is_array($customHeaders)) {
                $this->headers = array_merge($this->headers, $customHeaders);
            }
        }
    }

    /**
     * کراول یک صفحه و استخراج داده‌ها
     */
    public function crawlPage(int $sourceId): array
    {
        Log::info("🕷️ شروع کراول صفحه", [
            'source_id' => $sourceId,
            'config_id' => $this->config->id,
            'source_name' => $this->config->source_name
        ]);

        try {
            $url = $this->buildPageUrl($sourceId);
            $response = $this->makeRequest($url);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => "HTTP {$response->status()}: {$response->reason()}",
                    'status_code' => $response->status(),
                    'is_404' => $response->status() === 404
                ];
            }

            $html = $response->body();
            if (empty($html)) {
                return [
                    'success' => false,
                    'error' => 'صفحه خالی دریافت شد',
                    'status_code' => $response->status()
                ];
            }

            $data = $this->extractDataFromHtml($html, $sourceId);

            Log::info("✅ کراول موفق", [
                'source_id' => $sourceId,
                'extracted_fields' => array_keys($data),
                'has_title' => !empty($data['title'])
            ]);

            return [
                'success' => true,
                'data' => $data,
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error("❌ خطا در کراول صفحه", [
                'source_id' => $sourceId,
                'config_id' => $this->config->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => null
            ];
        }
    }

    /**
     * ساخت URL صفحه
     */
    private function buildPageUrl(int $sourceId): string
    {
        $baseUrl = rtrim($this->config->base_url, '/');
        $pattern = $this->config->page_pattern ?: '/book/{id}';

        // جایگزینی {id} با sourceId
        $path = str_replace('{id}', $sourceId, $pattern);
        $fullUrl = $baseUrl . $path;

        Log::debug("🔗 URL ساخته شد", [
            'source_id' => $sourceId,
            'pattern' => $pattern,
            'full_url' => $fullUrl
        ]);

        return $fullUrl;
    }

    /**
     * درخواست HTTP
     */
    private function makeRequest(string $url): Response
    {
        $generalSettings = $this->config->getGeneralSettings();

        return Http::timeout($this->config->timeout)
            ->withHeaders($this->headers)
            ->retry(2, 1000)
            ->when(
                !($generalSettings['verify_ssl'] ?? $this->config->verify_ssl ?? true),
                fn($client) => $client->withoutVerifying()
            )
            ->when(
                $generalSettings['follow_redirects'] ?? $this->config->follow_redirects ?? true,
                fn($client) => $client->withOptions(['allow_redirects' => true])
            )
            ->get($url);
    }

    /**
     * استخراج داده‌ها از HTML
     */
    private function extractDataFromHtml(string $html, int $sourceId): array
    {
        $crawler = new Crawler($html);
        $crawlerSettings = $this->config->getCrawlerSettings();
        $selectorMapping = $crawlerSettings['selector_mapping'] ?? [];

        $data = [];

        Log::debug("🔍 شروع استخراج داده‌ها", [
            'source_id' => $sourceId,
            'available_selectors' => array_keys($selectorMapping)
        ]);

        foreach ($selectorMapping as $field => $selector) {
            if (empty($selector)) {
                continue;
            }

            try {
                $value = $this->extractFieldValue($crawler, $field, $selector);

                if ($value !== null && $value !== '') {
                    $data[$field] = $value;

                    Log::debug("✅ فیلد استخراج شد", [
                        'field' => $field,
                        'selector' => $selector,
                        'value_preview' => mb_substr($value, 0, 100)
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning("⚠️ خطا در استخراج فیلد", [
                    'field' => $field,
                    'selector' => $selector,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // استخراج تصاویر
        $this->extractImages($crawler, $data, $crawlerSettings);

        // استخراج لینک‌های دانلود
        $this->extractDownloadLinks($crawler, $data, $crawlerSettings);

        Log::info("📊 استخراج کامل شد", [
            'source_id' => $sourceId,
            'total_fields' => count($data),
            'extracted_fields' => array_keys($data)
        ]);

        return $data;
    }

    /**
     * استخراج مقدار یک فیلد
     */
    private function extractFieldValue(Crawler $crawler, string $field, string $selector): ?string
    {
        $nodes = $crawler->filter($selector);

        if ($nodes->count() === 0) {
            return null;
        }

        // اولویت‌بندی استخراج بر اساس نوع فیلد
        return match ($field) {
            'title', 'description', 'category', 'publisher', 'language', 'format' =>
            $this->extractText($nodes),

            'author' => $this->extractAuthors($nodes),

            'image_url' => $this->extractImageUrl($nodes),

            'isbn' => $this->extractIsbn($nodes),

            'publication_year' => $this->extractYear($nodes),

            'pages_count', 'file_size' => $this->extractNumber($nodes),

            'sha1', 'sha256', 'md5', 'crc32', 'ed2k', 'btih' =>
            $this->extractHash($nodes),

            'magnet' => $this->extractMagnet($nodes),

            default => $this->extractText($nodes)
        };
    }

    /**
     * استخراج متن ساده
     */
    private function extractText(Crawler $nodes): ?string
    {
        $text = $nodes->first()->text('');
        $text = html_entity_decode(trim($text), ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        return !empty($text) ? $text : null;
    }

    /**
     * استخراج نویسندگان (ممکن است چندتا باشد)
     */
    private function extractAuthors(Crawler $nodes): ?string
    {
        $authors = [];

        $nodes->each(function (Crawler $node) use (&$authors) {
            $author = trim($node->text(''));
            if (!empty($author)) {
                $authors[] = $author;
            }
        });

        return !empty($authors) ? implode(', ', array_unique($authors)) : null;
    }

    /**
     * استخراج URL تصویر
     */
    private function extractImageUrl(Crawler $nodes): ?string
    {
        $node = $nodes->first();

        // بررسی attribute های مختلف
        $attributes = ['src', 'data-src', 'data-original', 'href'];

        foreach ($attributes as $attr) {
            $url = $node->attr($attr);
            if ($url && filter_var($url, FILTER_VALIDATE_URL)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * استخراج ISBN
     */
    private function extractIsbn(Crawler $nodes): ?string
    {
        $text = $this->extractText($nodes);
        if (!$text) return null;

        // جستجوی ISBN در متن
        if (preg_match('/(?:ISBN[:\s-]*)?([0-9X-]{10,17})/i', $text, $matches)) {
            $isbn = preg_replace('/[^0-9X]/i', '', $matches[1]);

            if (strlen($isbn) === 10 || strlen($isbn) === 13) {
                return $matches[1]; // برگرداندن با format اصلی
            }
        }

        return null;
    }

    /**
     * استخراج سال
     */
    private function extractYear(Crawler $nodes): ?int
    {
        $text = $this->extractText($nodes);
        if (!$text) return null;

        // جستجوی سال 4 رقمی
        if (preg_match('/\b(19|20)\d{2}\b/', $text, $matches)) {
            $year = (int)$matches[0];
            $currentYear = (int)date('Y');

            if ($year >= 1000 && $year <= $currentYear + 5) {
                return $year;
            }
        }

        return null;
    }

    /**
     * استخراج عدد
     */
    private function extractNumber(Crawler $nodes): ?int
    {
        $text = $this->extractText($nodes);
        if (!$text) return null;

        // حذف کاما و فاصله‌ها
        $text = preg_replace('/[,\s]/', '', $text);

        // استخراج عدد
        if (preg_match('/\d+/', $text, $matches)) {
            $number = (int)$matches[0];
            return $number > 0 ? $number : null;
        }

        return null;
    }

    /**
     * استخراج هش
     */
    private function extractHash(Crawler $nodes): ?string
    {
        $text = $this->extractText($nodes);
        if (!$text) return null;

        // حذف فاصله‌ها و کاراکترهای غیرضروری
        $hash = preg_replace('/[^a-f0-9]/i', '', $text);

        // بررسی طول صحیح هش
        $validLengths = [8, 32, 40, 64]; // CRC32, MD5/ED2K, SHA1/BTIH, SHA256

        return in_array(strlen($hash), $validLengths) ? strtolower($hash) : null;
    }

    /**
     * استخراج لینک مگنت
     */
    private function extractMagnet(Crawler $nodes): ?string
    {
        $node = $nodes->first();

        // بررسی href
        $href = $node->attr('href');
        if ($href && str_starts_with(strtolower($href), 'magnet:?xt=')) {
            return $href;
        }

        // بررسی متن
        $text = $node->text('');
        if (str_starts_with(strtolower($text), 'magnet:?xt=')) {
            return $text;
        }

        return null;
    }

    /**
     * استخراج تصاویر
     */
    private function extractImages(Crawler $crawler, array &$data, array $settings): void
    {
        $imageSelectors = $settings['image_selectors'] ?? [];

        foreach ($imageSelectors as $selector) {
            try {
                $nodes = $crawler->filter($selector);
                if ($nodes->count() > 0) {
                    $imageUrl = $this->extractImageUrl($nodes);
                    if ($imageUrl && !isset($data['image_url'])) {
                        $data['image_url'] = $imageUrl;
                        break;
                    }
                }
            } catch (\Exception $e) {
                Log::debug("خطا در استخراج تصویر", ['selector' => $selector, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * استخراج لینک‌های دانلود
     */
    private function extractDownloadLinks(Crawler $crawler, array &$data, array $settings): void
    {
        $downloadSelectors = $settings['download_selectors'] ?? [];

        foreach ($downloadSelectors as $type => $selector) {
            try {
                $nodes = $crawler->filter($selector);
                if ($nodes->count() > 0) {
                    $link = $nodes->first()->attr('href');
                    if ($link) {
                        if ($type === 'magnet' && str_starts_with(strtolower($link), 'magnet:')) {
                            $data['magnet'] = $link;
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::debug("خطا در استخراج لینک دانلود", ['type' => $type, 'error' => $e->getMessage()]);
            }
        }
    }
}
