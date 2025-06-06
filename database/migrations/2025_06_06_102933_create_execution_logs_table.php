<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('config_id')->constrained()->onDelete('cascade');
            $table->string('execution_id')->unique();
            $table->enum('status', ['running', 'completed', 'failed'])->default('running');

            // آمار
            $table->integer('total_processed')->default(0);
            $table->integer('total_success')->default(0);
            $table->integer('total_failed')->default(0);
            $table->integer('total_duplicate')->default(0);
            $table->decimal('execution_time', 8, 2)->nullable();

            // لاگ‌ها
            $table->json('log_details')->nullable();
            $table->text('error_message')->nullable();

            // زمان‌ها
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // ایندکس‌ها
            $table->index(['config_id', 'status']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_logs');
    }
};
