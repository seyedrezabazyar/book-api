<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * بهبود ایندکس‌های جدول books برای عملکرد بهتر
     */
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // ایندکس ترکیبی برای جستجوی تکراری‌ها
            $table->index(['title', 'publication_year'], 'books_title_year_index');

            // ایندکس برای content_hash (اگر وجود ندارد)
            if (!$this->indexExists('books', 'books_content_hash_index')) {
                $table->index('content_hash', 'books_content_hash_index');
            }

            // ایندکس برای ISBN
            if (!$this->indexExists('books', 'books_isbn_index')) {
                $table->index('isbn', 'books_isbn_index');
            }
        });

        // بهبود ایندکس جدول book_author
        Schema::table('book_author', function (Blueprint $table) {
            // ایندکس ترکیبی برای جستجوی سریع‌تر
            if (!$this->indexExists('book_author', 'book_author_book_created_index')) {
                $table->index(['book_id', 'created_at'], 'book_author_book_created_index');
            }
        });

        // بهبود ایندکس جدول authors
        Schema::table('authors', function (Blueprint $table) {
            // ایندکس برای جستجوی نام
            if (!$this->indexExists('authors', 'authors_name_index')) {
                $table->index('name', 'authors_name_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex('books_title_year_index');

            if ($this->indexExists('books', 'books_content_hash_index')) {
                $table->dropIndex('books_content_hash_index');
            }

            if ($this->indexExists('books', 'books_isbn_index')) {
                $table->dropIndex('books_isbn_index');
            }
        });

        Schema::table('book_author', function (Blueprint $table) {
            if ($this->indexExists('book_author', 'book_author_book_created_index')) {
                $table->dropIndex('book_author_book_created_index');
            }
        });

        Schema::table('authors', function (Blueprint $table) {
            if ($this->indexExists('authors', 'authors_name_index')) {
                $table->dropIndex('authors_name_index');
            }
        });
    }

    /**
     * بررسی وجود ایندکس
     */
    private function indexExists(string $table, string $index): bool
    {
        $indexes = collect(\Illuminate\Support\Facades\DB::select("SHOW INDEX FROM {$table}"))
            ->pluck('Key_name')
            ->toArray();

        return in_array($index, $indexes);
    }
};
