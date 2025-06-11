<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DataValidator
{
    public function cleanAndValidate(array $data): array
    {
        $cleaned = [];

        Log::debug("🧹 شروع تمیز کردن و اعتبارسنجی داده‌ها", [
            'input_fields' => array_keys($data),
            'input_count' => count($data)
        ]);

        $cleaned['title'] = $this->cleanAndValidateTitle($data['title'] ?? '');
        $cleaned['description'] = $this->cleanAndValidateDescription($data['description'] ?? '');
        $cleaned['author'] = $this->cleanAndValidateAuthor($data['author'] ?? '');
        $cleaned['publisher'] = $this->cleanAndValidatePublisher($data['publisher'] ?? '');
        $cleaned['category'] = $this->cleanAndValidateCategory($data['category'] ?? '');

        $cleaned['publication_year'] = $this->cleanAndValidatePublicationYear($data['publication_year'] ?? null);
        $cleaned['pages_count'] = $this->cleanAndValidatePagesCount($data['pages_count'] ?? null);
        $cleaned['file_size'] = $this->cleanAndValidateFileSize($data['file_size'] ?? null);

        $cleaned['isbn'] = $this->cleanAndValidateIsbn($data['isbn'] ?? '');
        $cleaned['language'] = $this->cleanAndValidateLanguage($data['language'] ?? '');
        $cleaned['format'] = $this->cleanAndValidateFormat($data['format'] ?? '');
        $cleaned['image_url'] = $this->cleanAndValidateImageUrl($data['image_url'] ?? '');

        $cleaned = array_merge($cleaned, $this->cleanAndValidateHashes($data));

        $cleaned = array_filter($cleaned, function($value) {
            return $value !== null && $value !== '';
        });

        $this->performFinalValidation($cleaned);

        Log::info("✅ تمیز کردن و اعتبارسنجی تمام شد", [
            'output_fields' => array_keys($cleaned),
            'output_count' => count($cleaned),
            'has_required_fields' => $this->hasRequiredFields($cleaned)
        ]);

        return $cleaned;
    }

    private function cleanAndValidateTitle(string $title): string
    {
        $title = trim($title);

        if (empty($title)) {
            return '';
        }

        $title = strip_tags($title);
        $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
        $title = preg_replace('/\s+/', ' ', $title);
        $title = preg_replace('/[^\p{L}\p{N}\s\-\.\(\)\[\]:]/u', '', $title);
        $title = preg_replace('/\s*[\[\(]*(pdf|epub|mobi|djvu|free|download)[\]\)]*\s*$/i', '', $title);

        if (strlen($title) > 500) {
            $title = Str::limit($title, 500, '');
        }

        $title = trim($title);

        if (strlen($title) < 2) {
            Log::warning("عنوان بعد از تمیز کردن خیلی کوتاه شد", [
                'cleaned' => $title
            ]);
            return '';
        }

        return $title;
    }

    private function cleanAndValidateDescription(string $description): string
    {
        $description = trim($description);

        if (empty($description)) {
            return '';
        }

        $description = strip_tags($description);
        $description = html_entity_decode($description, ENT_QUOTES, 'UTF-8');
        $description = preg_replace('/\s+/', ' ', $description);
        $description = preg_replace('/\n\s*\n/', "\n\n", $description);

        $spamPatterns = [
            '/download\s+free/i',
            '/click\s+here/i',
            '/visit\s+our\s+website/i',
            '/www\.[^\s]+/i'
        ];

        foreach ($spamPatterns as $pattern) {
            $description = preg_replace($pattern, '', $description);
        }

        if (strlen($description) > 5000) {
            $description = Str::limit($description, 5000, '...');
        }

        return trim($description);
    }

    private function cleanAndValidateAuthor($author): string
    {
        if (is_array($author)) {
            return $this->extractAndCleanAuthorsFromArray($author);
        }

        if (!is_string($author)) {
            return '';
        }

        $author = trim($author);
        if (empty($author)) {
            return '';
        }

        $author = preg_replace('/[^\p{L}\p{N}\s\-\.,،؛;&]/u', '', $author);
        $author = preg_replace('/[،؛;&]/', ',', $author);
        $author = preg_replace('/\s+and\s+/i', ', ', $author);
        $author = preg_replace('/\s+و\s+/', ', ', $author);
        $author = preg_replace('/\s+/', ' ', $author);
        $author = preg_replace('/,\s*,+/', ',', $author);

        $authors = array_filter(explode(',', $author), function($name) {
            $name = trim($name);
            return strlen($name) >= 2 && !preg_match('/^[0-9\-\s]+$/', $name);
        });

        $result = implode(', ', array_map('trim', $authors));
        return trim($result, ', ');
    }

    private function extractAndCleanAuthorsFromArray(array $authors): string
    {
        $names = [];

        foreach ($authors as $author) {
            if (is_array($author)) {
                $possibleKeys = ['name', 'full_name', 'author_name', 'firstname', 'lastname', 'title'];
                $authorName = '';

                foreach ($possibleKeys as $key) {
                    if (isset($author[$key]) && !empty(trim($author[$key]))) {
                        if ($key === 'firstname' || $key === 'lastname') {
                            $authorName .= trim($author[$key]) . ' ';
                        } else {
                            $authorName = trim($author[$key]);
                            break;
                        }
                    }
                }

                if (!empty($authorName)) {
                    $names[] = trim($authorName);
                }
            } elseif (is_string($author)) {
                $cleanAuthor = trim($author);
                if (!empty($cleanAuthor) && strlen($cleanAuthor) >= 2) {
                    $names[] = $cleanAuthor;
                }
            }
        }

        return implode(', ', array_unique($names));
    }

    private function cleanAndValidatePublisher(string $publisher): string
    {
        $publisher = trim($publisher);
        if (empty($publisher)) {
            return '';
        }

        $publisher = preg_replace('/[^\p{L}\p{N}\s\-\.\&]/u', '', $publisher);
        $publisher = preg_replace('/\s+/', ' ', $publisher);

        if (strlen($publisher) > 200) {
            $publisher = Str::limit($publisher, 200, '');
        }

        return trim($publisher);
    }

    private function cleanAndValidateCategory(string $category): string
    {
        $category = trim($category);
        if (empty($category)) {
            return '';
        }

        $category = preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $category);
        $category = preg_replace('/\s+/', ' ', $category);

        if (strlen($category) > 100) {
            $category = Str::limit($category, 100, '');
        }

        return trim($category);
    }

    private function cleanAndValidatePublicationYear($year): ?int
    {
        if ($year === null || $year === '') {
            return null;
        }

        if (is_string($year)) {
            $year = preg_replace('/[^0-9]/', '', $year);
        }

        if (!is_numeric($year)) {
            return null;
        }

        $year = (int)$year;
        $currentYear = (int)date('Y');

        if ($year < 1000 || $year > $currentYear + 5) {
            Log::debug("سال انتشار نامعتبر", [
                'year' => $year,
                'current_year' => $currentYear
            ]);
            return null;
        }

        return $year;
    }

    private function cleanAndValidatePagesCount($pages): ?int
    {
        if ($pages === null || $pages === '') {
            return null;
        }

        if (is_string($pages)) {
            $pages = preg_replace('/[^0-9]/', '', $pages);
        }

        if (!is_numeric($pages)) {
            return null;
        }

        $pages = (int)$pages;

        if ($pages < 1 || $pages > 50000) {
            Log::debug("تعداد صفحات نامعتبر", ['pages' => $pages]);
            return null;
        }

        return $pages;
    }

    private function cleanAndValidateFileSize($size): ?int
    {
        if ($size === null || $size === '') {
            return null;
        }

        if (is_string($size)) {
            $size = strtolower(trim($size));

            preg_match('/([0-9]+(?:\.[0-9]+)?)/', $size, $matches);
            if (!isset($matches[1])) {
                return null;
            }

            $number = (float)$matches[1];

            if (strpos($size, 'gb') !== false || strpos($size, 'گیگ') !== false) {
                $size = $number * 1024 * 1024 * 1024;
            } elseif (strpos($size, 'mb') !== false || strpos($size, 'مگ') !== false) {
                $size = $number * 1024 * 1024;
            } elseif (strpos($size, 'kb') !== false || strpos($size, 'کیلو') !== false) {
                $size = $number * 1024;
            } else {
                $size = $number;
            }
        }

        if (!is_numeric($size)) {
            return null;
        }

        $size = (int)$size;

        if ($size < 1024 || $size > 10 * 1024 * 1024 * 1024) {
            Log::debug("اندازه فایل نامعتبر", ['size' => $size]);
            return null;
        }

        return $size;
    }

    private function cleanAndValidateIsbn(string $isbn): string
    {
        $isbn = trim($isbn);
        if (empty($isbn)) {
            return '';
        }

        $isbn = preg_replace('/[^0-9X\-]/i', '', $isbn);
        $cleanIsbn = preg_replace('/[^0-9X]/i', '', $isbn);

        if (strlen($cleanIsbn) !== 10 && strlen($cleanIsbn) !== 13) {
            Log::debug("طول ISBN نامعتبر", [
                'clean_length' => strlen($cleanIsbn)
            ]);
            return '';
        }

        return $isbn;
    }

    private function cleanAndValidateLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        if (empty($language)) {
            return 'fa';
        }

        $langMap = [
            'persian' => 'fa',
            'farsi' => 'fa',
            'فارسی' => 'fa',
            'english' => 'en',
            'انگلیسی' => 'en',
            'arabic' => 'ar',
            'عربی' => 'ar',
            'french' => 'fr',
            'فرانسوی' => 'fr',
            'german' => 'de',
            'آلمانی' => 'de',
            'spanish' => 'es',
            'اسپانیایی' => 'es'
        ];

        if (isset($langMap[$language])) {
            return $langMap[$language];
        }

        if (strlen($language) === 2 && preg_match('/^[a-z]{2}$/', $language)) {
            return $language;
        }

        return 'fa';
    }

    private function cleanAndValidateFormat(string $format): string
    {
        $format = strtolower(trim($format));
        if (empty($format)) {
            return 'pdf';
        }

        $allowedFormats = ['pdf', 'epub', 'mobi', 'djvu', 'audio', 'txt'];

        $formatMap = [
            'portable document format' => 'pdf',
            'electronic publication' => 'epub',
            'e-book' => 'epub',
            'audiobook' => 'audio',
            'text' => 'txt'
        ];

        if (isset($formatMap[$format])) {
            return $formatMap[$format];
        }

        if (in_array($format, $allowedFormats)) {
            return $format;
        }

        return 'pdf';
    }

    private function cleanAndValidateImageUrl(string $url): string
    {
        $url = trim($url);
        if (empty($url)) {
            return '';
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            Log::debug("URL تصویر نامعتبر", ['url' => $url]);
            return '';
        }

        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!in_array($extension, $allowedExtensions)) {
            Log::debug("پسوند تصویر نامعتبر", [
                'url' => $url,
                'extension' => $extension
            ]);
            return '';
        }

        return $url;
    }

    private function cleanAndValidateHashes(array $data): array
    {
        $hashes = [];
        $hashFields = [
            'md5' => ['length' => 32, 'pattern' => '/^[a-f0-9]{32}$/i'],
            'sha1' => ['length' => 40, 'pattern' => '/^[a-f0-9]{40}$/i'],
            'sha256' => ['length' => 64, 'pattern' => '/^[a-f0-9]{64}$/i'],
            'crc32' => ['length' => 8, 'pattern' => '/^[a-f0-9]{8}$/i'],
            'ed2k' => ['length' => 32, 'pattern' => '/^[a-f0-9]{32}$/i'],
            'btih' => ['length' => 40, 'pattern' => '/^[a-f0-9]{40}$/i']
        ];

        foreach ($hashFields as $field => $config) {
            if (isset($data[$field])) {
                $hash = $this->cleanHash($data[$field], $config);
                if ($hash) {
                    $hashes[$field] = $hash;
                }
            }
        }

        if (isset($data['magnet'])) {
            $magnet = $this->cleanMagnetLink($data['magnet']);
            if ($magnet) {
                $hashes['magnet'] = $magnet;
            }
        }

        return $hashes;
    }

    private function cleanHash(string $hash, array $config): ?string
    {
        $hash = strtolower(trim($hash));
        $hash = preg_replace('/[^a-f0-9]/', '', $hash);

        if (strlen($hash) !== $config['length']) {
            return null;
        }

        if (!preg_match($config['pattern'], $hash)) {
            return null;
        }

        return $hash;
    }

    private function cleanMagnetLink(string $magnet): ?string
    {
        $magnet = trim($magnet);

        if (!str_starts_with(strtolower($magnet), 'magnet:?xt=')) {
            return null;
        }

        if (!preg_match('/xt=urn:btih:([a-f0-9]{40})/i', $magnet)) {
            return null;
        }

        return $magnet;
    }

    private function performFinalValidation(array $data): void
    {
        if (empty($data['title'])) {
            Log::warning('عنوان خالی است');
        }

        if (isset($data['publication_year']) && isset($data['pages_count'])) {
            $currentYear = (int)date('Y');
            if ($data['publication_year'] > $currentYear && $data['pages_count'] > 1000) {
                Log::warning('سال آینده با تعداد صفحات زیاد مشکوک است');
            }
        }

        if (isset($data['format']) && isset($data['file_size'])) {
            $expectedSizes = [
                'pdf' => [1024 * 100, 1024 * 1024 * 100],
                'epub' => [1024 * 50, 1024 * 1024 * 50],
                'audio' => [1024 * 1024, 1024 * 1024 * 1024]
            ];

            if (isset($expectedSizes[$data['format']])) {
                [$min, $max] = $expectedSizes[$data['format']];
                if ($data['file_size'] < $min || $data['file_size'] > $max) {
                    Log::warning("اندازه فایل برای فرمت {$data['format']} غیرعادی است");
                }
            }
        }
    }

    private function hasRequiredFields(array $data): bool
    {
        return !empty($data['title']);
    }
}
