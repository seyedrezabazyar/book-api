<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_author', function (Blueprint $table) {
            $table->unsignedBigInteger('book_id')->index();
            $table->unsignedBigInteger('author_id')->index();
            $table->timestamps();

            $table->primary(['book_id', 'author_id']);
            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');
            $table->foreign('author_id')->references('id')->on('authors')->onDelete('cascade');
            $table->index(['author_id', 'book_id'], 'idx_author_book');
        });

        // Update existing records
        if (Schema::hasTable('book_author')) {
            DB::table('book_author')
                ->whereNull('created_at')
                ->update([
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('book_author')) {
            Schema::table('book_author', function (Blueprint $table) {
                $table->dropIndex('idx_author_book');
                $table->dropTimestamps();
            });
        }
        Schema::dropIfExists('book_author');
    }
};
