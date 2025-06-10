<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_hashes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('book_id')->unique()->index();
            $table->char('md5', 32)->unique()->index()->comment('هش اصلی فایل');
            $table->char('sha1', 40)->nullable()->unique();
            $table->char('sha256', 64)->nullable()->unique();
            $table->char('crc32', 8)->nullable();
            $table->char('ed2k_hash', 32)->nullable();
            $table->char('btih', 40)->nullable()->index();
            $table->string('magnet_link', 2000)->nullable();
            $table->timestamps();

            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');

            try {
                $table->index(['sha1'], 'idx_sha1_lookup');
                $table->index(['sha256'], 'idx_sha256_lookup');
                $table->index(['btih'], 'idx_btih_lookup');
            } catch (\Exception $e) {
                // Indexes might already exist
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('book_hashes')) {
            Schema::table('book_hashes', function (Blueprint $table) {
                try {
                    $table->dropIndex('idx_sha1_lookup');
                    $table->dropIndex('idx_sha256_lookup');
                    $table->dropIndex('idx_btih_lookup');
                } catch (\Exception $e) {
                    // Indexes might not exist
                }
                $table->dropTimestamps();
            });
        }
        Schema::dropIfExists('book_hashes');
    }
};
