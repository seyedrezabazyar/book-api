<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('config_id')->constrained()->onDelete('cascade');
            $table->string('execution_id', 50)->unique();
            $table->enum('status', ['running', 'completed', 'failed', 'stopped'])->default('running')->index();

            // آمار اصلی
            $table->unsignedInteger('total_processed')->default(0)->index();
            $table->unsignedInteger('total_success')->default(0)->index();
            $table->unsignedInteger('total_failed')->default(0);
            $table->unsignedInteger('total_duplicate')->default(0);
            $table->decimal('execution_time', 10, 2)->nullable(); // افزایش precision برای زمان‌های طولانی

            // آمار تفصیلی و پیشرفت
            $table->unsignedInteger('current_page')->default(1);
            $table->unsignedInteger('total_pages')->nullable();
            $table->decimal('success_rate', 5, 2)->nullable(); // نرخ موفقیت محاسبه شده
            $table->unsignedInteger('records_per_minute')->nullable(); // سرعت پردازش

            // لاگ‌ها و جزئیات
            $table->json('log_details')->nullable();
            $table->json('page_stats')->nullable(); // آمار هر صفحه
            $table->json('performance_stats')->nullable(); // آمار عملکرد
            $table->json('error_details')->nullable(); // جزئیات خطاها
            $table->json('final_summary')->nullable(); // خلاصه نهایی
            $table->text('error_message')->nullable();
            $table->text('stop_reason')->nullable(); // دلیل توقف

            // زمان‌ها
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_activity_at')->nullable(); // آخرین فعالیت
            $table->timestamps();

            // ایندکس‌های بهینه شده
            $table->index(['config_id', 'status', 'started_at']); // ترکیبی برای فیلترها
            $table->index(['status', 'started_at']); // برای نمایش عمومی
            $table->index(['config_id', 'finished_at']); // برای تاریخچه کامل
            $table->index(['execution_id']);
            $table->index(['started_at', 'finished_at']); // برای محاسبه مدت زمان
            $table->index('last_activity_at'); // برای یافتن logs غیرفعال
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_logs');
    }
};
