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

            // نوع دریافت اطلاعات: api یا crawler
            $table->enum('data_source_type', ['api', 'crawler'])
                ->default('api')
                ->comment('نوع روش دریافت اطلاعات');

            // تنظیمات اتصال پایه
            $table->string('base_url')->comment('آدرس پایه سایت');
            $table->integer('timeout')->default(30)->comment('تایم‌اوت درخواست به ثانیه');
            $table->integer('max_retries')->default(3)->comment('تعداد تلاش مجدد');
            $table->integer('delay')->default(1000)->comment('تاخیر بین درخواست‌ها به میلی‌ثانیه');

            // داده‌های کانفیگ به صورت JSON شامل تمام تنظیمات
            $table->json('config_data')->comment('تنظیمات تفصیلی کانفیگ');

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
            $table->index(['data_source_type', 'status']);
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
