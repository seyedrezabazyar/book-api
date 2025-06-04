<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_sources', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('book_id')->index();
            $table->string('source_type', 50)->default('lgrs')->index();
            $table->string('source_id', 100)->index(); // شناسه منبع خارجی
            $table->string('source_url', 1000)->nullable(); // URL مستقیم منبع
            $table->timestamp('source_updated_at')->nullable(); // آخرین بررسی منبع
            $table->boolean('is_active')->default(true)->index(); // وضعیت فعال بودن
            $table->tinyInteger('priority')->default(1)->index(); // اولویت نمایش
            $table->timestamps();

            // کلید یکتای ترکیبی برای جلوگیری از تکرار
            $table->unique(['book_id', 'source_type', 'source_id']);

            // Foreign key
            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');

            // ایندکس‌های ترکیبی برای بهینه‌سازی
            $table->index(['source_type', 'is_active']);
            $table->index(['book_id', 'priority']);
            $table->index(['is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_sources');
    }
};
