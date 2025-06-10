<?php

namespace App\Services;

use App\Models\Config;

class FieldExtractor
{
    public function extractFields(array $data, Config $config): array
    {
        $apiSettings = $config->getApiSettings();
        $fieldMapping = $apiSettings['field_mapping'] ?? $this->getDefaultMapping();

        $extracted = [];
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

        return $extracted;
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
