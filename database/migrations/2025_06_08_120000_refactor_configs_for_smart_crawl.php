<?php
// database/migrations/2025_06_08_120000_refactor_configs_for_smart_crawl.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->info('🔄 شروع اصلاح configs برای کرال هوشمند...');

        // 1. اضافه کردن فیلدهای جدید
        Schema::table('configs', function (Blueprint $table) {
            // حذف فیلد crawl_mode قدیمی و اضافه کردن فیلدهای جدید
            $table->dropColumn('crawl_mode');

            // تنظیمات کرال جدید
            $table->string('source_type', 50)->default('api')->after('base_url');
            $table->string('source_name', 100)->after('source_type'); // نام منبع برای book_sources
            $table->unsignedInteger('max_pages')->default(1000)->after('page_delay');
            $table->unsignedInteger('last_source_id')->default(0)->after('current_page'); // آخرین ID منبع که پردازش شده

            // فیلدهای بهبود یافته
            $table->boolean('auto_resume')->default(true)->after('max_pages'); // ادامه خودکار از آخرین ID
            $table->boolean('fill_missing_fields')->default(true)->after('auto_resume'); // تکمیل فیلدهای خالی
            $table->boolean('update_descriptions')->default(true)->after('fill_missing_fields'); // بروزرسانی توضیحات بهتر

            // ایندکس‌ها
            $table->index(['source_type', 'source_name']);
            $table->index('last_source_id');
        });

        // 2. بروزرسانی configs موجود
        $this->updateExistingConfigs();

        $this->info('✅ اصلاح configs تمام شد!');
    }

    private function updateExistingConfigs(): void
    {
        $this->info('📊 بروزرسانی configs موجود...');

        $configs = DB::table('configs')->get();

        foreach ($configs as $config) {
            // استخراج نام منبع از URL
            $sourceName = $this->extractSourceName($config->base_url);

            // یافتن آخرین source_id از book_sources
            $lastSourceId = DB::table('book_sources')
                ->where('source_type', 'api')
                ->where('source_url', 'like', '%' . parse_url($config->base_url, PHP_URL_HOST) . '%')
                ->max('source_id');

            DB::table('configs')
                ->where('id', $config->id)
                ->update([
                    'source_type' => 'api',
                    'source_name' => $sourceName,
                    'max_pages' => 1000,
                    'last_source_id' => (int) $lastSourceId ?: 0,
                    'auto_resume' => true,
                    'fill_missing_fields' => true,
                    'update_descriptions' => true,
                    'updated_at' => now()
                ]);

            $this->info("✅ Config '{$config->name}' بروزرسانی شد - آخرین ID: {$lastSourceId}");
        }
    }

    private function extractSourceName(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        // حذف www و تبدیل به نام منبع
        $sourceName = preg_replace('/^www\./', '', $host);
        $sourceName = str_replace('.', '_', $sourceName);

        return $sourceName ?: 'unknown_source';
    }

    private function info(string $message): void
    {
        echo $message . "\n";
    }

    public function down(): void
    {
        Schema::table('configs', function (Blueprint $table) {
            $table->dropColumn([
                'source_type',
                'source_name',
                'max_pages',
                'last_source_id',
                'auto_resume',
                'fill_missing_fields',
                'update_descriptions'
            ]);

            // بازگرداندن فیلد قدیمی
            $table->enum('crawl_mode', ['continue', 'restart', 'update'])->default('continue');
        });
    }
};
