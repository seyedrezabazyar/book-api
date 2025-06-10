<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_sources', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('book_id')->index();
            $table->string('source_name', 100)->index();
            $table->string('source_id', 100)->index();
            $table->timestamp('discovered_at')->useCurrent();
            $table->timestamps();

            $table->unique(['book_id', 'source_name', 'source_id'], 'book_source_unique');
            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');
            $table->index(['source_name', 'source_id'], 'idx_source_lookup');
            $table->index(['book_id', 'source_name'], 'idx_book_sources');

            try {
                $table->index(['source_name', 'discovered_at'], 'idx_source_discovery_time');
            } catch (\Exception $e) {
                // Index might already exist
            }
        });

        // Numeric index for source_id
        try {
            DB::statement('ALTER TABLE book_sources ADD INDEX idx_source_id_numeric ((CAST(source_id AS UNSIGNED)))');
        } catch (\Exception $e) {
            // Fallback to simple index
            Schema::table('book_sources', function (Blueprint $table) {
                $table->index('source_id', 'idx_source_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('book_sources')) {
            Schema::table('book_sources', function (Blueprint $table) {
                try {
                    $table->dropIndex('idx_source_discovery_time');
                    $table->dropIndex('idx_source_lookup');
                    $table->dropIndex('idx_book_sources');
                    $table->dropIndex('idx_source_id_numeric');
                } catch (\Exception $e) {
                    // Indexes might not exist
                }
                $table->dropTimestamps();
            });
        }
        Schema::dropIfExists('book_sources');
    }
};
