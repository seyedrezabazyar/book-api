#!/bin/bash

# اسکریپت اصلاح import های DB در همه فایل‌ها

echo "🔧 شروع اصلاح import های DB..."

# لیست فایل‌هایی که باید اصلاح شوند
files=(
    "app/Models/ExecutionLog.php"
    "app/Models/BookSource.php"
    "app/Models/Config.php"
    "app/Http/Controllers/ConfigController.php"
    "app/Jobs/ProcessSinglePageJob.php"
    "app/Helpers/SourceIdManager.php"
    "app/Services/ApiDataService.php"
    "app/Services/QueueManagerService.php"
)

for file in "${files[@]}"; do
    if [ -f "$file" ]; then
        echo "🔧 اصلاح فایل: $file"

        # بررسی اینکه آیا قبلاً import دارد
        if ! grep -q "use Illuminate\Support\Facades\DB;" "$file"; then
            # یافتن خط namespace
            if grep -q "^namespace " "$file"; then
                # اضافه کردن import بعد از آخرین use statement یا بعد از namespace
                if grep -q "^use " "$file"; then
                    # اضافه کردن بعد از آخرین use
                    sed -i '/^use /a use Illuminate\Support\Facades\DB;' "$file"
                else
                    # اضافه کردن بعد از namespace
                    sed -i '/^namespace /a\\nuse Illuminate\Support\Facades\DB;' "$file"
                fi
                echo "   ✅ Import اضافه شد"
            else
                echo "   ⚠️ namespace یافت نشد"
            fi
        else
            echo "   ✅ Import قبلاً موجود است"
        fi
    else
        echo "   ❌ فایل یافت نشد: $file"
    fi
done

echo "🎉 اصلاح import ها تمام شد!"
