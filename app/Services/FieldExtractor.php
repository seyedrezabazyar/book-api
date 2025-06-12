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

        Log::debug("🔍 شروع استخراج فیلدها", [
            'config_id' => $config->id,
            'data_keys' => array_keys($data)
        ]);

        // استخراج فیلدهای اصلی
        foreach ($fieldMapping as $bookField => $apiField) {
            if (empty($apiField)) continue;

            $value = $this->getNestedValue($data, $apiField);
            if ($value !== null) {
                $sanitized = $this->sanitizeValue($value, $bookField);
                if ($sanitized !== null && $sanitized !== '') {
                    $extracted[$bookField] = $sanitized;
                }
            }
        }

        // استخراج هش‌ها
        $this->extractHashes($data, $extracted);

        Log::info("✅ استخراج فیلدها تمام شد", [
            'extracted_fields' => array_keys($extracted),
            'has_title' => !empty($extracted['title'])
        ]);

        return $extracted;
    }

    private function extractHashes(array $data, array &$extracted): void
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
            'magnet_link' => 'magnet'
        ];

        // هش‌های مستقیم
        foreach ($hashFields as $sourceKey => $targetKey) {
            if (isset($data[$sourceKey]) && !empty($data[$sourceKey])) {
                $hashValue = trim($data[$sourceKey]);
                if ($this->isValidHash($hashValue, $targetKey)) {
                    $extracted[$targetKey] = $hashValue;
                }
            }
        }

        // هش‌های تودرتو
        $nestedPaths = [
            'hashes.md5' => 'md5',
            'hashes.sha1' => 'sha1',
            'hashes.sha256' => 'sha256',
            'hashes.btih' => 'btih',
            'download_info.magnet' => 'magnet',
            'torrent.magnet' => 'magnet'
        ];

        foreach ($nestedPaths as $path => $targetKey) {
            if (!isset($extracted[$targetKey])) {
                $value = $this->getNestedValue($data, $path);
                if ($value && $this->isValidHash($value, $targetKey)) {
                    $extracted[$targetKey] = trim($value);
                }
            }
        }

        // استخراج BTIH از مگنت
        if (isset($extracted['magnet']) && !isset($extracted['btih'])) {
            $btih = $this->extractBtihFromMagnet($extracted['magnet']);
            if ($btih) {
                $extracted['btih'] = $btih;
            }
        }
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
            'title', 'description', 'category' => trim(strip_tags((string)$value)),
            'author' => $this->sanitizeAuthor($value),
            'publisher' => is_array($value) ? ($value['name'] ?? '') : trim((string)$value),
            'publication_year' => $this->sanitizeYear($value),
            'pages_count', 'file_size' => $this->sanitizeInteger($value),
            'isbn' => $this->sanitizeIsbn((string)$value),
            'language' => $this->sanitizeLanguage((string)$value),
            'format' => strtolower(trim((string)$value)) ?: 'pdf',
            'image_url' => filter_var(trim((string)$value), FILTER_VALIDATE_URL) ?: null,
            default => trim((string)$value)
        };
    }

    private function sanitizeAuthor($author): string
    {
        if (is_array($author)) {
            $names = [];
            foreach ($author as $item) {
                if (is_array($item)) {
                    $name = $item['name'] ?? $item['full_name'] ?? '';
                    if (empty($name) && isset($item['firstname'], $item['lastname'])) {
                        $name = trim($item['firstname'] . ' ' . $item['lastname']);
                    }
                } else {
                    $name = (string)$item;
                }
                if (!empty(trim($name))) {
                    $names[] = trim($name);
                }
            }
            return implode(', ', array_unique($names));
        }

        return trim((string)$author);
    }

    private function sanitizeYear($value): ?int
    {
        if (!is_numeric($value)) return null;
        $year = (int)$value;
        $currentYear = (int)date('Y');
        return ($year >= 1000 && $year <= $currentYear + 5) ? $year : null;
    }

    private function sanitizeInteger($value): ?int
    {
        if (!is_numeric($value) || $value <= 0) return null;
        return (int)$value;
    }

    private function sanitizeIsbn(string $isbn): string
    {
        $isbn = preg_replace('/[^0-9X-]/i', '', $isbn);
        $cleanIsbn = preg_replace('/[^0-9X]/i', '', $isbn);
        return (strlen($cleanIsbn) === 10 || strlen($cleanIsbn) === 13) ? $isbn : '';
    }

    private function sanitizeLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        $langMap = [
            'persian' => 'fa', 'farsi' => 'fa', 'فارسی' => 'fa',
            'english' => 'en', 'انگلیسی' => 'en'
        ];
        return $langMap[$language] ?? (strlen($language) === 2 ? $language : 'fa');
    }

    private function isValidHash(string $hash, string $type): bool
    {
        $hash = trim($hash);
        if (empty($hash)) return false;

        return match ($type) {
            'md5' => preg_match('/^[a-f0-9]{32}$/i', $hash),
            'sha1' => preg_match('/^[a-f0-9]{40}$/i', $hash),
            'sha256' => preg_match('/^[a-f0-9]{64}$/i', $hash),
            'crc32' => preg_match('/^[a-f0-9]{8}$/i', $hash),
            'ed2k' => preg_match('/^[a-f0-9]{32}$/i', $hash),
            'btih' => preg_match('/^[a-f0-9]{40}$/i', $hash),
            'magnet' => str_starts_with(strtolower($hash), 'magnet:?xt='),
            default => !empty($hash)
        };
    }

    private function extractBtihFromMagnet(string $magnetLink): ?string
    {
        if (preg_match('/xt=urn:btih:([a-f0-9]{40})/i', $magnetLink, $matches)) {
            return strtolower($matches[1]);
        }
        return null;
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
            'image_url' => 'image_url'
        ];
    }
}
