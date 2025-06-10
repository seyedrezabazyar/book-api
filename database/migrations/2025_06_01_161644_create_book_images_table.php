<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_images', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('book_id')->index();
            $table->string('image_url', 1000);
            $table->timestamps();

            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');
            $table->index(['book_id', 'created_at'], 'idx_book_image_created');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('book_images')) {
            Schema::table('book_images', function (Blueprint $table) {
                $table->dropIndex('idx_book_image_created');
                $table->dropTimestamps();
            });
        }
        Schema::dropIfExists('book_images');
    }
};
