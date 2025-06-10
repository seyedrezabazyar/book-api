<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title', 500)->index();
            $table->text('description')->nullable();
            $table->text('excerpt')->nullable();
            $table->string('slug')->unique()->index();
            $table->string('isbn', 50)->nullable()->index();
            $table->unsignedSmallInteger('publication_year')->nullable()->index();
            $table->unsignedMediumInteger('pages_count')->nullable();
            $table->char('language', 2)->default('fa')->index();
            $table->enum('format', ['pdf', 'epub', 'mobi', 'djvu', 'audio'])->index();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedBigInteger('category_id')->index();
            $table->unsignedBigInteger('publisher_id')->nullable()->index();
            $table->unsignedBigInteger('downloads_count')->default(0)->index();
            $table->enum('status', ['active', 'hidden', 'deleted'])->default('active')->index();
            $table->timestamps();

            $table->fullText(['title', 'description']);
            $table->index(['category_id', 'status', 'language'], 'idx_category_status_lang');
            $table->index(['format', 'language', 'status'], 'idx_format_lang_status');
            $table->index(['publication_year', 'status'], 'idx_year_status');
            $table->index(['status', 'created_at'], 'idx_status_created');

            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
            $table->foreign('publisher_id')->references('id')->on('publishers')->onDelete('set null');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('books')) {
            Schema::table('books', function (Blueprint $table) {
                $table->dropIndex('idx_category_status_lang');
                $table->dropIndex('idx_format_lang_status');
                $table->dropIndex('idx_year_status');
                $table->dropIndex('idx_status_created');
                $table->dropFullText(['title', 'description']);
            });
        }
        Schema::dropIfExists('books');
    }
};
