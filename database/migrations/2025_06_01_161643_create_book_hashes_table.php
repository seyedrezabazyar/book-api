<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_hashes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('book_id')->index();
            $table->char('book_hash', 32)->index()->comment('کپی از content_hash کتاب');
            $table->char('md5', 32)->unique()->comment('همان content_hash');
            $table->char('sha1', 40)->nullable()->unique();
            $table->char('sha256', 64)->nullable()->unique();
            $table->char('crc32', 8)->nullable();
            $table->char('ed2k_hash', 32)->nullable();
            $table->char('btih', 40)->nullable()->index();
            $table->string('magnet_link', 2000)->nullable();
            $table->timestamps();

            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_hashes');
    }
};
