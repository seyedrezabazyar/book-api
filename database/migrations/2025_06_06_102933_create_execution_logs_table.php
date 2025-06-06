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
            $table->enum('status', ['running', 'completed', 'failed', 'stopped'])->default('running');

            // آمار
            $table->integer('total_processed')->default(0);
            $table->integer('total_success')->default(0);
            $table->integer('total_failed')->default(0);
            $table->integer('total_duplicate')->default(0);
            $table->decimal('execution_time', 8, 2)->nullable();

            // لاگ‌ها و جزئیات
            $table->json('log_details')->nullable();
            $table->json('stats')->nullable(); // آمار جزئی‌تر
            $table->json('final_stats')->nullable(); // آمار نهایی
            $table->text('error_message')->nullable();

            // زمان‌ها
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('completed_at')->nullable(); // برای سازگاری با کد قبلی
            $table->timestamps();

            // ایندکس‌ها برای کارایی بهتر
            $table->index(['config_id', 'status']);
            $table->index(['config_id', 'completed_at']);
            $table->index(['execution_id']);
            $table->index('started_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_logs');
    }
};
