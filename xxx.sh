#!/bin/bash

echo "🔍 شروع تشخیص مشکل..."

# 1. بررسی وضعیت queue
echo "📊 بررسی وضعیت queue:"
php artisan queue:monitor

# 2. بررسی job های pending
echo "⏳ Job های در انتظار:"
php artisan tinker --execute="
echo 'Jobs در انتظار: ' . \Illuminate\Support\Facades\DB::table('jobs')->count() . PHP_EOL;
echo 'Failed jobs: ' . \Illuminate\Support\Facades\DB::table('failed_jobs')->count() . PHP_EOL;
"

# 3. اجرای یک job pending (اگر وجود دارد)
echo "⚡ اجرای job pending:"
php artisan queue:work --once -v

# 4. نمایش آخرین 30 خط لاگ
echo "📝 آخرین لاگ‌ها:"
tail -30 storage/logs/laravel.log

# 5. بررسی کانفیگ‌های موجود
echo "⚙️ کانفیگ‌های موجود:"
php artisan tinker --execute="
\$configs = App\Models\Config::all();
foreach(\$configs as \$config) {
    echo 'ID: ' . \$config->id . ' - نام: ' . \$config->name . ' - وضعیت: ' . \$config->status . ' - در حال اجرا: ' . (\$config->is_running ? 'بله' : 'خیر') . PHP_EOL;
}
"

# 6. تست تنظیمات کانفیگ
echo "🔧 بررسی تنظیمات کانفیگ:"
php artisan tinker --execute="
\$config = App\Models\Config::first();
if(\$config) {
    echo 'API Settings: ' . json_encode(\$config->getApiSettings(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
"
