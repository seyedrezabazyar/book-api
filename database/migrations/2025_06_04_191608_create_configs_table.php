<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configs', function (Blueprint $table) {
            // شناسه اصلی
            $table->id();

            // اطلاعات کلی
            $table->string('name')->unique()->index();
            $table->string('base_url', 500);

            // تنظیمات منبع
            $table->string('source_type', 50)->default('api')->index();
            $table->string('source_name', 100)->index(); // نام منبع برای book_sources

            // تنظیمات اتصال
            $table->unsignedSmallInteger('timeout')->default(30);
            $table->unsignedSmallInteger('delay_seconds')->default(5);
            $table->unsignedTinyInteger('records_per_run')->default(10);
            $table->unsignedTinyInteger('page_delay')->default(5);

            // تنظیمات کرال هوشمند
            $table->unsignedInteger('start_page')->nullable();
            $table->unsignedInteger('max_pages')->default(1000);
            $table->integer('current_page')->default(0);
            $table->unsignedInteger('last_source_id')->default(0)->index(); // آخرین ID منبع که پردازش شده

            // ویژگی‌های هوشمند
            $table->boolean('auto_resume')->default(true); // ادامه خودکار از آخرین ID
            $table->boolean('fill_missing_fields')->default(true); // تکمیل فیلدهای خالی
            $table->boolean('update_descriptions')->default(true); // بروزرسانی توضیحات بهتر

            // داده‌های کانفیگ (JSON)
            $table->json('config_data');

            // اطلاعات پیشرفت
            $table->unsignedInteger('total_processed')->default(0);
            $table->unsignedInteger('total_success')->default(0);
            $table->unsignedInteger('total_failed')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->boolean('is_running')->default(false);

            // کاربر ایجادکننده
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');

            // زمان‌ها
            $table->timestamps();

            // ایندکس‌های بهینه
            $table->index(['source_type', 'source_name'], 'idx_source_info');
            $table->index(['is_running', 'created_at'], 'idx_running_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configs');
    }
};
