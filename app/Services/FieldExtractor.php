<?php

namespace App\Services;

use App\Models\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FieldExtractor
{
    /**
     * استخراج فیلدها با منطق بهبود یافته
     */
    public function extractFields(array $data, Config $config): array
    {
        $apiSettings = $config->getApiSettings();
        $fieldMapping = $apiSettings['field_mapping'] ?? $this->getDefaultMapping();

        $extracted = [];

        Log::debug("🔍 شروع استخراج فیلدها", [
            'config_id' => $config->id,
            'source_name' => $config->source_name,
            'data_keys' => array_keys($data),
            'field_mapping_count' => count($fieldMapping)
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

        // استخراج هش‌ها با منطق پیشرفته
        $this->extractHashesIntelligently($data, $extracted);

        // اعتبارسنجی و بهبود داده‌های استخراج شده
        $this->validateAndEnhanceExtractedData($extracted);

        Log::info("✅ استخراج فیلدها تمام شد", [
            'config_id' => $config->id,
            'extracted_fields' => array_keys($extracted),
            'has_title' => !empty($extracted['title']),
            'has_author' => !empty($extracted['author']),
            'has_hashes' => $this->hasHashes($extracted),
            'hash_types' => $this->getHashTypes($extracted)
        ]);

        return $extracted;
    }

    /**
     * استخراج هش‌ها با منطق هوشمند و پیشرفته
     */
    private function extractHashesIntelligently(array $data, array &$extracted): void
    {
        Log::debug("🔐 شروع استخراج هش‌ها", [
            'data_structure' => $this->analyzeDataStructure($data)
        ]);

        // 1. هش‌های مستقیم در سطح اول
        $this->extractDirectHashes($data, $extracted);

        // 2. هش‌ها در ساختار تودرتو
        $this->extractNestedHashes($data, $extracted);

        // 3. هش‌ها در آرایه‌های پیچیده
        $this->extractArrayHashes($data, $extracted);

        // 4. لینک‌های مگنت و استخراج BTIH
        $this->extractMagnetLinksAndBtih($data, $extracted);

        // 5. بازسازی لینک مگنت از BTIH
        $this->reconstructMagnetFromBtih($extracted);

        // 6. اعتبارسنجی نهایی هش‌ها
        $this->validateAndCleanHashes($extracted);

        $hashesFound = $this->getHashTypes($extracted);
        Log::info("🔐 استخراج هش‌ها تمام شد", [
            'hashes_found' => $hashesFound,
            'hash_count' => count($hashesFound)
        ]);
    }

    /**
     * تحلیل ساختار داده برای بهبود استخراج
     */
    private function analyzeDataStructure(array $data): array
    {
        $structure = [
            'depth' => $this->calculateArrayDepth($data),
            'has_nested_arrays' => false,
            'potential_hash_locations' => [],
            'potential_book_locations' => []
        ];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $structure['has_nested_arrays'] = true;

                // احتمال وجود هش در این مکان
                if (in_array(strtolower($key), ['hashes', 'hash', 'checksums', 'file_hashes', 'download_info'])) {
                    $structure['potential_hash_locations'][] = $key;
                }

                // احتمال وجود اطلاعات کتاب
                if (in_array(strtolower($key), ['book', 'data', 'item', 'result', 'content'])) {
                    $structure['potential_book_locations'][] = $key;
                }
            }
        }

        return $structure;
    }

    /**
     * محاسبه عمق آرایه
     */
    private function calculateArrayDepth(array $array, int $currentDepth = 0): int
    {
        $maxDepth = $currentDepth;

        foreach ($array as $value) {
            if (is_array($value)) {
                $depth = $this->calculateArrayDepth($value, $currentDepth + 1);
                $maxDepth = max($maxDepth, $depth);
            }
        }

        return $maxDepth;
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
                        'value_preview' => $this->getHashPreview($hashValue)
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
            // مسیرهای استاندارد
            'hashes.md5' => 'md5',
            'hashes.sha1' => 'sha1',
            'hashes.sha256' => 'sha256',
            'hashes.crc32' => 'crc32',
            'hashes.ed2k' => 'ed2k',
            'hashes.btih' => 'btih',

            // مسیرهای متنوع
            'hash_data.md5' => 'md5',
            'hash_data.sha1' => 'sha1',
            'checksums.md5' => 'md5',
            'checksums.sha1' => 'sha1',
            'file_hashes.md5' => 'md5',
            'file_hashes.sha1' => 'sha1',
            'file_hashes.sha256' => 'sha256',

            // مسیرهای دانلود
            'download_info.md5' => 'md5',
            'download_info.sha1' => 'sha1',
            'download_info.magnet' => 'magnet',
            'download_links.magnet' => 'magnet',

            // مسیرهای تورنت
            'torrent.btih' => 'btih',
            'torrent.magnet' => 'magnet',
            'torrent.info_hash' => 'btih',

            // سایر مسیرها
            'links.magnet' => 'magnet',
            'metadata.hashes.md5' => 'md5',
            'metadata.hashes.sha1' => 'sha1'
        ];

        foreach ($nestedPaths as $path => $targetKey) {
            if (!isset($extracted[$targetKey])) { // فقط اگر قبلاً یافت نشده
                $value = $this->getNestedValue($data, $path);
                if ($value && $this->isValidHashFormat($value, $targetKey)) {
                    $extracted[$targetKey] = trim($value);
                    Log::debug("✅ هش تودرتو یافت شد", [
                        'type' => $targetKey,
                        'path' => $path,
                        'value_preview' => $this->getHashPreview($value)
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
        $arrayKeys = ['files', 'downloads', 'mirrors', 'items', 'results', 'entries'];

        foreach ($arrayKeys as $arrayKey) {
            if (isset($data[$arrayKey]) && is_array($data[$arrayKey])) {
                foreach ($data[$arrayKey] as $item) {
                    if (is_array($item)) {
                        // استخراج از هر آیتم
                        $this->extractDirectHashes($item, $extracted);
                        $this->extractNestedHashes($item, $extracted);

                        // اگر تمام هش‌های اصلی یافت شدند، دیگر ادامه نده
                        if ($this->hasAllMainHashes($extracted)) {
                            break 2;
                        }
                    }
                }
            }
        }
    }

    /**
     * استخراج لینک‌های مگنت و BTIH
     */
    private function extractMagnetLinksAndBtih(array $data, array &$extracted): void
    {
        if (isset($extracted['magnet']) && isset($extracted['btih'])) {
            return; // هر دو یافت شده‌اند
        }

        $magnetPaths = [
            'magnet', 'magnet_link', 'magnet_uri',
            'torrent.magnet', 'download_links.magnet', 'links.torrent',
            'mirrors.magnet', 'download_info.magnet_link'
        ];

        foreach ($magnetPaths as $path) {
            $value = $this->getNestedValue($data, $path);
            if ($value && $this->isValidMagnetLink($value)) {
                if (!isset($extracted['magnet'])) {
                    $extracted['magnet'] = trim($value);
                    Log::debug("✅ لینک مگنت یافت شد", [
                        'path' => $path,
                        'value_preview' => $this->getHashPreview($value, 50)
                    ]);
                }

                // استخراج BTIH از لینک مگنت
                if (!isset($extracted['btih'])) {
                    $btih = $this->extractBtihFromMagnet($value);
                    if ($btih) {
                        $extracted['btih'] = $btih;
                        Log::debug("✅ BTIH از مگنت استخراج شد", ['btih' => $btih]);
                    }
                }
                break;
            }
        }
    }

    /**
     * بازسازی لینک مگنت از BTIH
     */
    private function reconstructMagnetFromBtih(array &$extracted): void
    {
        if (!isset($extracted['magnet']) && isset($extracted['btih'])) {
            $title = $extracted['title'] ?? 'Unknown';
            $magnetLink = "magnet:?xt=urn:btih:{$extracted['btih']}&dn=" . urlencode($title);
            $extracted['magnet'] = $magnetLink;

            Log::debug("🔗 لینک مگنت از BTIH بازسازی شد", [
                'btih' => $extracted['btih'],
                'title' => $title
            ]);
        }
    }

    /**
     * اعتبارسنجی و تمیز کردن هش‌ها
     */
    private function validateAndCleanHashes(array &$extracted): void
    {
        $hashFields = ['md5', 'sha1', 'sha256', 'crc32', 'ed2k', 'btih', 'magnet'];
        $cleanedCount = 0;

        foreach ($hashFields as $field) {
            if (isset($extracted[$field])) {
                $originalValue = $extracted[$field];
                $cleanedValue = $this->cleanHashValue($originalValue, $field);

                if ($cleanedValue === null) {
                    unset($extracted[$field]);
                    Log::warning("❌ هش نامعتبر حذف شد", [
                        'type' => $field,
                        'original_value' => $this->getHashPreview($originalValue)
                    ]);
                } elseif ($cleanedValue !== $originalValue) {
                    $extracted[$field] = $cleanedValue;
                    $cleanedCount++;
                    Log::debug("🧹 هش تمیز شد", [
                        'type' => $field,
                        'original' => $this->getHashPreview($originalValue),
                        'cleaned' => $this->getHashPreview($cleanedValue)
                    ]);
                }
            }
        }

        if ($cleanedCount > 0) {
            Log::info("🧹 هش‌ها تمیز شدند", ['cleaned_count' => $cleanedCount]);
        }
    }

    /**
     * اعتبارسنجی و بهبود داده‌های استخراج شده
     */
    private function validateAndEnhanceExtractedData(array &$extracted): void
    {
        // تمیز کردن عنوان
        if (isset($extracted['title'])) {
            $extracted['title'] = $this->enhanceTitle($extracted['title']);
        }

        // بهبود توضیحات
        if (isset($extracted['description'])) {
            $extracted['description'] = $this->enhanceDescription($extracted['description']);
        }

        // استاندارد کردن نام نویسندگان
        if (isset($extracted['author'])) {
            $extracted['author'] = $this->enhanceAuthors($extracted['author']);
        }

        // اعتبارسنجی سال انتشار
        if (isset($extracted['publication_year'])) {
            $extracted['publication_year'] = $this->validatePublicationYear($extracted['publication_year']);
        }

        // اعتبارسنجی تعداد صفحات
        if (isset($extracted['pages_count'])) {
            $extracted['pages_count'] = $this->validatePagesCount($extracted['pages_count']);
        }
    }

    /**
     * بهبود عنوان کتاب
     */
    private function enhanceTitle(string $title): string
    {
        $title = trim($title);

        // حذف کاراکترهای اضافی
        $title = preg_replace('/\s+/', ' ', $title);
        $title = preg_replace('/[^\p{L}\p{N}\s\-\.\(\)\[\]:]/u', '', $title);

        // حذف عبارات اضافی
        $removePatterns = [
            '/\[.*?(pdf|epub|mobi|djvu)\]$/i',
            '/\(.*?(edition|ed\.|چاپ)\)$/i',
            '/\s*-\s*(free\s+)?download$/i'
        ];

        foreach ($removePatterns as $pattern) {
            $title = preg_replace($pattern, '', $title);
        }

        return trim($title);
    }

    /**
     * بهبود توضیحات
     */
    private function enhanceDescription(string $description): string
    {
        $description = trim($description);

        // حذف تگ‌های HTML
        $description = strip_tags($description);

        // تبدیل entities
        $description = html_entity_decode($description, ENT_QUOTES, 'UTF-8');

        // بهبود فاصله‌ها
        $description = preg_replace('/\s+/', ' ', $description);
        $description = preg_replace('/\n\s*\n/', "\n\n", $description);

        return trim($description);
    }

    /**
     * بهبود نام نویسندگان
     */
    private function enhanceAuthors($authors): string
    {
        if (is_array($authors)) {
            return $this->extractAuthorsFromArray($authors);
        }

        if (is_string($authors)) {
            return $this->cleanAuthorsString($authors);
        }

        return '';
    }

    /**
     * استخراج نویسندگان از آرایه
     */
    private function extractAuthorsFromArray(array $authors): string
    {
        $names = [];

        foreach ($authors as $author) {
            if (is_array($author)) {
                // بررسی کلیدهای مختلف برای نام نویسنده
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

                // اگر firstname و lastname جداگانه بودند
                if (empty($authorName) && (isset($author['firstname']) || isset($author['lastname']))) {
                    $authorName = trim(($author['firstname'] ?? '') . ' ' . ($author['lastname'] ?? ''));
                }

                if (!empty($authorName)) {
                    $names[] = trim($authorName);
                }

            } elseif (is_string($author)) {
                $cleanAuthor = trim($author);
                if (!empty($cleanAuthor)) {
                    $names[] = $cleanAuthor;
                }
            }
        }

        return !empty($names) ? implode(', ', array_unique($names)) : '';
    }

    /**
     * تمیز کردن رشته نویسندگان
     */
    private function cleanAuthorsString(string $authors): string
    {
        $authors = trim($authors);

        // حذف کاراکترهای اضافی
        $authors = preg_replace('/[^\p{L}\p{N}\s\-\.,،؛;&]/u', '', $authors);

        // استاندارد کردن جداکننده‌ها
        $authors = preg_replace('/[،؛;&]/', ',', $authors);
        $authors = preg_replace('/\s+and\s+/i', ', ', $authors);
        $authors = preg_replace('/\s+و\s+/', ', ', $authors);

        // تمیز کردن فاصله‌ها
        $authors = preg_replace('/\s+/', ' ', $authors);
        $authors = preg_replace('/,\s*,+/', ',', $authors);

        return trim($authors, ', ');
    }

    /**
     * سایر متدهای کمکی که قبلاً وجود داشتند...
     */
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
            'author' => $this->enhanceAuthors($value),
            'publisher' => is_array($value) ? ($value['name'] ?? '') : trim((string)$value),
            'publication_year' => $this->validatePublicationYear($value),
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

    // متدهای کمکی
    private function isValidHashFormat(string $hash, string $type): bool
    {
        $hash = trim($hash);
        if (empty($hash)) return false;

        switch ($type) {
            case 'md5': return preg_match('/^[a-f0-9]{32}$/i', $hash);
            case 'sha1': return preg_match('/^[a-f0-9]{40}$/i', $hash);
            case 'sha256': return preg_match('/^[a-f0-9]{64}$/i', $hash);
            case 'crc32': return preg_match('/^[a-f0-9]{8}$/i', $hash);
            case 'ed2k': return preg_match('/^[a-f0-9]{32}$/i', $hash);
            case 'btih': return preg_match('/^[a-f0-9]{40}$/i', $hash);
            case 'magnet': return $this->isValidMagnetLink($hash);
            default: return !empty($hash);
        }
    }

    private function isValidMagnetLink(string $link): bool
    {
        return str_starts_with(strtolower(trim($link)), 'magnet:?xt=');
    }

    private function extractBtihFromMagnet(string $magnetLink): ?string
    {
        if (preg_match('/xt=urn:btih:([a-f0-9]{40})/i', $magnetLink, $matches)) {
            return strtolower($matches[1]);
        }
        return null;
    }

    private function cleanHashValue(string $hash, string $type): ?string
    {
        $hash = trim($hash);
        $hash = strtolower($hash);

        // حذف کاراکترهای اضافی
        if ($type !== 'magnet') {
            $hash = preg_replace('/[^a-f0-9]/', '', $hash);
        }

        return $this->isValidHashFormat($hash, $type) ? $hash : null;
    }

    private function getHashPreview(string $hash, int $length = 16): string
    {
        return strlen($hash) > $length ? substr($hash, 0, $length) . '...' : $hash;
    }

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

    private function hasAllMainHashes(array $extracted): bool
    {
        $mainHashes = ['md5', 'sha1', 'btih'];
        foreach ($mainHashes as $hash) {
            if (empty($extracted[$hash])) {
                return false;
            }
        }
        return true;
    }

    private function validatePublicationYear($value): ?int
    {
        if (!is_numeric($value)) return null;
        $year = (int)$value;
        $currentYear = (int)date('Y');
        return ($year >= 1000 && $year <= $currentYear + 5) ? $year : null;
    }

    private function validatePagesCount($value): ?int
    {
        if (!is_numeric($value) || $value <= 0) return null;
        $pages = (int)$value;
        return ($pages >= 1 && $pages <= 50000) ? $pages : null;
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
