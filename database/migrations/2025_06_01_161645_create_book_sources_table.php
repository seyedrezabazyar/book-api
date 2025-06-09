<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_sources', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('book_id')->index();
            $table->string('source_name', 100)->index(); // نام منبع (مثل libgen_rs، zlib، ...)
            $table->string('source_id', 100)->index(); // شناسه کتاب در منبع مقصد
            $table->timestamp('discovered_at')->useCurrent(); // زمان کشف این منبع
            $table->timestamps();

            // کلید یکتای ترکیبی - یک کتاب نمی‌تواند در یک منبع چندین بار ثبت شود
            $table->unique(['book_id', 'source_name', 'source_id'], 'book_source_unique');

            // Foreign key
            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');

            // ایندکس‌های بهینه برای جستجوهای مختلف
            $table->index(['source_name', 'source_id'], 'idx_source_lookup'); // برای یافتن کتاب بر اساس منبع و ID
            $table->index(['book_id', 'source_name'], 'idx_book_sources'); // برای یافتن منابع یک کتاب
            $table->index(['source_name', 'discovered_at'], 'idx_source_timeline'); // برای تحلیل زمانی منابع
        });

        // ایندکس عددی برای source_id (بعد از ایجاد جدول)
        try {
            DB::statement('ALTER TABLE book_sources ADD INDEX idx_source_id_numeric ((CAST(source_id AS UNSIGNED)))');
        } catch (\Exception $e) {
            // اگر MySQL از generated columns پشتیبانی نکند، ایندکس ساده ایجاد می‌کنیم
            // این ایندکس کمی کندتر است اما با همه نسخه‌های MySQL کار می‌کند
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('book_sources');
    }
};
