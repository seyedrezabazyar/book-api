<?php

namespace App\Services;

use App\Models\Config;
use App\Models\ExecutionLog;
use App\Models\ScrapingFailure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ConfigService
{
    public function create(array $data): Config
    {
        $configData = $this->buildConfigData($data);
        $sourceName = $this->extractSourceName($data['base_url']);
        $startPage = $this->processStartPage($data['start_page'] ?? null);

        return Config::create([
            ...$data,
            'source_type' => 'api',
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
        ]);
    }

    public function update(Config $config, array $data): Config
    {
        if ($config->is_running) {
            throw new \Exception('امکان ویرایش کانفیگ در حال اجرا وجود ندارد.');
        }

        $configData = $this->buildConfigData($data);
        $sourceName = $this->extractSourceName($data['base_url']);
        $startPage = $this->processStartPage($data['start_page'] ?? null);
        $oldStartPage = $config->start_page;

        $config->update([
            ...$data,
            'source_name' => $sourceName,
            'config_data' => $configData,
            'start_page' => $startPage
        ]);

        Log::info("🔧 کانفیگ ویرایش شد", [
            'config_id' => $config->id,
            'old_start_page' => $oldStartPage,
            'new_start_page' => $startPage,
            'source_name' => $sourceName,
            'smart_start_page' => $config->getSmartStartPage(),
            'has_user_defined_start' => $config->hasUserDefinedStartPage()
        ]);

        return $config;
    }

    public function delete(Config $config): void
    {
        if ($config->is_running) {
            throw new \Exception('امکان حذف کانفیگ در حال اجرا وجود ندارد.');
        }

        ExecutionLog::where('config_id', $config->id)->delete();
        ScrapingFailure::where('config_id', $config->id)->delete();
        $config->delete();
    }

    private function processStartPage($startPageValue): ?int
    {
        if ($startPageValue === null || $startPageValue === '' || $startPageValue === false) {
            Log::debug("📝 start_page خالی - حالت هوشمند فعال خواهد شد");
            return null;
        }

        $intValue = (int) $startPageValue;

        if ($intValue > 0) {
            Log::info("🎯 start_page تنظیم شد", [
                'original_value' => $startPageValue,
                'processed_value' => $intValue,
                'mode' => 'user_defined'
            ]);
            return $intValue;
        }

        Log::warning("⚠️ start_page نامعتبر دریافت شد، حالت هوشمند فعال می‌شود", [
            'invalid_value' => $startPageValue,
            'converted_to' => $intValue
        ]);
        return null;
    }

    private function extractSourceName(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $sourceName = preg_replace('/^www\./', '', $host ?? '');
        return str_replace('.', '_', $sourceName) ?: 'unknown_source';
    }

    private function buildConfigData(array $data): array
    {
        return [
            'general' => [
                'verify_ssl' => $data['verify_ssl'] ?? true,
                'follow_redirects' => $data['follow_redirects'] ?? true,
            ],
            'api' => [
                'endpoint' => $data['api_endpoint'] ?? '',
                'method' => $data['api_method'] ?? 'GET',
                'params' => $this->buildApiParams($data),
                'field_mapping' => $this->buildFieldMapping($data)
            ],
        ];
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

    private function buildFieldMapping(array $data): array
    {
        $mapping = [];
        foreach (array_keys(Config::getBookFields()) as $field) {
            $value = $data["api_field_{$field}"] ?? null;
            if (!empty($value)) {
                $mapping[$field] = $value;
            }
        }
        return $mapping;
    }
}
