<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 100)->unique();
            $table->string('slug', 100)->unique()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('books_count')->default(0)->index();
            $table->timestamps();

            $table->index(['is_active', 'created_at'], 'idx_active_created');
        });

        // Update existing records
        if (Schema::hasTable('categories')) {
            DB::table('categories')
                ->whereNull('is_active')
                ->update(['is_active' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('categories')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropIndex('idx_active_created');
            });
        }
        Schema::dropIfExists('categories');
    }
};
