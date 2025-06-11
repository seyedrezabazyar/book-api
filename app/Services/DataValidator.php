<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DataValidator
{
    /**
     * تمیز کردن و اعتبارسنجی داده‌ها با منطق بهبود یافته
     */
    public function cleanAndValidate(array $data): array
    {
        $cleaned = [];
        $validationResults = [];

        Log::debug("🧹 شروع تمیز کردن و اعتبارسنجی داده‌ها", [
            'input_fields' => array_keys($data),
            'input_count' => count($data)
        ]);

        // تمیز کردن فیلدهای اصلی
        $cleaned['title'] = $this->cleanAndValidateTitle($data['title'] ?? '');
        $cleaned['description'] = $this->cleanAndValidateDescription($data['description'] ?? '');
        $cleaned['author'] = $this->cleanAndValidateAuthor($data['author'] ?? '');
        $cleaned['publisher'] = $this->cleanAndValidatePublisher($data['publisher'] ?? '');
        $cleaned['category'] = $this->cleanAndValidateCategory($data['category'] ?? '');

        // تمیز کردن فیلدهای عددی
        $cleaned['publication_year'] = $this->cleanAndValidatePublicationYear($data['publication_year'] ?? null);
        $cleaned['pages_count'] = $this->cleanAndValidatePagesCount($data['pages_count'] ?? null);
        $cleaned['file_size'] = $this->cleanAndValidateFileSize($data['file_size'] ?? null);

        // تمیز کردن فیلدهای خاص
        $cleaned['isbn'] = $this->cleanAndValidateIsbn($data['isbn'] ?? '');
        $cleaned['language'] = $this->cleanAndValidateLanguage($data['language'] ?? '');
        $cleaned['format'] = $this->cleanAndValidateFormat($data['format'] ?? '');
        $cleaned['image_url'] = $this->cleanAndValidateImageUrl($data['image_url'] ?? '');

        // تمیز کردن هش‌ها
        $cleaned = array_merge($cleaned, $this->cleanAndValidateHashes($data));

        // حذف فیلدهای خالی
        $cleaned = array_filter($cleaned, function($value) {
            return $value !== null && $value !== '';
        });

        // اعتبارسنجی نهایی
        $this->performFinalValidation($cleaned, $validationResults);

        Log::info("✅ تمیز کردن و اعتبارسنجی تمام شد", [
            'output_fields' => array_keys($cleaned),
            'output_count' => count($cleaned),
            'validation_issues' => count($validationResults),
            'has_required_fields' => $this->hasRequiredFields($cleaned)
        ]);

        return $cleaned;
    }

    /**
     * تمیز کردن و اعتبارسنجی عنوان
     */
    private function cleanAndValidateTitle(string $title): string
    {
        $original = $title;
        $title = trim($title);

        if (empty($title)) {
            return '';
        }

        // حذف تگ‌های HTML
        $title = strip_tags($title);

        // تبدیل entities
        $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');

        // بهبود فاصله‌ها
        $title = preg_replace('/\s+/', ' ', $title);

        // حذف کاراکترهای مشکوک
        $title = preg_replace('/[^\p{L}\p{N}\s\-\.\(\)\[\]:]/u', '', $title);

        // حذف عبارات اضافی مثل فرمت فایل
        $title = preg_replace('/\s*[\[\(]*(pdf|epub|mobi|djvu|free|download)[\]\)]*\s*$/i', '', $title);

        // محدود کردن طول
        if (strlen($title) > 500) {
            $title = Str::limit($title, 500, '');
        }

        $title = trim($title);

        if (strlen($title) < 2) {
            Log::warning("عنوان بعد از تمیز کردن خیلی کوتاه شد", [
                'original' => $original,
                'cleaned' => $title
            ]);
            return '';
        }

        return $title;
    }

    /**
     * تمیز کردن و اعتبارسنجی توضیحات
     */
    private function cleanAndValidateDescription(string $description): string
    {
        $description = trim($description);

        if (empty($description)) {
            return '';
        }

        // حذف تگ‌های HTML
        $description = strip_tags($description);

        // تبدیل entities
        $description = html_entity_decode($description, ENT_QUOTES, 'UTF-8');

        // بهبود فاصله‌ها و خطوط جدید
        $description = preg_replace('/\s+/', ' ', $description);
        $description = preg_replace('/\n\s*\n/', "\n\n", $description);

        // حذف عبارات تبلیغاتی رایج
        $spamPatterns = [
            '/download\s+free/i',
            '/click\s+here/i',
            '/visit\s+our\s+website/i',
            '/www\.[^\s]+/i'
        ];

        foreach ($spamPatterns as $pattern) {
            $description = preg_replace($pattern, '', $description);
        }

        // محدود کردن طول
        if (strlen($description) > 5000) {
            $description = Str::limit($description, 5000, '...');
        }

        return trim($description);
    }

    /**
     * تمیز کردن و اعتبارسنجی نویسنده
     */
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

        // حذف کاراکترهای غیرضروری
        $author = preg_replace('/[^\p{L}\p{N}\s\-\.,،؛;&]/u', '', $author);

        // استاندارد کردن جداکننده‌ها
        $author = preg_replace('/[،؛;&]/', ',', $author);
        $author = preg_replace('/\s+and\s+/i', ', ', $author);
        $author = preg_replace('/\s+و\s+/', ', ', $author);

        // بهبود فاصله‌ها
        $author = preg_replace('/\s+/', ' ', $author);
        $author = preg_replace('/,\s*,+/', ',', $author);

        // حذف نام‌های خیلی کوتاه یا مشکوک
        $authors = array_filter(explode(',', $author), function($name) {
            $name = trim($name);
            return strlen($name) >= 2 && !preg_match('/^[0-9\-\s]+$/', $name);
        });

        $result = implode(', ', array_map('trim', $authors));
        return trim($result, ', ');
    }

    /**
     * استخراج نویسندگان از آرایه
     */
    private function extractAndCleanAuthorsFromArray(array $authors): string
    {
        $names = [];

        foreach ($authors as $author) {
            if (is_array($author)) {
                // کلیدهای مختلف برای نام
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

    /**
     * تمیز کردن ناشر
     */
    private function cleanAndValidatePublisher(string $publisher): string
    {
        $publisher = trim($publisher);
        if (empty($publisher)) {
            return '';
        }

        // حذف کاراکترهای اضافی
        $publisher = preg_replace('/[^\p{L}\p{N}\s\-\.\&]/u', '', $publisher);
        $publisher = preg_replace('/\s+/', ' ', $publisher);

        // محدود کردن طول
        if (strlen($publisher) > 200) {
            $publisher = Str::limit($publisher, 200, '');
        }

        return trim($publisher);
    }

    /**
     * تمیز کردن دسته‌بندی
     */
    private function cleanAndValidateCategory(string $category): string
    {
        $category = trim($category);
        if (empty($category)) {
            return '';
        }

        // حذف کاراکترهای اضافی
        $category = preg_replace('/[^\p{L}\p{N}\s\-]/u', '', $category);
        $category = preg_replace('/\s+/', ' ', $category);

        // محدود کردن طول
        if (strlen($category) > 100) {
            $category = Str::limit($category, 100, '');
        }

        return trim($category);
    }

    /**
     * اعتبارسنجی سال انتشار
     */
    private function cleanAndValidatePublicationYear($year): ?int
    {
        if ($year === null || $year === '') {
            return null;
        }

        // تبدیل به عدد
        if (is_string($year)) {
            $year = preg_replace('/[^0-9]/', '', $year);
        }

        if (!is_numeric($year)) {
            return null;
        }

        $year = (int)$year;
        $currentYear = (int)date('Y');

        // بررسی محدوده معقول
        if ($year < 1000 || $year > $currentYear + 5) {
            Log::debug("سال انتشار نامعتبر", [
                'year' => $year,
                'current_year' => $currentYear
            ]);
            return null;
        }

        return $year;
    }

    /**
     * اعتبارسنجی تعداد صفحات
     */
    private function cleanAndValidatePagesCount($pages): ?int
    {
        if ($pages === null || $pages === '') {
            return null;
        }

        // تبدیل به عدد
        if (is_string($pages)) {
            $pages = preg_replace('/[^0-9]/', '', $pages);
        }

        if (!is_numeric($pages)) {
            return null;
        }

        $pages = (int)$pages;

        // بررسی محدوده معقول
        if ($pages < 1 || $pages > 50000) {
            Log::debug("تعداد صفحات نامعتبر", ['pages' => $pages]);
            return null;
        }

        return $pages;
    }

    /**
     * اعتبارسنجی اندازه فایل
     */
    private function cleanAndValidateFileSize($size): ?int
    {
        if ($size === null || $size === '') {
            return null;
        }

        // اگر رشته است، سعی کن واحد را تشخیص بدهی
        if (is_string($size)) {
            $originalSize = $size;
            $size = strtolower(trim($size));

            // استخراج عدد
            preg_match('/([0-9]+(?:\.[0-9]+)?)/', $size, $matches);
            if (!isset($matches[1])) {
                return null;
            }

            $number = (float)$matches[1];

            // تشخیص واحد
            if (strpos($size, 'gb') !== false || strpos($size, 'گیگ') !== false) {
                $size = $number * 1024 * 1024 * 1024;
            } elseif (strpos($size, 'mb') !== false || strpos($size, 'مگ') !== false) {
                $size = $number * 1024 * 1024;
            } elseif (strpos($size, 'kb') !== false || strpos($size, 'کیلو') !== false) {
                $size = $number * 1024;
            } else {
                $size = $number; // فرض بر بایت
            }
        }

        if (!is_numeric($size)) {
            return null;
        }

        $size = (int)$size;

        // بررسی محدوده معقول (حداقل 1KB، حداکثر 10GB)
        if ($size < 1024 || $size > 10 * 1024 * 1024 * 1024) {
            Log::debug("اندازه فایل نامعتبر", ['size' => $size]);
            return null;
        }

        return $size;
    }

    /**
     * تمیز کردن ISBN
     */
    private function cleanAndValidateIsbn(string $isbn): string
    {
        $isbn = trim($isbn);
        if (empty($isbn)) {
            return '';
        }

        // نگه داشتن فقط اعداد، X و خط تیره
        $isbn = preg_replace('/[^0-9X\-]/i', '', $isbn);

        // بررسی طول
        $cleanIsbn = preg_replace('/[^0-9X]/i', '', $isbn);
        if (strlen($cleanIsbn) !== 10 && strlen($cleanIsbn) !== 13) {
            Log::debug("طول ISBN نامعتبر", [
                'original' => $isbn,
                'clean_length' => strlen($cleanIsbn)
            ]);
            return '';
        }

        return $isbn;
    }

    /**
     * استاندارد کردن زبان
     */
    private function cleanAndValidateLanguage(string $language): string
    {
        $language = strtolower(trim($language));
        if (empty($language)) {
            return 'fa'; // پیش‌فرض فارسی
        }

        // نقشه زبان‌ها
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

        // اگر کد 2 حرفی است
        if (strlen($language) === 2 && preg_match('/^[a-z]{2}$/', $language)) {
            return $language;
        }

        return 'fa'; // پیش‌فرض
    }

    /**
     * استاندارد کردن فرمت
     */
    private function cleanAndValidateFormat(string $format): string
    {
        $format = strtolower(trim($format));
        if (empty($format)) {
            return 'pdf'; // پیش‌فرض
        }

        $allowedFormats = ['pdf', 'epub', 'mobi', 'djvu', 'audio', 'txt'];

        // نقشه فرمت‌ها
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

        return 'pdf'; // پیش‌فرض
    }

    /**
     * اعتبارسنجی URL تصویر
     */
    private function cleanAndValidateImageUrl(string $url): string
    {
        $url = trim($url);
        if (empty($url)) {
            return '';
        }

        // اعتبارسنجی URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            Log::debug("URL تصویر نامعتبر", ['url' => $url]);
            return '';
        }

        // بررسی پسوند
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

    /**
     * تمیز کردن هش‌ها
     */
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

        // تمیز کردن لینک مگنت
        if (isset($data['magnet'])) {
            $magnet = $this->cleanMagnetLink($data['magnet']);
            if ($magnet) {
                $hashes['magnet'] = $magnet;
            }
        }

        return $hashes;
    }

    /**
     * تمیز کردن هش
     */
    private function cleanHash(string $hash, array $config): ?string
    {
        $hash = strtolower(trim($hash));

        // حذف کاراکترهای غیرهش
        $hash = preg_replace('/[^a-f0-9]/', '', $hash);

        // بررسی طول
        if (strlen($hash) !== $config['length']) {
            return null;
        }

        // بررسی الگو
        if (!preg_match($config['pattern'], $hash)) {
            return null;
        }

        return $hash;
    }

    /**
     * تمیز کردن لینک مگنت
     */
    private function cleanMagnetLink(string $magnet): ?string
    {
        $magnet = trim($magnet);

        if (!str_starts_with(strtolower($magnet), 'magnet:?xt=')) {
            return null;
        }

        // بررسی وجود BTIH
        if (!preg_match('/xt=urn:btih:([a-f0-9]{40})/i', $magnet)) {
            return null;
        }

        return $magnet;
    }

    /**
     * اعتبارسنجی نهایی
     */
    private function performFinalValidation(array $data, array &$validationResults): void
    {
        // بررسی فیلدهای ضروری
        if (empty($data['title'])) {
            $validationResults[] = 'عنوان خالی است';
        }

        // بررسی سازگاری سال و تعداد صفحات
        if (isset($data['publication_year']) && isset($data['pages_count'])) {
            $currentYear = (int)date('Y');
            if ($data['publication_year'] > $currentYear && $data['pages_count'] > 1000) {
                $validationResults[] = 'سال آینده با تعداد صفحات زیاد مشکوک است';
            }
        }

        // بررسی سازگاری فرمت و اندازه فایل
        if (isset($data['format']) && isset($data['file_size'])) {
            $expectedSizes = [
                'pdf' => [1024 * 100, 1024 * 1024 * 100], // 100KB - 100MB
                'epub' => [1024 * 50, 1024 * 1024 * 50],   // 50KB - 50MB
                'audio' => [1024 * 1024, 1024 * 1024 * 1024] // 1MB - 1GB
            ];

            if (isset($expectedSizes[$data['format']])) {
                [$min, $max] = $expectedSizes[$data['format']];
                if ($data['file_size'] < $min || $data['file_size'] > $max) {
                    $validationResults[] = "اندازه فایل برای فرمت {$data['format']} غیرعادی است";
                }
            }
        }
    }

    /**
     * بررسی وجود فیلدهای ضروری
     */
    private function hasRequiredFields(array $data): bool
    {
        return !empty($data['title']);
    }
}
