<?php

namespace App\Console\Commands;

use App\Models\Config;
use App\Models\ExecutionLog;
use App\Services\ApiDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DebugApiCommand extends Command
{
    protected $signature = 'debug:api {config_id} {source_id=1}';
    protected $description = 'دیباگ API و پیدا کردن مشکل';

    public function handle(): int
    {
        $configId = $this->argument('config_id');
        $sourceId = (int) $this->argument('source_id');

        $this->info("🔍 شروع دیباگ API برای کانفیگ {$configId} و source ID {$sourceId}");

        // 1. بررسی کانفیگ
        $config = Config::find($configId);
        if (!$config) {
            $this->error("❌ کانفیگ {$configId} یافت نشد!");
            return 1;
        }

        $this->info("✅ کانفیگ یافت شد: {$config->name}");
        $this->info("   • منبع: {$config->source_name}");
        $this->info("   • base_url: {$config->base_url}");

        // 2. بررسی تنظیمات API
        $apiSettings = $config->getApiSettings();
        $this->info("📋 تنظیمات API:");
        $this->line("   • endpoint: " . ($apiSettings['endpoint'] ?? 'تعریف نشده'));
        $this->line("   • method: " . ($apiSettings['method'] ?? 'GET'));
        $this->line("   • field_mapping: " . (empty($apiSettings['field_mapping']) ? 'خالی' : count($apiSettings['field_mapping']) . ' فیلد'));

        // 3. ساخت URL
        try {
            $url = $config->buildApiUrl($sourceId);
            $this->info("🌐 URL ساخته شده: {$url}");
        } catch (\Exception $e) {
            $this->error("❌ خطا در ساخت URL: " . $e->getMessage());
            return 1;
        }

        // 4. تست HTTP Request
        $this->info("📡 تست درخواست HTTP...");
        try {
            $generalSettings = $config->getGeneralSettings();
            $httpClient = Http::timeout($config->timeout)->retry(1, 500);

            if (!($generalSettings['verify_ssl'] ?? true)) {
                $httpClient = $httpClient->withoutVerifying();
            }

            $response = $httpClient->get($url);

            $this->info("📊 پاسخ HTTP:");
            $this->line("   • Status: {$response->status()}");
            $this->line("   • Content-Type: " . $response->header('Content-Type'));
            $this->line("   • Size: " . strlen($response->body()) . " bytes");

            if ($response->successful()) {
                $this->info("✅ درخواست موفق بود");

                // 5. بررسی محتوای پاسخ
                try {
                    $data = $response->json();
                    $this->info("🔍 تحلیل پاسخ JSON:");

                    if (is_array($data)) {
                        $this->line("   • نوع: Array");
                        $this->line("   • کلیدهای اصلی: " . implode(', ', array_keys($data)));

                        // بررسی ساختار احتمالی کتاب
                        $this->checkBookStructure($data);

                        // تست استخراج فیلدها
                        $this->testFieldExtraction($data, $apiSettings);

                    } else {
                        $this->warn("⚠️ پاسخ آرایه نیست");
                    }

                } catch (\Exception $e) {
                    $this->error("❌ خطا در parse کردن JSON: " . $e->getMessage());
                    $this->line("📄 محتوای خام (اول 500 کاراکتر):");
                    $this->line(substr($response->body(), 0, 500));
                }

            } else {
                $this->error("❌ درخواست ناموفق: {$response->status()} - {$response->reason()}");
            }

        } catch (\Exception $e) {
            $this->error("❌ خطا در درخواست HTTP: " . $e->getMessage());
            return 1;
        }

        // 6. تست کامل ApiDataService
        $this->info("🧪 تست کامل ApiDataService...");
        try {
            $service = new ApiDataService($config);
            $executionLog = ExecutionLog::createNew($config);

            $result = $service->processSourceId($sourceId, $executionLog);

            $this->info("✅ تست ApiDataService موفق:");
            $this->line("   • Action: " . ($result['action'] ?? 'نامشخص'));
            $this->line("   • Stats: " . json_encode($result['stats'] ?? []));

            if (isset($result['title'])) {
                $this->line("   • عنوان کتاب: " . $result['title']);
            }

        } catch (\Exception $e) {
            $this->error("❌ خطا در ApiDataService: " . $e->getMessage());
            $this->line("📍 فایل: " . $e->getFile() . ":" . $e->getLine());
        }

        return 0;
    }

    private function checkBookStructure(array $data): void
    {
        $this->info("📚 بررسی ساختار کتاب:");

        // فیلدهای احتمالی کتاب
        $bookFields = ['title', 'name', 'book', 'data', 'result', 'id', 'description', 'author', 'authors'];

        $foundFields = [];
        $this->searchFieldsRecursive($data, $bookFields, $foundFields);

        if (!empty($foundFields)) {
            $this->info("✅ فیلدهای کتاب یافت شده:");
            foreach ($foundFields as $path => $value) {
                $preview = is_string($value) ? substr($value, 0, 50) : gettype($value);
                $this->line("   • {$path}: {$preview}");
            }
        } else {
            $this->warn("⚠️ هیچ فیلد آشنای کتابی یافت نشد");
        }
    }

    private function searchFieldsRecursive(array $data, array $searchFields, array &$found, string $path = ''): void
    {
        foreach ($data as $key => $value) {
            $currentPath = $path ? "{$path}.{$key}" : $key;

            if (in_array(strtolower($key), array_map('strtolower', $searchFields))) {
                $found[$currentPath] = $value;
            }

            if (is_array($value) && count($found) < 10) { // محدود کردن عمق جستجو
                $this->searchFieldsRecursive($value, $searchFields, $found, $currentPath);
            }
        }
    }

    private function testFieldExtraction(array $data, array $apiSettings): void
    {
        $this->info("🔧 تست استخراج فیلدها:");

        $fieldMapping = $apiSettings['field_mapping'] ?? [];

        if (empty($fieldMapping)) {
            $this->warn("⚠️ نقشه‌برداری فیلدها تعریف نشده - استفاده از پیش‌فرض");
            $fieldMapping = [
                'title' => 'title',
                'description' => 'description_en',
                'author' => 'authors',
            ];
        }

        foreach ($fieldMapping as $bookField => $apiField) {
            if (empty($apiField)) continue;

            $value = $this->getNestedValue($data, $apiField);
            if ($value !== null) {
                $preview = is_string($value) ? substr($value, 0, 50) : gettype($value);
                $this->info("   ✅ {$bookField} ({$apiField}): {$preview}");
            } else {
                $this->warn("   ❌ {$bookField} ({$apiField}): یافت نشد");
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
}
