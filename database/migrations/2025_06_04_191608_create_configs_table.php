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
            $table->text('description')->nullable();
            $table->string('base_url', 500);

            // تنظیمات اتصال
            $table->unsignedSmallInteger('timeout')->default(30);
            $table->unsignedSmallInteger('delay_seconds')->default(5);
            $table->unsignedTinyInteger('records_per_run')->default(10);
            $table->unsignedTinyInteger('page_delay')->default(5);

            // تنظیمات کرال
            $table->enum('crawl_mode', ['continue', 'restart', 'update'])->default('continue');
            $table->unsignedInteger('start_page')->nullable();
            $table->unsignedInteger('current_page')->default(1);

            // داده‌های کانفیگ (JSON)
            $table->json('config_data');

            // وضعیت
            $table->enum('status', ['active', 'inactive', 'draft'])->default('draft')->index();

            // اطلاعات پیشرفت
            $table->unsignedInteger('total_processed')->default(0);
            $table->unsignedInteger('total_success')->default(0);
            $table->unsignedInteger('total_failed')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->boolean('is_running')->default(false)->index();

            // کاربر ایجادکننده
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');

            // زمان‌ها
            $table->timestamps();

            // ایندکس‌های بهینه
            $table->index(['status', 'is_running']);
            $table->index(['crawl_mode', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configs');
    }
};
