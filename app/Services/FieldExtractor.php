<?php

namespace App\Services;

use App\Models\Config;
use Illuminate\Support\Facades\Log;

class FieldExtractor
{
    public function extractFields(array $data, Config $config): array
    {
        $apiSettings = $config->getApiSettings();
        $fieldMapping = $apiSettings['field_mapping'] ?? $this->getDefaultMapping();

        $extracted = [];

        // استخراج فیلدهای اصلی
        foreach ($fieldMapping as $bookField => $apiField) {
            if (empty($apiField)) continue;

            $value = $this->getNestedValue($data, $apiField);
            if ($value !== null) {
                $sanitized = $this->sanitizeValue($value, $bookField);
                if ($sanitized !== null) {
                    $extracted[$bookField] = $sanitized;
                }
            }
        }

        // استخراج هش‌ها با منطق پیشرفته
        $this->extractHashes($data, $extracted);

        Log::debug("🔍 فیلدهای استخراج شده", [
            'extracted_fields' => array_keys($extracted),
            'has_hashes' => $this->hasHashes($extracted),
            'hash_types' => $this->getHashTypes($extracted)
        ]);

        return $extracted;
    }

    /**
     * استخراج هش‌ها با منطق پیشرفته
     */
    private function extractHashes(array $data, array &$extracted): void
    {
        // 1. هش‌های مستقیم در سطح اول
        $this->extractDirectHashes($data, $extracted);

        // 2. هش‌ها در ساختار تودرتو
        $this->extractNestedHashes($data, $extracted);

        // 3. هش‌ها در آرایه‌های پیچیده
        $this->extractArrayHashes($data, $extracted);

        // 4. لینک‌های مگنت از فیلدهای مختلف
        $this->extractMagnetLinks($data, $extracted);
    }

    /**
     * استخراج هش‌های مستقیم
     */
    private function extractDirectHashes(array $data, array &$extracted): void
    {
        $hashFields = [
            'md5' => 'md5',
            'sha1' => 'sha1',
            'sha256' => 'sha256',
            'crc32' => 'crc32',
            'ed2k' => 'ed2k',
            'ed2k_hash' => 'ed2k',
            'btih' => 'btih',
            'info_hash' => 'btih',
            'magnet' => 'magnet',
            'magnet_link' => 'magnet',
            'magnet_uri' => 'magnet'
        ];

        foreach ($hashFields as $sourceKey => $targetKey) {
            if (isset($data[$sourceKey]) && !empty($data[$sourceKey])) {
                $hashValue = trim($data[$sourceKey]);
                if ($this->isValidHashFormat($hashValue, $targetKey)) {
                    $extracted[$targetKey] = $hashValue;
                    Log::debug("✅ هش مستقیم یافت شد", [
                        'type' => $targetKey,
                        'source_key' => $sourceKey,
                        'value' => substr($hashValue, 0, 16) . '...'
                    ]);
                }
            }
        }
    }

    /**
     * استخراج هش‌های تودرتو
     */
    private function extractNestedHashes(array $data, array &$extracted): void
    {
        $nestedPaths = [
            'hashes.md5' => 'md5',
            'hashes.sha1' => 'sha1',
            'hashes.sha256' => 'sha256',
            'hashes.crc32' => 'crc32',
            'hashes.ed2k' => 'ed2k',
            'hashes.btih' => 'btih',
            'hash_data.md5' => 'md5',
            'hash_data.sha1' => 'sha1',
            'checksums.md5' => 'md5',
            'checksums.sha1' => 'sha1',
            'file_hashes.md5' => 'md5',
            'file_hashes.sha1' => 'sha1',
            'download_info.md5' => 'md5',
            'download_info.sha1' => 'sha1',
            'download_info.magnet' => 'magnet',
            'torrent.btih' => 'btih',
            'torrent.magnet' => 'magnet',
            'links.magnet' => 'magnet'
        ];

        foreach ($nestedPaths as $path => $targetKey) {
            if (!isset($extracted[$targetKey])) { // فقط اگر قبلاً یافت نشده
                $value = $this->getNestedValue($data, $path);
                if ($value && $this->isValidHashFormat($value, $targetKey)) {
                    $extracted[$targetKey] = trim($value);
                    Log::debug("✅ هش تودرتو یافت شد", [
                        'type' => $targetKey,
                        'path' => $path,
                        'value' => substr($value, 0, 16) . '...'
                    ]);
                }
            }
        }
    }

