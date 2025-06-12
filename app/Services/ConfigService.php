<?php

namespace App\Services;

use App\Models\Config;
use App\Models\ExecutionLog;
use App\Models\FailedRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ConfigService
{
    public function create(array $data): Config
    {
        $configData = $this->buildConfigData($data);
        $sourceName = $this->extractSourceName($data['base_url']);
        $startPage = $this->processStartPage($data['start_page'] ?? null);
        $sourceType = $data['source_type'] ?? 'api';

        $configArray = [
            ...$data,
            'source_type' => $sourceType,
            'source_name' => $sourceName,
            'config_data' => $configData,
            'created_by' => Auth::id(),
            'start_page' => $startPage,
            'current_page' => $startPage ?? 1,
            'total_processed' => 0,
            'total_success' => 0,
            'total_failed' => 0,
            'is_running' => false,
            'is_active' => true
        ];

        // اضافه کردن فیلدهای مخصوص crawler
        if ($sourceType === 'crawler') {
            $configArray['page_pattern'] = $data['page_pattern'] ?? '/book/{id}';
            $configArray['user_agent'] = $data['user_agent'] ?? null;
        }

        Log::info('🆕 ایجاد کانفیگ جدید', [
            'name' => $data['name'],
            'source_type' => $sourceType,
            'source_name' => $sourceName,
            'start_page' => $startPage
        ]);

        return Config::create($configArray);
    }

    public function update(Config $config, array $data): Config
    {
        if ($config->is_running) {
            throw new \Exception('امکان ویرایش کانفیگ در حال اجرا وجود ندارد.');
        }

        $configData = $this->buildConfigData($data);
        $sourceName = $this->extractSourceName($data['base_url']);
        $startPage = $this->processStartPage($data['start_page'] ?? null);
        $sourceType = $data['source_type'] ?? $config->source_type ?? 'api';

        $updateArray = [
            ...$data,
            'source_type' => $sourceType,
            'source_name' => $sourceName,
            'config_data' => $configData,
            'start_page' => $startPage
        ];

        // بروزرسانی فیلدهای مخصوص crawler
        if ($sourceType === 'crawler') {
            $updateArray['page_pattern'] = $data['page_pattern'] ?? '/book/{id}';
            $updateArray['user_agent'] = $data['user_agent'] ?? null;
            // پاک کردن فیلدهای API اگر تبدیل به crawler شده
            $updateArray['api_endpoint'] = null;
            $updateArray['api_method'] = null;
        } else {
            // پاک کردن فیلدهای crawler اگر تبدیل به API شده
            $updateArray['page_pattern'] = null;
            $updateArray['user_agent'] = null;
        }

        $config->update($updateArray);

        Log::info("🔧 کانفیگ ویرایش شد", [
            'config_id' => $config->id,
            'source_type' => $sourceType,
            'source_name' => $sourceName,
            'start_page' => $startPage
        ]);

        return $config;
    }

    public function delete(Config $config): void
    {
        if ($config->is_running) {
            throw new \Exception('امکان حذف کانفیگ در حال اجرا وجود ندارد.');
        }

        Log::info("🗑️ حذف کانفیگ", [
            'config_id' => $config->id,
            'name' => $config->name,
            'source_type' => $config->source_type
        ]);

        ExecutionLog::where('config_id', $config->id)->delete();
        FailedRequest::where('config_id', $config->id)->delete();
        $config->delete();
    }

    private function processStartPage($startPageValue): ?int
    {
        if ($startPageValue === null || $startPageValue === '' || $startPageValue === false) {
            return null;
        }

        $intValue = (int) $startPageValue;
        return $intValue > 0 ? $intValue : null;
    }

    private function extractSourceName(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $sourceName = preg_replace('/^www\./', '', $host ?? '');
        return str_replace('.', '_', $sourceName) ?: 'unknown_source';
    }

    private function buildConfigData(array $data): array
    {
        $sourceType = $data['source_type'] ?? 'api';

        $configData = [
            'general' => [
                'verify_ssl' => $data['verify_ssl'] ?? true,
                'follow_redirects' => $data['follow_redirects'] ?? true,
            ]
        ];

        if ($sourceType === 'api') {
            $configData['api'] = [
                'endpoint' => $data['api_endpoint'] ?? '',
                'method' => $data['api_method'] ?? 'GET',
                'params' => $this->buildApiParams($data),
                'field_mapping' => $this->buildFieldMapping($data, 'api')
            ];
        } else {
            $configData['crawler'] = [
                'selector_mapping' => $this->buildFieldMapping($data, 'css'),
                'image_selectors' => $this->buildImageSelectors($data),
                'download_selectors' => $this->buildDownloadSelectors($data)
            ];
        }

        return $configData;
    }

    private function buildApiParams(array $data): array
    {
        $params = [];
        for ($i = 1; $i <= 5; $i++) {
            $key = $data["param_key_{$i}"] ?? null;
            $value = $data["param_value_{$i}"] ?? null;
            if (!empty($key) && !empty($value)) {
                $params[$key] = $value;
            }
        }
        return $params;
    }

    private function buildFieldMapping(array $data, string $type): array
    {
        $mapping = [];
        $prefix = $type === 'api' ? 'api_field_' : 'css_';

        // فیلدهای مشترک
        $fields = $type === 'api' ? array_keys(Config::getBookFields()) : array_keys(Config::getCrawlerFields());

        foreach ($fields as $field) {
            $value = $data["{$prefix}{$field}"] ?? null;
            if (!empty($value)) {
                $mapping[$field] = $value;
            }
        }
        return $mapping;
    }

    private function buildImageSelectors(array $data): array
    {
        $selectors = [];

        // سلکتورهای پیش‌فرض برای تصاویر
        $defaultImageSelectors = [
            'img.cover', 'img.book-cover', '.book-image img',
            '.cover img', 'img[alt*="cover"]', 'img[src*="cover"]'
        ];

        // اگر سلکتور تصویر در نقشه‌برداری موجود است
        if (!empty($data['css_image_url'])) {
            array_unshift($defaultImageSelectors, $data['css_image_url']);
        }

        return array_unique($defaultImageSelectors);
    }

    private function buildDownloadSelectors(array $data): array
    {
        return [
            'direct' => 'a[href*="download"]',
            'torrent' => 'a[href$=".torrent"]',
            'magnet' => 'a[href^="magnet:"]'
        ];
    }
}
