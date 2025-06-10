<?php

namespace App\Services;

class DataValidator
{
    public function cleanAndValidate(array $data): array
    {
        $cleaned = [];

        // تمیز کردن عنوان
        if (isset($data['title'])) {
            $cleaned['title'] = $this->cleanTitle($data['title']);
        }

        // بهبود توضیحات
        if (isset($data['description'])) {
            $cleaned['description'] = $this->enhanceDescription($data['description']);
        }

        // اعتبارسنجی سال انتشار
        if (isset($data['publication_year'])) {
            $cleaned['publication_year'] = $this->validatePublicationYear($data['publication_year']);
        }

        // اعتبارسنجی تعداد صفحات
        if (isset($data['pages_count'])) {
            $cleaned['pages_count'] = $this->validatePagesCount($data['pages_count']);
        }

        // تمیز کردن ISBN
        if (isset($data['isbn'])) {
            $cleaned['isbn'] = $this->cleanIsbn($data['isbn']);
        }

        // کپی بقیه فیلدها
        foreach ($data as $key => $value) {
            if (!isset($cleaned[$key]) && $value !== null) {
                $cleaned[$key] = $value;
            }
        }

        return $cleaned;
    }

    private function cleanTitle(string $title): string
    {
        $title = trim($title);
        $title = preg_replace('/\s+/', ' ', $title);
        $title = preg_replace('/[^\p{L}\p{N}\s\-\.\(\)\[\]]/u', '', $title);

        return $title;
    }

    private function enhanceDescription(string $description): string
    {
        $description = trim($description);
        $description = strip_tags($description);
        $description = preg_replace('/\s+/', ' ', $description);
        $description = preg_replace('/\n\s*\n/', "\n\n", $description);

        return $description;
    }

    private function validatePublicationYear($year): ?int
    {
        if (!is_numeric($year)) return null;

        $year = (int)$year;
        $currentYear = (int)date('Y');

        return ($year >= 1000 && $year <= $currentYear + 2) ? $year : null;
    }

    private function validatePagesCount($pages): ?int
    {
        if (!is_numeric($pages)) return null;

        $pages = (int)$pages;
        return ($pages >= 1 && $pages <= 50000) ? $pages : null;
    }

    private function cleanIsbn(string $isbn): string
    {
        $isbn = preg_replace('/[^0-9X-]/i', '', $isbn);
        $cleanIsbn = preg_replace('/[^0-9X]/i', '', $isbn);

        if (strlen($cleanIsbn) === 10 || strlen($cleanIsbn) === 13) {
            return $isbn;
        }

        return '';
    }
}
