<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ایجاد کانفیگ جدید</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
<div class="container mx-auto px-4 py-6">
    <!-- هدر -->
    <div class="mb-6">
        <div class="flex items-center mb-4">
            <a href="{{ route('configs.index') }}" class="text-gray-600 hover:text-gray-800 ml-4">
                ← بازگشت
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-800">ایجاد کانفیگ جدید</h1>
                <p class="text-gray-600">کانفیگ جدید برای اسکرپ اطلاعات</p>
            </div>
        </div>
    </div>

    <!-- فرم -->
    <div class="bg-white rounded-lg shadow p-6">
        <form method="POST" action="{{ route('configs.store') }}" class="space-y-6">
            @csrf

            <!-- اطلاعات کلی -->
            <div class="border-b pb-6">
                <h2 class="text-lg font-medium text-gray-900 mb-4">اطلاعات کلی</h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- نام -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            نام کانفیگ <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="name" required maxlength="255"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="نام منحصر به فرد">
                    </div>

                    <!-- نوع منبع -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            نوع منبع <span class="text-red-500">*</span>
                        </label>
                        <select name="data_source_type" required onchange="toggleSourceFields()"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">انتخاب کنید</option>
                            <option value="api">API</option>
                            <option value="crawler">وب کراولر</option>
                        </select>
                    </div>

                    <!-- وضعیت -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">وضعیت</label>
                        <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="draft">پیش‌نویس</option>
                            <option value="active">فعال</option>
                            <option value="inactive">غیرفعال</option>
                        </select>
                    </div>

                    <!-- آدرس پایه -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            آدرس پایه <span class="text-red-500">*</span>
                        </label>
                        <input type="url" name="base_url" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="https://example.com">
                    </div>

                    <!-- توضیحات -->
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">توضیحات</label>
                        <textarea name="description" rows="3" maxlength="1000"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                  placeholder="توضیحات مختصر..."></textarea>
                    </div>
                </div>
            </div>

            <!-- تنظیمات اتصال -->
            <div class="border-b pb-6">
                <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات اتصال</h2>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <!-- Timeout -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Timeout (ثانیه) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="timeout" value="30" required min="1" max="300"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- تلاش مجدد -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            تلاش مجدد <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="max_retries" value="3" required min="0" max="10"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- تاخیر (ثانیه) -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            تاخیر (ثانیه) <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="delay_seconds" value="1" required min="1" max="3600"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-xs text-gray-500 mt-1">تاخیر بین هر دور اجرا</p>
                    </div>

                    <!-- رکورد در هر اجرا -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            رکورد در هر اجرا <span class="text-red-500">*</span>
                        </label>
                        <input type="number" name="records_per_run" value="1" required min="1" max="100"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-xs text-gray-500 mt-1">تعداد رکورد در هر دور</p>
                    </div>

                    <!-- User Agent -->
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">User Agent</label>
                        <input type="text" name="user_agent"
                               value="Mozilla/5.0 (compatible; ScraperBot/1.0)"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <!-- تنظیمات امنیتی -->
                    <div class="md:col-span-2 space-y-2">
                        <div class="flex items-center">
                            <input type="checkbox" name="verify_ssl" value="1" checked
                                   class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <label class="mr-2 text-sm text-gray-900">تأیید گواهی SSL</label>
                        </div>
                        <div class="flex items-center">
                            <input type="checkbox" name="follow_redirects" value="1" checked
                                   class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <label class="mr-2 text-sm text-gray-900">پیگیری ریدایرکت‌ها</label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- تنظیمات API -->
            <div id="api-settings" class="border-b pb-6 hidden">
                <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات API</h2>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Endpoint -->
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Endpoint <span class="text-red-500">*</span>
                        </label>
                        <input type="text" name="api_endpoint"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="/api/books">
                    </div>

                    <!-- متد -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">متد HTTP</label>
                        <select name="api_method"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="GET">GET</option>
                            <option value="POST">POST</option>
                        </select>
                    </div>

                    <!-- احراز هویت -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">احراز هویت</label>
                        <select name="auth_type" onchange="toggleAuthToken()"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="none">بدون احراز</option>
                            <option value="bearer">Bearer Token</option>
                            <option value="basic">Basic Auth</option>
                        </select>
                    </div>

                    <!-- توکن احراز هویت -->
                    <div id="auth-token-field" class="md:col-span-2 hidden">
                        <label class="block text-sm font-medium text-gray-700 mb-2">توکن احراز هویت</label>
                        <input type="text" name="auth_token"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="توکن یا username:password">
                    </div>
                </div>

                <!-- نقشه‌برداری فیلدها -->
                <div class="mt-6">
                    <h3 class="text-md font-medium text-gray-900 mb-4">نقشه‌برداری فیلدهای API</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @foreach(['title' => 'عنوان', 'description' => 'توضیحات', 'author' => 'نویسنده', 'category' => 'دسته‌بندی', 'publisher' => 'ناشر', 'isbn' => 'شابک', 'image_url' => 'تصویر'] as $field => $label)
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">{{ $label }}</label>
                                <input type="text" name="crawler_selector_{{ $field }}"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                                       placeholder="سلکتور CSS مثل .title یا #book-title">
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- دکمه‌های عمل -->
            <div class="flex items-center justify-end space-x-4 space-x-reverse pt-6">
                <a href="{{ route('configs.index') }}"
                   class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                    انصراف
                </a>
                <button type="submit"
                        class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                    ذخیره کانفیگ
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleSourceFields() {
        const sourceType = document.querySelector('[name="data_source_type"]').value;
        const apiSettings = document.getElementById('api-settings');
        const crawlerSettings = document.getElementById('crawler-settings');

        // مخفی کردن همه
        apiSettings.classList.add('hidden');
        crawlerSettings.classList.add('hidden');

        // نمایش مناسب
        if (sourceType === 'api') {
            apiSettings.classList.remove('hidden');
            setFieldsRequired(['api_endpoint'], true);
            setFieldsRequired(['url_pattern'], false);
        } else if (sourceType === 'crawler') {
            crawlerSettings.classList.remove('hidden');
            setFieldsRequired(['api_endpoint'], false);
            setFieldsRequired(['url_pattern'], false);
        }
    }

    function toggleAuthToken() {
        const authType = document.querySelector('[name="auth_type"]').value;
        const authTokenField = document.getElementById('auth-token-field');
        const authTokenInput = document.querySelector('[name="auth_token"]');

        if (authType === 'none') {
            authTokenField.classList.add('hidden');
            authTokenInput.removeAttribute('required');
        } else {
            authTokenField.classList.remove('hidden');
            authTokenInput.setAttribute('required', 'required');
        }
    }

    function setFieldsRequired(fieldNames, required) {
        fieldNames.forEach(name => {
            const field = document.querySelector(`[name="${name}"]`);
            if (field) {
                if (required) {
                    field.setAttribute('required', 'required');
                } else {
                    field.removeAttribute('required');
                }
            }
        });
    }

    // محاسبه سرعت اسکرپ
    document.addEventListener('DOMContentLoaded', function() {
        const delayInput = document.querySelector('[name="delay_seconds"]');
        const recordsInput = document.querySelector('[name="records_per_run"]');

        function updateSpeedInfo() {
            const delay = parseInt(delayInput.value) || 1;
            const records = parseInt(recordsInput.value) || 1;
            const recordsPerMinute = Math.round((60 / delay) * records);

            // نمایش سرعت محاسبه شده
            let speedInfo = document.getElementById('speed-info');
            if (!speedInfo) {
                speedInfo = document.createElement('div');
                speedInfo.id = 'speed-info';
                speedInfo.className = 'text-sm text-blue-600 mt-2';
                recordsInput.parentNode.appendChild(speedInfo);
            }
            speedInfo.textContent = `≈ ${recordsPerMinute} رکورد در دقیقه`;
        }

        delayInput.addEventListener('input', updateSpeedInfo);
        recordsInput.addEventListener('input', updateSpeedInfo);
        updateSpeedInfo();
    });
