#!/bin/bash

echo "🚨 EMERGENCY STOP - کشتن همه processes مرتبط با Laravel"

# 1. کشتن همه PHP processes مرتبط با artisan
echo "⏹️ کشتن PHP artisan processes..."
pkill -f "php.*artisan" 2>/dev/null || echo "هیچ PHP artisan process یافت نشد"

# 2. کشتن processes مرتبط با queue
echo "⏹️ کشتن queue processes..."
pkill -f "queue:work" 2>/dev/null || echo "هیچ queue process یافت نشد"
pkill -f "queue:listen" 2>/dev/null || echo "هیچ queue listen process یافت نشد"

# 3. کشتن processes طولانی‌مدت PHP
echo "⏹️ بررسی PHP processes طولانی‌مدت..."
ps aux | grep php | grep -v grep | awk '{print $2}' | while read pid; do
    # اگر process بیش از 5 دقیقه اجرا شده، آن را بکش
    runtime=$(ps -o etime= -p $pid 2>/dev/null | tr -d ' ')
    if [[ ! -z "$runtime" ]]; then
        echo "Process $pid runtime: $runtime"
        # اگر runtime شامل : است و بیش از 05:00 است
        if [[ $runtime == *":"* ]]; then
            minutes=$(echo $runtime | cut -d: -f1)
            if [[ $minutes -gt 5 ]]; then
                echo "کشتن process طولانی‌مدت: $pid"
                kill -9 $pid 2>/dev/null
            fi
        fi
    fi
done

# 4. پاک کردن Jobs و cache
echo "🧹 پاک کردن cache و Jobs..."
php artisan cache:clear 2>/dev/null || echo "خطا در cache:clear"
php artisan config:clear 2>/dev/null || echo "خطا در config:clear"
php artisan queue:clear 2>/dev/null || echo "خطا در queue:clear"

echo "✅ Emergency stop تمام شد!"
echo "🔍 processes باقی‌مانده:"
ps aux | grep -E "(php|artisan|queue)" | grep -v grep | head -5
