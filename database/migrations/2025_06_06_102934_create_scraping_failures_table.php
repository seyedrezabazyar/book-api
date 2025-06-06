<?php
// فایل: database/migrations/2025_06_06_102934_create_scraping_failures_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scraping_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('config_id')->constrained()->onDelete('cascade');
            $table->text('url'); // تغییر از string به text
            $table->text('error_message');
            $table->json('error_details')->nullable();
            $table->text('response_content')->nullable();
            $table->integer('http_status')->nullable();
            $table->integer('retry_count')->default(0);
            $table->boolean('is_resolved')->default(false)->index();
            $table->timestamp('last_attempt_at');
            $table->timestamps();

            // ایندکس‌های بهینه - بدون استفاده از فیلد URL در composite index
            $table->index(['config_id', 'is_resolved']);
            $table->index(['is_resolved', 'last_attempt_at']);
            $table->index(['config_id', 'created_at']); // جایگزین index مشکل‌دار

            // اگر نیاز به جستجو در URL هست، می‌توان از fulltext index استفاده کرد
            // $table->fullText('url'); // فقط در صورت نیاز uncomment کنید
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scraping_failures');
    }
};
