<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->enum('data_source_type', ['api', 'crawler'])->default('api');
            $table->string('base_url');
            $table->integer('timeout')->default(30);
            $table->integer('max_retries')->default(3);

            // تنظیمات سرعت اسکرپر
            $table->integer('delay_seconds')->default(1); // تاخیر بین درخواست‌ها (ثانیه)
            $table->integer('records_per_run')->default(1); // تعداد رکورد در هر اجرا

            $table->json('config_data');
            $table->enum('status', ['active', 'inactive', 'draft'])->default('draft');

            // اطلاعات پیشرفت
            $table->text('current_url')->nullable(); // آخرین URL پردازش شده
            $table->integer('total_processed')->default(0); // کل پردازش شده
            $table->integer('total_success')->default(0); // موفق
            $table->integer('total_failed')->default(0); // ناموفق
            $table->timestamp('last_run_at')->nullable();
            $table->boolean('is_running')->default(false);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'is_running']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configs');
    }
};
