<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * اجرای مایگریشن
     */
    public function up(): void
    {
        Schema::create('configs', function (Blueprint $table) {
            $table->id();

            // اطلاعات اصلی کانفیگ
            $table->string('name')->unique()->comment('نام کانفیگ');
            $table->text('description')->nullable()->comment('توضیحات کانفیگ');

            // داده‌های کانفیگ به صورت JSON
            $table->json('config_data')->comment('داده‌های کانفیگ');

            // وضعیت کانفیگ
            $table->enum('status', ['active', 'inactive', 'draft'])
                ->default('draft')
                ->comment('وضعیت کانفیگ');

            // اطلاعات ایجادکننده
            $table->unsignedBigInteger('created_by')->nullable()->comment('شناسه کاربر ایجادکننده');

            // تاریخ‌های ایجاد و به‌روزرسانی
            $table->timestamps();

            // فهرست‌ها برای بهینه‌سازی جستجو
            $table->index(['status', 'created_at']);
            $table->index('name');

            // کلید خارجی برای کاربر (در صورت وجود جدول users)
            // $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * برگرداندن تغییرات مایگریشن
     */
    public function down(): void
    {
        Schema::dropIfExists('configs');
    }
};
