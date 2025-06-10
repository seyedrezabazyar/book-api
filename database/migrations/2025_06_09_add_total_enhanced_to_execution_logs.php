<?php
// فایل: database/migrations/2025_06_09_add_total_enhanced_to_execution_logs.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('execution_logs', function (Blueprint $table) {
            // اضافه کردن فیلد جدید برای کتاب‌های بهبود یافته
            $table->unsignedInteger('total_enhanced')->default(0)->after('total_duplicate');

            // اضافه کردن ایندکس برای جستجوی بهتر
            $table->index(['config_id', 'total_enhanced']);
        });
    }

    public function down(): void
    {
        Schema::table('execution_logs', function (Blueprint $table) {
            $table->dropIndex(['config_id', 'total_enhanced']);
            $table->dropColumn('total_enhanced');
        });
    }
};
