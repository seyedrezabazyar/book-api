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
            $table->string('execution_id', 50)->unique();
            $table->enum('status', ['running', 'completed', 'failed', 'stopped'])->default('running')->index();

            // آمار اصلی
            $table->unsignedInteger('total_processed')->default(0);
            $table->unsignedInteger('total_success')->default(0);
            $table->unsignedInteger('total_failed')->default(0);
            $table->unsignedInteger('total_duplicate')->default(0);
            $table->decimal('execution_time', 10, 2)->nullable();
            $table->decimal('success_rate', 5, 2)->nullable();

            // لاگ‌ها و جزئیات
            $table->json('log_details')->nullable();
            $table->text('error_message')->nullable();
            $table->text('stop_reason')->nullable();

            // زمان‌ها
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            // ایندکس‌های بهینه
            $table->index(['config_id', 'status', 'started_at']);
            $table->index(['status', 'started_at']);
            $table->index('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_logs');
    }
};
