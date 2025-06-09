<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            // شناسه‌های اصلی
            $table->bigIncrements('id');

            // اطلاعات کتاب
            $table->string('title', 500)->index();
            $table->text('description')->nullable();
            $table->text('excerpt')->nullable();
            $table->string('slug')->unique()->index();

            // متادیتا
            $table->string('isbn', 50)->nullable()->index();
            $table->unsignedSmallInteger('publication_year')->nullable()->index();
            $table->unsignedMediumInteger('pages_count')->nullable();
            $table->char('language', 2)->default('fa')->index();
            $table->enum('format', ['pdf', 'epub', 'mobi', 'djvu', 'audio'])->index();
            $table->unsignedBigInteger('file_size')->nullable();

            // دسته‌بندی
            $table->unsignedBigInteger('category_id')->index();
            $table->unsignedBigInteger('publisher_id')->nullable()->index();

            // آمار
            $table->unsignedBigInteger('downloads_count')->default(0)->index();

            // وضعیت
            $table->enum('status', ['active', 'hidden', 'deleted'])->default('active')->index();

            // زمان‌ها
            $table->timestamp('created_at')->useCurrent()->index();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // ایندکس‌های ترکیبی بهینه
            $table->index(['category_id', 'status', 'language']);
            $table->index(['format', 'language', 'status']);
            $table->index(['publication_year', 'status']);

            // جستجوی متنی
            $table->fullText(['title', 'description']);

            // Foreign Keys
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
            $table->foreign('publisher_id')->references('id')->on('publishers')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
