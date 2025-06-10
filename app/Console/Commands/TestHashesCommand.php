<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\FieldExtractor;
use App\Services\BookProcessor;
use App\Models\Config;

class TestHashesCommand extends Command
{
    protected $signature = 'test:hashes
                          {--sample-data : استفاده از داده‌های نمونه}
                          {--config-id= : ID کانفیگ برای تست}';

    protected $description = 'تست استخراج هش‌ها از داده‌های API';

    public function handle()
    {
        $this->info("🧪 شروع تست استخراج هش‌ها");

        if ($this->option('sample-data')) {
            $this->testWithSampleData();
        } elseif ($configId = $this->option('config-id')) {
            $this->testWithConfig($configId);
        } else {
            $this->testBothMethods();
        }

        $this->info("✅ تست‌ها تمام شد");
    }

    private function testWithSampleData()
    {
        $this->info("📦 تست با داده‌های نمونه");

        $sampleDataSets = [
            'basic_hashes' => [
                'title' => 'کتاب نمونه 1',
                'md5' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6',
                'sha1' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0',
                'sha256' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2',
                'crc32' => 'a1b2c3d4',
                'magnet' => 'magnet:?xt=urn:btih:a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0'
            ],
            'nested_hashes' => [
                'title' => 'کتاب نمونه 2',
                'hashes' => [
                    'md5' => 'b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6',
                    'sha1' => 'b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0',
                    'btih' => 'b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0'
                ],
                'download_info' => [
                    'magnet' => 'magnet:?xt=urn:btih:b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0'
                ]
            ],
            'complex_structure' => [
                'title' => 'کتاب نمونه 3',
                'data' => [
                    'book' => [
                        'title' => 'کتاب پیچیده',
                        'file_hashes' => [
                            'md5' => 'c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6',
                            'sha256' => 'c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2'
                        ],
                        'torrent' => [
                            'btih' => 'c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d0',
                            'magnet' => 'magnet:?xt=urn:btih:c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d0'
                        ]
                    ]
                ]
            ],
            'invalid_hashes' => [
                'title' => 'کتاب با هش‌های نامعتبر',
                'md5' => 'invalid_md5',
                'sha1' => 'too_short',
                'magnet' => 'not_a_magnet_link',
                'valid_sha256' => 'd1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0d1e2'
            ]
        ];

        foreach ($sampleDataSets as $testName => $sampleData) {
            $this->testDataSet($testName, $sampleData);
        }
    }

    private function testWithConfig(int $configId)
    {
        $this->info("🔧 تست با کانفیگ ID: {$configId}");

        $config = Config::find($configId);
        if (!$config) {
            $this->error("❌ کانفیگ {$configId} یافت نشد!");
            return;
        }

        $this->info("📋 کانفیگ: {$config->name}");
        $this->info("🌐 منبع: {$config->source_name}");

        // تست با چند source ID
        $testSourceIds = [1, 10, 100, 500];

        foreach ($testSourceIds as $sourceId) {
            $this->info("\n🔍 تست source ID: {$sourceId}");

            try {
                $url = $config->buildApiUrl($sourceId);
                $this->line("URL: {$url}");

                // فرضی: درخواست API (در تست واقعی باید به API متصل شوید)
                $this->warn("⚠️ برای تست کامل، به API واقعی متصل شوید");

            } catch (\Exception $e) {
                $this->error("❌ خطا در ساخت URL: " . $e->getMessage());
            }
        }
    }

    private function testBothMethods()
    {
        $this->testWithSampleData();

        $this->newLine();
        $this->info("🔧 تست با کانفیگ‌های موجود:");

        $configs = Config::where('is_active', true)->limit(3)->get();

        if ($configs->isEmpty()) {
            $this->warn("⚠️ هیچ کانفیگ فعالی یافت نشد");
            return;
        }

        foreach ($configs as $config) {
            $this->line("• {$config->name} (ID: {$config->id})");
        }

        $this->newLine();
        $this->info("💡 برای تست با کانفیگ خاص: php artisan test:hashes --config-id=ID");
    }

