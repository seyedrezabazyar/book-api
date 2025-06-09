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
            $table->foreignId('config_id')->constrained()->onDelete('cascade');
            $table->string('url', 1000); // برای URL های طولانی
            $table->text('error_message');
            $table->json('error_details')->nullable();
            $table->text('response_content')->nullable();
            $table->integer('http_status')->nullable();
            $table->integer('retry_count')->default(0);
            $table->boolean('is_resolved')->default(false)->index();
            $table->timestamp('last_attempt_at');
            $table->timestamps();

            // ایندکس‌های بهینه
            $table->index(['config_id', 'is_resolved']);
            $table->index(['is_resolved', 'last_attempt_at']);
            $table->index(['config_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scraping_failures');
    }
};
