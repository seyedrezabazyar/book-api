<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DataValidator
{
    public function cleanAndValidate(array $data): array
    {
        $cleaned = [];

        Log::debug("🧹 شروع تمیز کردن داده‌ها", [
            'input_fields' => array_keys($data)
        ]);

        // فیلدهای متنی
        $cleaned['title'] = $this->cleanTitle($data['title'] ?? '');
        $cleaned['description'] = $this->cleanDescription($data['description'] ?? '');
        $cleaned['author'] = $this->cleanText($data['author'] ?? '');
        $cleaned['publisher'] = $this->cleanText($data['publisher'] ?? '');
        $cleaned['category'] = $this->cleanText($data['category'] ?? '');

        // فیلدهای عددی
        $cleaned['publication_year'] = $this->cleanYear($data['publication_year'] ?? null);
        $cleaned['pages_count'] = $this->cleanPositiveInt($data['pages_count'] ?? null);
        $cleaned['file_size'] = $this->cleanFileSize($data['file_size'] ?? null);

        // فیلدهای خاص
        $cleaned['isbn'] = $this->cleanIsbn($data['isbn'] ?? '');
        $cleaned['language'] = $this->cleanLanguage($data['language'] ?? '');
        $cleaned['format'] = $this->cleanFormat($data['format'] ?? '');
        $cleaned['image_url'] = $this->cleanUrl($data['image_url'] ?? '');

        // هش‌ها
        $cleaned = array_merge($cleaned, $this->cleanHashes($data));

        // حذف مقادیر خالی
        $cleaned = array_filter($cleaned, fn($value) => $value !== null && $value !== '');

        Log::info("✅ تمیز کردن تمام شد", [
            'output_fields' => array_keys($cleaned),
            'has_title' => !empty($cleaned['title'])
        ]);

        return $cleaned;
    }

    private function cleanTitle(string $title): string
    {
        $title = strip_tags(trim($title));
        $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
        $title = preg_replace('/\s+/', ' ', $title);
        $title = preg_replace('/\s*[\[\(]*(pdf|epub|mobi|djvu|free|download)[\]\)]*\s*$/i', '', $title);

        if (strlen($title) > 500) {
            $title = Str::limit($title, 500, '');
        }

        return strlen(trim($title)) >= 2 ? trim($title) : '';
    }

    private function cleanDescription(string $description): string
    {
        if (empty($description)) return '';

        $description = strip_tags(trim($description));
        $description = html_entity_decode($description, ENT_QUOTES, 'UTF-8');
        $description = preg_replace('/\s+/', ' ', $description);

        // حذف اسپم
        $spamPatterns = [
            '/download\s+free/i',
            '/click\s+here/i',
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

    private function cleanText(string $text): string
    {
        if (empty($text)) return '';

        $text = strip_tags(trim($text));
        $text = preg_replace('/\s+/', ' ', $text);

        return strlen($text) > 200 ? Str::limit($text, 200, '') : $text;
    }

    private function cleanYear($year): ?int
    {
        if (!is_numeric($year)) return null;

        $year = (int)$year;
        $currentYear = (int)date('Y');

        return ($year >= 1000 && $year <= $currentYear + 5) ? $year : null;
    }

    private function cleanPositiveInt($value): ?int
    {
        if (!is_numeric($value) || $value <= 0) return null;

        $int = (int)$value;
        return ($int >= 1 && $int <= 50000) ? $int : null;
    }

    private function cleanFileSize($size): ?int
    {
        if (empty($size)) return null;

        if (is_string($size)) {
            $size = strtolower(trim($size));
            preg_match('/([0-9]+(?:\.[0-9]+)?)/', $size, $matches);
            if (!isset($matches[1])) return null;

            $number = (float)$matches[1];
            if (strpos($size, 'gb') !== false) {
                $size = $number * 1024 * 1024 * 1024;
            } elseif (strpos($size, 'mb') !== false) {
                $size = $number * 1024 * 1024;
            } elseif (strpos($size, 'kb') !== false) {
                $size = $number * 1024;
            } else {
                $size = $number;
            }
        }

        if (!is_numeric($size)) return null;

        $size = (int)$size;
        return ($size >= 1024 && $size <= 10 * 1024 * 1024 * 1024) ? $size : null;
    }

    private function cleanIsbn(string $isbn): string
    {
        if (empty($isbn)) return '';

        $isbn = preg_replace('/[^0-9X\-]/i', '', $isbn);
        $cleanIsbn = preg_replace('/[^0-9X]/i', '', $isbn);

        return (strlen($cleanIsbn) === 10 || strlen($cleanIsbn) === 13) ? $isbn : '';
    }

    private function cleanLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        if (empty($language)) return 'fa';

        $langMap = [
            'persian' => 'fa', 'farsi' => 'fa', 'فارسی' => 'fa',
            'english' => 'en', 'انگلیسی' => 'en',
            'arabic' => 'ar', 'عربی' => 'ar'
        ];

        return $langMap[$language] ?? (strlen($language) === 2 ? $language : 'fa');
    }

    private function cleanFormat(string $format): string
    {
        $format = strtolower(trim($format));
        if (empty($format)) return 'pdf';

        $allowedFormats = ['pdf', 'epub', 'mobi', 'djvu', 'audio', 'txt'];
        return in_array($format, $allowedFormats) ? $format : 'pdf';
    }

    private function cleanUrl(string $url): string
    {
        $url = trim($url);
        if (empty($url)) return '';

        if (!filter_var($url, FILTER_VALIDATE_URL)) return '';

        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        return in_array($extension, $allowedExtensions) ? $url : '';
    }

    private function cleanHashes(array $data): array
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

        // مگنت لینک
        if (isset($data['magnet'])) {
            $magnet = trim($data['magnet']);
            if (str_starts_with(strtolower($magnet), 'magnet:?xt=') &&
                preg_match('/xt=urn:btih:([a-f0-9]{40})/i', $magnet)) {
                $hashes['magnet'] = $magnet;
            }
        }

        return $hashes;
    }

    private function cleanHash(string $hash, array $config): ?string
    {
        $hash = strtolower(trim($hash));
        $hash = preg_replace('/[^a-f0-9]/', '', $hash);

        if (strlen($hash) !== $config['length']) return null;
        if (!preg_match($config['pattern'], $hash)) return null;

        return $hash;
    }
}
