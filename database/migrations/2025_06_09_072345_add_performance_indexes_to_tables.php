<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('configs', function (Blueprint $table) {
            $table->index(['is_running', 'created_at'], 'idx_configs_running_created');
        });

        Schema::table('execution_logs', function (Blueprint $table) {
            $table->index(['config_id', 'status', 'created_at'], 'idx_execution_logs_config_status_created');
        });

        Schema::table('books', function (Blueprint $table) {
            $table->index(['created_at', 'status'], 'idx_books_created_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('configs', function (Blueprint $table) {
            $table->dropIndex('idx_configs_running_created');
        });

        Schema::table('execution_logs', function (Blueprint $table) {
            $table->dropIndex('idx_execution_logs_config_status_created');
        });

        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex('idx_books_created_status');
        });
    }
};
