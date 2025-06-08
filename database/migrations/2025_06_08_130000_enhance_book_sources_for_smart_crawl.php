<?php

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
        Schema::table('book_sources', function (Blueprint $table) {
            if (!Schema::hasColumn('book_sources', 'source_name'))
                $table->string('source_name', 100)->nullable()->after('source_type');
            if (!Schema::hasColumn('book_sources', 'last_check_at'))
                $table->timestamp('last_check_at')->nullable()->after('source_updated_at');
            if (!Schema::hasColumn('book_sources', 'check_count'))
                $table->unsignedInteger('check_count')->default(0)->after('last_check_at');
            if (!Schema::hasColumn('book_sources', 'http_status'))
                $table->unsignedSmallInteger('http_status')->nullable()->after('check_count');
            if (!Schema::hasColumn('book_sources', 'response_time_ms'))
                $table->unsignedInteger('response_time_ms')->nullable()->after('http_status');
            if (!Schema::hasColumn('book_sources', 'metadata'))
                $table->json('metadata')->nullable()->after('response_time_ms');
        });

        // اصلاح نوع داده source_id
        $this->info('🔧 اصلاح نوع داده source_id...');
        DB::statement('ALTER TABLE book_sources MODIFY source_id VARCHAR(100) NOT NULL');

        $this->info('⚡ بهبود ایندکس‌های جدول...');

        // حذف ایندکس‌های قدیمی اگر وجود دارند
        $this->dropIndexIfExists('book_sources', 'book_sources_source_type_source_id_index');
        $this->dropIndexIfExists('book_sources', 'book_sources_book_id_source_type_index');

        // اضافه کردن ایندکس‌های جدید
        Schema::table('book_sources', function (Blueprint $table) {
            $table->index(['source_type', 'source_id'], 'idx_source_type_id');
            $table->index(['book_id', 'source_type', 'source_id'], 'idx_book_source_unique');
            $table->index(['source_type', 'is_active', 'priority'], 'idx_source_active_priority');
            $table->index(['source_type', 'created_at'], 'idx_source_created');
            $table->index(['source_type', 'last_check_at'], 'idx_source_check');
        });
        // ایندکس عددی source_id
        try {
            DB::statement('CREATE INDEX idx_source_id_numeric ON book_sources ((CAST(source_id AS UNSIGNED)))');
        } catch (\Throwable $e) {}

        $this->info('✅ ساختار جدول و ایندکس‌ها بهبود یافت');

        // سایر عملیات داده‌ای و constraint ها را طبق نیازت همینجا بگذار (مثل قبل)
    }

    // حذف ایندکس اگر وجود داشت
    private function dropIndexIfExists($table, $index)
    {
        try {
            Schema::table($table, function (Blueprint $t) use ($index) {
                $t->dropIndex($index);
            });
        } catch (\Throwable $e) {
            // ایندکس وجود نداشت، مهم نیست
        }
    }

    private function info($msg)
    {
        echo $msg . "\n";
    }

    public function down(): void
    {
        $this->info('⏪ بازگردانی تغییرات...');

        Schema::table('book_sources', function (Blueprint $table) {
            $cols = [
                'source_name',
                'last_check_at',
                'check_count',
                'http_status',
                'response_time_ms',
                'metadata'
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('book_sources', $col)) {
                    $table->dropColumn($col);
                }
            }
            $indexes = [
                'idx_source_type_id',
                'idx_book_source_unique',
                'idx_source_active_priority',
                'idx_source_created',
                'idx_source_check'
            ];
            foreach ($indexes as $idx) {
                try {
                    $table->dropIndex($idx);
                } catch (\Throwable $e) {}
            }
            try {
                DB::statement('DROP INDEX idx_source_id_numeric ON book_sources');
            } catch (\Throwable $e) {}
        });

        $this->info('✅ بازگردانی تمام شد');
    }
};