    /**
     * استخراج هش‌ها از آرایه‌های پیچیده
     */
    private function extractArrayHashes(array $data, array &$extracted): void
    {
        // بررسی آرایه files
        if (isset($data['files']) && is_array($data['files'])) {
            foreach ($data['files'] as $file) {
                if (is_array($file)) {
                    $this->extractDirectHashes($file, $extracted);
                    $this->extractNestedHashes($file, $extracted);
                }
            }
        }

        // بررسی آرایه downloads
        if (isset($data['downloads']) && is_array($data['downloads'])) {
            foreach ($data['downloads'] as $download) {
                if (is_array($download)) {
                    $this->extractDirectHashes($download, $extracted);
                    $this->extractNestedHashes($download, $extracted);
                }
            }
        }

        // بررسی آرایه mirrors
        if (isset($data['mirrors']) && is_array($data['mirrors'])) {
            foreach ($data['mirrors'] as $mirror) {
                if (is_array($mirror)) {
                    $this->extractDirectHashes($mirror, $extracted);
                    $this->extractNestedHashes($mirror, $extracted);
                }
            }
        }
    }

    /**
     * استخراج لینک‌های مگنت
     */
    private function extractMagnetLinks(array $data, array &$extracted): void
    {
        if (isset($extracted['magnet'])) {
            return; // قبلاً یافت شده
        }

        $magnetPaths = [
            'magnet',
            'magnet_link',
            'magnet_uri',
            'torrent.magnet',
            'download_links.magnet',
            'links.torrent',
            'mirrors.magnet'
        ];

        foreach ($magnetPaths as $path) {
            $value = $this->getNestedValue($data, $path);
            if ($value && str_starts_with(strtolower(trim($value)), 'magnet:')) {
                $extracted['magnet'] = trim($value);
                Log::debug("✅ لینک مگنت یافت شد", [
                    'path' => $path,
                    'value' => substr($value, 0, 50) . '...'
                ]);
                break;
            }
        }

        // استخراج BTIH از لینک مگنت اگر BTIH جداگانه موجود نیست
        if (isset($extracted['magnet']) && !isset($extracted['btih'])) {
            $btih = $this->extractBtihFromMagnet($extracted['magnet']);
            if ($btih) {
                $extracted['btih'] = $btih;
                Log::debug("✅ BTIH از مگنت استخراج شد", ['btih' => $btih]);
            }
        }
    }

    /**
     * استخراج BTIH از لینک مگنت
     */
    private function extractBtihFromMagnet(string $magnetLink): ?string
    {
        if (preg_match('/xt=urn:btih:([a-f0-9]{40})/i', $magnetLink, $matches)) {
            return strtolower($matches[1]);
        }
        return null;
    }

    /**
     * اعتبارسنجی فرمت هش
     */
    private function isValidHashFormat(string $hash, string $type): bool
    {
        $hash = trim($hash);

        if (empty($hash)) {
            return false;
        }

        switch ($type) {
            case 'md5':
                return preg_match('/^[a-f0-9]{32}$/i', $hash);

            case 'sha1':
                return preg_match('/^[a-f0-9]{40}$/i', $hash);

            case 'sha256':
                return preg_match('/^[a-f0-9]{64}$/i', $hash);

            case 'crc32':
                return preg_match('/^[a-f0-9]{8}$/i', $hash);

            case 'ed2k':
                return preg_match('/^[a-f0-9]{32}$/i', $hash);

            case 'btih':
                return preg_match('/^[a-f0-9]{40}$/i', $hash);

            case 'magnet':
                return str_starts_with(strtolower($hash), 'magnet:?xt=');

            default:
                return !empty($hash);
        }
    }