    private function testDataSet(string $testName, array $data)
    {
        $this->newLine();
        $this->info("🧪 تست: {$testName}");
        $this->line("📥 داده ورودی:");
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        try {
            // ایجاد کانفیگ فرضی برای تست
            $testConfig = new Config([
                'source_name' => 'test_source',
                'config_data' => ['api' => ['field_mapping' => []]]
            ]);

            $fieldExtractor = new FieldExtractor();
            $extracted = $fieldExtractor->extractFields($data, $testConfig);

            $this->line("\n📤 فیلدهای استخراج شده:");

            // نمایش فیلدهای عمومی
            $generalFields = ['title', 'description', 'author', 'isbn'];
            foreach ($generalFields as $field) {
                if (isset($extracted[$field])) {
                    $this->line("  • {$field}: {$extracted[$field]}");
                }
            }

            // نمایش هش‌ها
            $hashFields = ['md5', 'sha1', 'sha256', 'crc32', 'ed2k', 'btih', 'magnet'];
            $foundHashes = [];

            foreach ($hashFields as $hashField) {
                if (isset($extracted[$hashField])) {
                    $foundHashes[$hashField] = $extracted[$hashField];
                }
            }

            if (!empty($foundHashes)) {
                $this->info("\n🔐 هش‌های یافت شده:");
                foreach ($foundHashes as $type => $hash) {
                    $displayHash = strlen($hash) > 50 ? substr($hash, 0, 47) . '...' : $hash;
                    $this->line("  ✅ {$type}: {$displayHash}");
                }
            } else {
                $this->warn("  ⚠️ هیچ هش معتبری یافت نشد!");
            }

            // اعتبارسنجی هش‌ها
            $this->validateExtractedHashes($foundHashes);

        } catch (\Exception $e) {
            $this->error("❌ خطا در تست: " . $e->getMessage());
        }
    }

    private function validateExtractedHashes(array $hashes)
    {
        if (empty($hashes)) {
            return;
        }

        $this->line("\n🔍 اعتبارسنجی هش‌ها:");

        $validationRules = [
            'md5' => '/^[a-f0-9]{32}$/i',
            'sha1' => '/^[a-f0-9]{40}$/i',
            'sha256' => '/^[a-f0-9]{64}$/i',
            'crc32' => '/^[a-f0-9]{8}$/i',
            'ed2k' => '/^[a-f0-9]{32}$/i',
            'btih' => '/^[a-f0-9]{40}$/i',
            'magnet' => '/^magnet:\?xt=/i'
        ];

        foreach ($hashes as $type => $hash) {
            if (isset($validationRules[$type])) {
                $isValid = preg_match($validationRules[$type], $hash);
                $status = $isValid ? '✅' : '❌';
                $this->line("  {$status} {$type}: " . ($isValid ? 'معتبر' : 'نامعتبر'));
            }
        }
    }

    private function displayHashStats()
    {
        $this->info("\n📊 آمار هش‌ها در دیتابیس:");

        try {
            $stats = \Illuminate\Support\Facades\DB::table('book_hashes')
                ->selectRaw('
                    COUNT(*) as total_records,
                    COUNT(CASE WHEN md5 IS NOT NULL THEN 1 END) as md5_count,
                    COUNT(CASE WHEN sha1 IS NOT NULL THEN 1 END) as sha1_count,
                    COUNT(CASE WHEN sha256 IS NOT NULL THEN 1 END) as sha256_count,
                    COUNT(CASE WHEN crc32 IS NOT NULL THEN 1 END) as crc32_count,
                    COUNT(CASE WHEN ed2k_hash IS NOT NULL THEN 1 END) as ed2k_count,
                    COUNT(CASE WHEN btih IS NOT NULL THEN 1 END) as btih_count,
                    COUNT(CASE WHEN magnet_link IS NOT NULL THEN 1 END) as magnet_count
                ')
                ->first();

            $this->table(
                ['نوع هش', 'تعداد', 'درصد پوشش'],
                [
                    ['MD5', number_format($stats->md5_count), round(($stats->md5_count / max($stats->total_records, 1)) * 100, 1) . '%'],
                    ['SHA1', number_format($stats->sha1_count), round(($stats->sha1_count / max($stats->total_records, 1)) * 100, 1) . '%'],
                    ['SHA256', number_format($stats->sha256_count), round(($stats->sha256_count / max($stats->total_records, 1)) * 100, 1) . '%'],
                    ['CRC32', number_format($stats->crc32_count), round(($stats->crc32_count / max($stats->total_records, 1)) * 100, 1) . '%'],
                    ['ED2K', number_format($stats->ed2k_count), round(($stats->ed2k_count / max($stats->total_records, 1)) * 100, 1) . '%'],
                    ['BTIH', number_format($stats->btih_count), round(($stats->btih_count / max($stats->total_records, 1)) * 100, 1) . '%'],
                    ['Magnet', number_format($stats->magnet_count), round(($stats->magnet_count / max($stats->total_records, 1)) * 100, 1) . '%'],
                ]
            );

        } catch (\Exception $e) {
            $this->error("❌ خطا در دریافت آمار: " . $e->getMessage());
        }
    }
}
