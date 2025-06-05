<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scraping_failures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('config_id');
            $table->text('url'); // URL که اسکرپ نشده
            $table->text('error_message'); // پیام خطا
            $table->json('error_details')->nullable(); // جزئیات تکنیکی
            $table->text('response_content')->nullable(); // محتوای پاسخ (در صورت وجود)
            $table->integer('http_status')->nullable(); // کد HTTP
            $table->integer('retry_count')->default(0); // تعداد تلاش مجدد
            $table->boolean('is_resolved')->default(false); // آیا حل شده؟
            $table->timestamp('last_attempt_at')->useCurrent();
            $table->timestamps();

            $table->foreign('config_id')->references('id')->on('configs')->onDelete('cascade');
            $table->index(['config_id', 'is_resolved']);
            $table->index('last_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scraping_failures');
    }
};