    /**
     * بررسی وجود هش
     */
    private function hasHashes(array $extracted): bool
    {
        $hashFields = ['md5', 'sha1', 'sha256', 'crc32', 'ed2k', 'btih', 'magnet'];
        foreach ($hashFields as $field) {
            if (!empty($extracted[$field])) {
                return true;
            }
        }
        return false;
    }

    /**
     * دریافت انواع هش موجود
     */
    private function getHashTypes(array $extracted): array
    {
        $hashFields = ['md5', 'sha1', 'sha256', 'crc32', 'ed2k', 'btih', 'magnet'];
        $types = [];
        foreach ($hashFields as $field) {
            if (!empty($extracted[$field])) {
                $types[] = $field;
            }
        }
        return $types;
    }

    private function getDefaultMapping(): array
    {
        return [
            'title' => 'title',
            'description' => 'description',
            'author' => 'authors',
            'category' => 'category',
            'publisher' => 'publisher',
            'isbn' => 'isbn',
            'publication_year' => 'publication_year',
            'pages_count' => 'pages_count',
            'language' => 'language',
            'format' => 'format',
            'file_size' => 'file_size',
            'image_url' => 'image_url',
            'sha1' => 'sha1',
            'sha256' => 'sha256',
            'crc32' => 'crc32',
            'ed2k' => 'ed2k_hash',
            'btih' => 'btih',
            'magnet' => 'magnet_link'
        ];
    }

    private function getNestedValue(array $data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (is_array($value)) {
                $value = is_numeric($key) ? $value[(int)$key] ?? null : $value[$key] ?? null;
            } else {
                return null;
            }
        }

        return $value;
    }

    private function sanitizeValue($value, string $fieldType)
    {
        if ($value === null) return null;

        return match ($fieldType) {
            'title', 'description', 'category' => trim((string)$value),
            'author' => $this->extractAuthors($value),
            'publisher' => is_array($value) ? ($value['name'] ?? '') : trim((string)$value),
            'publication_year' => $this->validateYear($value),
            'pages_count', 'file_size' => $this->validatePositiveInteger($value),
            'isbn' => $this->cleanIsbn((string)$value),
            'language' => $this->normalizeLanguage((string)$value),
            'format' => $this->normalizeFormat((string)$value),
            'image_url' => filter_var(trim((string)$value), FILTER_VALIDATE_URL) ?: null,
            'sha1', 'sha256', 'crc32', 'ed2k', 'btih' => is_string($value) ? trim($value) : null,
            'magnet' => is_string($value) && str_starts_with($value, 'magnet:') ? trim($value) : null,
            default => trim((string)$value)
        };
    }

    private function extractAuthors($authorsData): ?string
    {
        if (is_string($authorsData)) {
            return trim($authorsData);
        }

        if (is_array($authorsData)) {
            $names = [];
            foreach ($authorsData as $author) {
                if (is_array($author) && isset($author['name'])) {
                    $names[] = trim($author['name']);
                } elseif (is_string($author)) {
                    $names[] = trim($author);
                }
            }
            return !empty($names) ? implode(', ', $names) : null;
        }

        return null;
    }

    private function validateYear($value): ?int
    {
        if (!is_numeric($value)) return null;

        $year = (int)$value;
        $currentYear = (int)date('Y');

        return ($year >= 1000 && $year <= $currentYear + 5) ? $year : null;
    }

    private function validatePositiveInteger($value): ?int
    {
        if (!is_numeric($value) || $value <= 0) return null;
        return (int)$value;
    }

    private function cleanIsbn(string $isbn): string
    {
        $isbn = preg_replace('/[^0-9X-]/i', '', $isbn);
        $cleanIsbn = preg_replace('/[^0-9X]/i', '', $isbn);

        return (strlen($cleanIsbn) === 10 || strlen($cleanIsbn) === 13) ? $isbn : '';
    }

    private function normalizeLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        $langMap = ['persian' => 'fa', 'english' => 'en', 'فارسی' => 'fa'];
        return $langMap[$language] ?? substr($language, 0, 2);
    }

    private function normalizeFormat(string $format): string
    {
        $format = strtolower(trim($format));
        $allowedFormats = ['pdf', 'epub', 'mobi', 'djvu'];
        return in_array($format, $allowedFormats) ? $format : 'pdf';
    }
}
