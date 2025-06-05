#!/bin/bash

echo "🔍 شروع تشخیص مشکل بهبود یافته..."
echo "=================================="

# 1. بررسی وضعیت queue و jobs
echo "📊 بررسی وضعیت Queue:"
echo "----------------------"
php artisan queue:monitor 2>/dev/null || echo "خطا در queue:monitor"

echo ""
echo "⏳ Jobs در صف:"
php artisan tinker --execute="
echo 'Jobs در انتظار: ' . \Illuminate\Support\Facades\DB::table('jobs')->count() . PHP_EOL;
echo 'Failed jobs: ' . \Illuminate\Support\Facades\DB::table('failed_jobs')->count() . PHP_EOL;
"

# 2. بررسی آمار کانفیگ‌ها از دیتابیس
echo ""
echo "⚙️ آمار کانفیگ‌های موجود:"
echo "------------------------"
php artisan tinker --execute="
\$configs = App\Models\Config::all();
foreach(\$configs as \$config) {
    echo 'ID: ' . \$config->id . ' - نام: ' . \$config->name . PHP_EOL;
    echo '  وضعیت: ' . \$config->status . ' | در حال اجرا: ' . (\$config->is_running ? 'بله' : 'خیر') . PHP_EOL;
    echo '  کل پردازش شده: ' . number_format(\$config->total_processed) . PHP_EOL;
    echo '  موفق: ' . number_format(\$config->total_success) . PHP_EOL;
    echo '  خطا: ' . number_format(\$config->total_failed) . PHP_EOL;
    echo '  آخرین اجرا: ' . (\$config->last_run_at ? \$config->last_run_at->format('Y-m-d H:i:s') : 'هرگز') . PHP_EOL;
    echo '---' . PHP_EOL;
}
"

# 3. بررسی آخرین کتاب‌های ایجاد شده
echo ""
echo "📚 آخرین کتاب‌های ایجاد شده:"
echo "-----------------------------"
php artisan tinker --execute="
\$books = App\Models\Book::latest()->take(5)->get(['id', 'title', 'created_at']);
foreach(\$books as \$book) {
    echo 'ID: ' . \$book->id . ' | عنوان: ' . \Illuminate\Support\Str::limit(\$book->title, 40) . ' | تاریخ: ' . \$book->created_at->format('Y-m-d H:i:s') . PHP_EOL;
}
"

# 4. اجرای یک job pending (اگر وجود دارد)
echo ""
echo "⚡ اجرای job pending:"
echo "-------------------"
php artisan queue:work --once -v --timeout=60 2>/dev/null || echo "هیچ job در صف نیست یا خطا رخ داد"

# 5. تست کامند جدید
echo ""
echo "🧪 تست کامند debug:"
echo "-------------------"
php artisan config:debug 1 2>/dev/null || echo "خطا در اجرای کامند debug"

# 6. تست اجرای sync
echo ""
echo "⚡ تست اجرای sync:"
echo "-----------------"
echo "اجرای 3 رکورد تستی..."
php artisan config:run --id=1 --sync --limit=3 || echo "خطا در اجرای sync"

# 7. بررسی آمار بعد از تست
echo ""
echo "📊 آمار بعد از تست:"
echo "-------------------"
php artisan tinker --execute="
\$config = App\Models\Config::find(1);
if(\$config) {
    echo 'کل پردازش شده: ' . number_format(\$config->total_processed) . PHP_EOL;
    echo 'موفق: ' . number_format(\$config->total_success) . PHP_EOL;
    echo 'خطا: ' . number_format(\$config->total_failed) . PHP_EOL;
    echo 'آخرین اجرا: ' . (\$config->last_run_at ? \$config->last_run_at->format('Y-m-d H:i:s') : 'هرگز') . PHP_EOL;
} else {
    echo 'کانفیگ با ID 1 یافت نشد' . PHP_EOL;
}
"

# 8. بررسی cache
echo ""
echo "💾 بررسی Cache:"
echo "---------------"
php artisan tinker --execute="
\$stats = \Illuminate\Support\Facades\Cache::get('config_stats_1');
\$error = \Illuminate\Support\Facades\Cache::get('config_error_1');

if(\$stats) {
    echo 'آمار Cache موجود: کل=' . \$stats['total'] . ', موفق=' . \$stats['success'] . ', خطا=' . \$stats['failed'] . PHP_EOL;
} else {
    echo 'آمار در Cache یافت نشد' . PHP_EOL;
}

if(\$error) {
    echo 'خطا در Cache: ' . \$error['message'] . PHP_EOL;
} else {
    echo 'هیچ خطایی در Cache نیست' . PHP_EOL;
}
"

# 9. نمایش آخرین 20 خط لاگ
echo ""
echo "📝 آخرین لاگ‌ها:"
echo "----------------"
tail -20 storage/logs/laravel.log 2>/dev/null || echo "فایل لاگ یافت نشد"

echo ""
echo "✅ تشخیص مشکل تمام شد!"
echo "======================="
