<?php
// فایل: database/migrations/2025_06_01_161642_create_book_author_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_author', function (Blueprint $table) {
            $table->unsignedBigInteger('book_id')->index();
            $table->unsignedBigInteger('author_id')->index();
            $table->timestamps();

            // کلید اصلی ترکیبی
            $table->primary(['book_id', 'author_id']);

            // Foreign Keys
            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');
            $table->foreign('author_id')->references('id')->on('authors')->onDelete('cascade');

            // ایندکس برای جستجوی معکوس (کتاب‌های یک نویسنده)
            $table->index(['author_id', 'book_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_author');
    }
};
