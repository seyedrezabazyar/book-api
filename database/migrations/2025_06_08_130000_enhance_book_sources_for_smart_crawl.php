<?php
// database/migrations/2025_06_08_130000_enhance_book_sources_for_smart_crawl.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->info('🔧 شروع بهبود جدول book_sources برای کرال هوشمند...');

        // 1. بررسی و اضافه کردن فیلدهای جدید
        $this->enhanceBookSourcesTable();

        // 2. بهبود ایندکس‌ها
        $this->improveIndexes();

        // 3. پاکسازی و بهینه‌سازی داده‌های موجود
        $this->cleanupAndOptimize();

        // 4. اضافه کردن constraint ها
        $this->addConstraints();

        $this->info('✅ بهبود جدول book_sources تمام شد!');
    }

    private function enhanceBookSourcesTable(): void
    {
        $this->info('📊 بررسی و بهبود ساختار جدول...');

        Schema::table('book_sources', function (Blueprint $table) {
            // بررسی وجود فیلدها و اضافه کردن در صورت عدم وجود
            if (!Schema::hasColumn('book_sources', 'source_name')) {
                $table->string('source_name', 100)->nullable()->after('source_type');
            }

            if (!Schema::hasColumn('book_sources', 'last_check_at')) {
                $table->timestamp('last_check_at')->nullable()->after('source_updated_at');
            }

            if (!Schema::hasColumn('book_sources', 'check_count')) {
                $table->unsignedInteger('check_count')->default(0)->after('last_check_at');
            }

            if (!Schema::hasColumn('book_sources', 'http_status')) {
                $table->unsignedSmallInteger('http_status')->nullable()->after('check_count');
            }

            if (!Schema::hasColumn('book_sources', 'response_time_ms')) {
                $table->unsignedInteger('response_time_ms')->nullable()->after('http_status');
            }

            if (!Schema::hasColumn('book_sources', 'metadata')) {
                $table->json('metadata')->nullable()->after('response_time_ms');
            }
        });

        // اصلاح نوع داده source_id
        $this->info('🔧 اصلاح نوع داده source_id...');
        DB::statement('ALTER TABLE book_sources MODIFY source_id VARCHAR(100) NOT NULL');

        $this->info('✅ ساختار جدول بهبود یافت');
    }

    private function improveIndexes(): void
    {
        $this->info('⚡ بهبود ایندکس‌های جدول...');

        Schema::table('book_sources', function (Blueprint $table) {
            // حذف ایندکس‌های احتمالی قدیمی
            try {
                $table->dropIndex(['source_type', 'source_id']);
            } catch (\Exception $e) {
                // ایندکس وجود ندارد
            }

            try {
                $table->dropIndex(['book_id', 'source_type']);
            } catch (\Exception $e) {
                // ایندکس وجود ندارد
            }

            // اضافه کردن ایندکس‌های بهینه جدید
            $table->index(['source_type', 'source_id'], 'idx_source_type_id');
            $table->index(['book_id', 'source_type', 'source_id'], 'idx_book_source_unique');
            $table->index(['source_type', 'is_active', 'priority'], 'idx_source_active_priority');
            $table->index(['source_type', 'created_at'], 'idx_source_created');
            $table->index(['source_type', 'last_check_at'], 'idx_source_check');

            // ایندکس برای جستجوهای عددی source_id
            DB::statement('CREATE INDEX idx_source_id_numeric ON book_sources ((CAST(source_id AS UNSIGNED)))');
        });

        $this->info('✅ ایندکس‌ها بهبود یافتند');
    }

    private function cleanupAndOptimize(): void
    {
        $this->info('🧹 پاکسازی و بهینه‌سازی داده‌ها...');

        // 1. پر کردن source_name برای رکوردهای موجود
        $this->fillMissingSourceNames();

        // 2. حذف رکوردهای تکراری
        $this->removeDuplicates();

        // 3. بروزرسانی فیلدهای جدید
        $this->updateNewFields();

        // 4. آنالیز جدول برای بهینه‌سازی
        DB::statement('ANALYZE TABLE book_sources');

        $this->info('✅ پاکسازی و بهینه‌سازی تمام شد');
    }

    private function fillMissingSourceNames(): void
    {
        $this->info('📝 پر کردن source_name های مفقود...');

        // بروزرسانی source_name بر اساس URL
        $sources = DB::table('book_sources')
            ->whereNull('source_name')
            ->orWhere('source_name', '')
            ->select('id', 'source_url')
            ->get();

        $updateCount = 0;
        foreach ($sources as $source) {
            if (!empty($source->source_url)) {
                $host = parse_url($source->source_url, PHP_URL_HOST);
                if ($host) {
                    $sourceName = preg_replace('/^www\./', '', $host);
                    $sourceName = str_replace('.', '_', $sourceName);

                    DB::table('book_sources')
                        ->where('id', $source->id)
                        ->update(['source_name' => $sourceName]);

                    $updateCount++;
                }
            }
        }

        $this->info("✅ {$updateCount} source_name پر شد");
    }

    private function removeDuplicates(): void
    {
        $this->info('🔄 حذف رکوردهای تکراری...');

        // یافتن و حذف تکراری‌ها (نگه داشتن جدیدترین)
        $duplicates = DB::select("
            SELECT book_id, source_type, source_id, COUNT(*) as count
            FROM book_sources
            GROUP BY book_id, source_type, source_id
            HAVING count > 1
        ");

        $removedCount = 0;
        foreach ($duplicates as $duplicate) {
            // حذف همه به جز جدیدترین
            $toDelete = DB::table('book_sources')
                ->where('book_id', $duplicate->book_id)
                ->where('source_type', $duplicate->source_type)
                ->where('source_id', $duplicate->source_id)
                ->orderBy('id', 'desc')
                ->skip(1) // نگه داشتن اولین (جدیدترین)
                ->pluck('id');

            if ($toDelete->isNotEmpty()) {
                DB::table('book_sources')->whereIn('id', $toDelete)->delete();
                $removedCount += $toDelete->count();
            }
        }

        $this->info("✅ {$removedCount} رکورد تکراری حذف شد");
    }

    private function updateNewFields(): void
    {
        $this->info('🔄 بروزرسانی فیلدهای جدید...');

        // تنظیم مقادیر پیش‌فرض
        DB::table('book_sources')
            ->whereNull('check_count')
            ->update([
                'check_count' => 1,
                'last_check_at' => DB::raw('COALESCE(source_updated_at, created_at)'),
                'metadata' => json_encode([
                    'created_by_migration' => true,
                    'migration_date' => now()->toISOString()
                ])
            ]);

        $this->info('✅ فیلدهای جدید بروزرسانی شدند');
    }

    private function addConstraints(): void
    {
        $this->info('🔒 اضافه کردن constraint ها...');

        try {
            // اضافه کردن unique constraint برای جلوگیری از تکرار
            DB::statement('
                ALTER TABLE book_sources
                ADD CONSTRAINT unique_book_source_id
                UNIQUE (book_id, source_type, source_id)
            ');
            $this->info('✅ Unique constraint اضافه شد');
        } catch (\Exception $e) {
            $this->warn('⚠️ Unique constraint قبلاً وجود دارد یا نمی‌تواند اضافه شود');
        }

        try {
            // اضافه کردن check constraint برای priority
            DB::statement('
                ALTER TABLE book_sources
                ADD CONSTRAINT check_priority_range
                CHECK (priority BETWEEN 1 AND 10)
            ');
            $this->info('✅ Priority constraint اضافه شد');
        } catch (\Exception $e) {
            $this->warn('⚠️ Priority constraint قبلاً وجود دارد');
        }
    }

    private function info(string $message): void
    {
        echo $message . "\n";
    }

    private function warn(string $message): void
    {
        echo "⚠️ " . $message . "\n";
    }

    public function down(): void
    {
        $this->info('⏪ بازگردانی تغییرات...');

        Schema::table('book_sources', function (Blueprint $table) {
            // حذف فیلدهای اضافه شده
            $table->dropColumn([
                'source_name',
                'last_check_at',
                'check_count',
                'http_status',
                'response_time_ms',
                'metadata'
            ]);

            // حذف ایندکس‌های اضافه شده
            try {
                $table->dropIndex('idx_source_type_id');
                $table->dropIndex('idx_book_source_unique');
                $table->dropIndex('idx_source_active_priority');
                $table->dropIndex('idx_source_created');
                $table->dropIndex('idx_source_check');
                DB::statement('DROP INDEX idx_source_id_numeric ON book_sources');
            } catch (\Exception $e) {
                // ایندکس‌ها ممکن است وجود نداشته باشند
            }
        });

        // حذف constraint ها
        try {
            DB::statement('ALTER TABLE book_sources DROP CONSTRAINT unique_book_source_id');
            DB::statement('ALTER TABLE book_sources DROP CONSTRAINT check_priority_range');
        } catch (\Exception $e) {
            // Constraint ها ممکن است وجود نداشته باشند
        }

        $this->info('✅ بازگردانی تمام شد');
    }
};