</script>
</body>
</html>‌بندی', 'publisher' => 'ناشر', 'isbn' => 'شابک'] as $field => $label)
<div>
    <label class="block text-sm font-medium text-gray-700 mb-1">{{ $label }}</label>
    <input type="text" name="api_field_{{ $field }}"
           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
           placeholder="نام فیلد در API">
</div>
@endforeach
</div>
</div>
</div>

<!-- تنظیمات Crawler -->
<div id="crawler-settings" class="border-b pb-6 hidden">
    <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات وب کراولر</h2>

    <!-- الگوی URL -->
    <div class="mb-6">
        <label class="block text-sm font-medium text-gray-700 mb-2">الگوی URL</label>
        <input type="text" name="url_pattern"
               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
               placeholder="https://example.com/book/{id} یا خالی برای فقط URL پایه">
        <p class="text-sm text-gray-500 mt-1">{id} با شماره‌های متوالی جایگزین می‌شود</p>
    </div>

    <!-- سلکتورها -->
    <div>
        <h3 class="text-md font-medium text-gray-900 mb-4">سلکتورهای CSS</h3>
        <div class="grid grid-cols-1 gap-4">
@foreach(['title' => 'عنوان', 'description' => 'توضیحات', 'author' => 'نویسنده', 'category' => 'دسته
