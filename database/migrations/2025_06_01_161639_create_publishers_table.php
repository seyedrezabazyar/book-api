<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publishers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 200);
            $table->string('slug', 200)->unique()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('books_count')->default(0)->index();
            $table->timestamps();

            $table->fullText('name');
            $table->index(['is_active', 'created_at'], 'idx_active_created');
        });

        // Update existing records
        if (Schema::hasTable('publishers')) {
            DB::table('publishers')
                ->whereNull('is_active')
                ->update(['is_active' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('publishers')) {
            Schema::table('publishers', function (Blueprint $table) {
                $table->dropIndex('idx_active_created');
                $table->dropFullText('name');
            });
        }
        Schema::dropIfExists('publishers');
    }
};
