@extends('layouts.app')

@section('title', 'ایجاد کانفیگ جدید')

@section('content')
    <div class="container mx-auto px-4 py-6">
        <!-- هدر صفحه -->
        <div class="mb-6">
            <div class="flex items-center mb-4">
                <a href="{{ route('configs.index') }}"
                   class="text-gray-600 hover:text-gray-800 ml-4 transition-colors"
                   title="بازگشت به لیست">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">ایجاد کانفیگ جدید</h1>
                    <p class="text-gray-600">کانفیگ جدید برای اسکرپ اطلاعات</p>
                </div>
            </div>
        </div>

        <!-- نمایش خطاهای اعتبارسنجی -->
        @if($errors->any())
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                <div class="font-medium mb-2">خطاهای زیر را بررسی کنید:</div>
                <ul class="list-disc list-inside text-sm space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- فرم ایجاد کانفیگ -->
        <div class="bg-white rounded-lg shadow">
            <form method="POST" action="{{ route('configs.store') }}" class="space-y-6 p-6" id="configForm">
                @csrf

                <!-- بخش اطلاعات کلی -->
                <div class="border-b border-gray-200 pb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">اطلاعات کلی</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- نام کانفیگ -->
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
                                نام کانفیگ <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="name"
                                name="name"
                                value="{{ old('name') }}"
                                required
                                maxlength="255"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('name') border-red-500 @enderror"
                                placeholder="نام منحصر به فرد برای کانفیگ"
                            >
                            @error('name')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- نوع منبع داده -->
                        <div>
                            <label for="data_source_type" class="block text-sm font-medium text-gray-700 mb-2">
                                نوع منبع داده <span class="text-red-500">*</span>
                            </label>
                            <select
                                id="data_source_type"
                                name="data_source_type"
                                required
                                onchange="toggleSourceFields()"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('data_source_type') border-red-500 @enderror"
                            >
                                <option value="">انتخاب کنید</option>
                                <option value="api" {{ old('data_source_type') === 'api' ? 'selected' : '' }}>API</option>
                                <option value="crawler" {{ old('data_source_type') === 'crawler' ? 'selected' : '' }}>وب کراولر</option>
                            </select>
                            @error('data_source_type')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- وضعیت -->
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
                                وضعیت <span class="text-red-500">*</span>
                            </label>
                            <select
                                id="status"
                                name="status"
                                required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('status') border-red-500 @enderror"
                            >
                                <option value="draft" {{ old('status', 'draft') === 'draft' ? 'selected' : '' }}>پیش‌نویس</option>
                                <option value="active" {{ old('status') === 'active' ? 'selected' : '' }}>فعال</option>
                                <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>غیرفعال</option>
                            </select>
                            @error('status')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- آدرس پایه -->
                        <div>
                            <label for="base_url" class="block text-sm font-medium text-gray-700 mb-2">
                                آدرس پایه <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="url"
                                id="base_url"
                                name="base_url"
                                value="{{ old('base_url') }}"
                                required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('base_url') border-red-500 @enderror"
                                placeholder="https://example.com"
                            >
                            @error('base_url')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- توضیحات -->
                        <div class="md:col-span-2">
                            <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                                توضیحات
                            </label>
                            <textarea
                                id="description"
                                name="description"
                                rows="3"
                                maxlength="1000"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('description') border-red-500 @enderror"
                                placeholder="توضیحات مختصری درباره این کانفیگ..."
                            >{{ old('description') }}</textarea>
                            @error('description')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- بخش تنظیمات اتصال -->
                <div class="border-b border-gray-200 pb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات اتصال</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <!-- Timeout -->
                        <div>
                            <label for="timeout" class="block text-sm font-medium text-gray-700 mb-2">
                                Timeout (ثانیه) <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="number"
                                id="timeout"
                                name="timeout"
                                value="{{ old('timeout', 30) }}"
                                required
                                min="5"
                                max="300"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('timeout') border-red-500 @enderror"
                            >
                            @error('timeout')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- تعداد تلاش مجدد -->
                        <div>
                            <label for="max_retries" class="block text-sm font-medium text-gray-700 mb-2">
                                تعداد تلاش مجدد <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="number"
                                id="max_retries"
                                name="max_retries"
                                value="{{ old('max_retries', 3) }}"
                                required
                                min="0"
                                max="10"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('max_retries') border-red-500 @enderror"
                            >
                            @error('max_retries')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- تاخیر بین اجراها -->
                        <div>
                            <label for="delay_seconds" class="block text-sm font-medium text-gray-700 mb-2">
                                تاخیر (ثانیه) <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="number"
                                id="delay_seconds"
                                name="delay_seconds"
                                value="{{ old('delay_seconds', 5) }}"
                                required
                                min="1"
                                max="3600"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('delay_seconds') border-red-500 @enderror"
                            >
                            <p class="text-xs text-gray-500 mt-1">تاخیر بین هر دور اجرا</p>
                            @error('delay_seconds')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- رکورد در هر اجرا -->
                        <div>
                            <label for="records_per_run" class="block text-sm font-medium text-gray-700 mb-2">
                                رکورد در هر اجرا <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="number"
                                id="records_per_run"
                                name="records_per_run"
                                value="{{ old('records_per_run', 10) }}"
                                required
                                min="1"
                                max="100"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('records_per_run') border-red-500 @enderror"
                            >
                            <p class="text-xs text-gray-500 mt-1">تعداد رکورد در هر دور</p>
                            @error('records_per_run')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- سرعت محاسبه شده -->
                        <div class="md:col-span-2 lg:col-span-4">
                            <div id="speed-info" class="text-sm text-blue-600 bg-blue-50 rounded-lg p-3">
                                <span class="font-medium">سرعت تخمینی:</span>
                                <span id="speed-text">محاسبه خودکار...</span>
                            </div>
                        </div>

                        <!-- User Agent -->
                        <div class="md:col-span-2 lg:col-span-4">
                            <label for="user_agent" class="block text-sm font-medium text-gray-700 mb-2">
                                User Agent
                            </label>
                            <input
                                type="text"
                                id="user_agent"
                                name="user_agent"
                                value="{{ old('user_agent', 'Mozilla/5.0 (compatible; ScraperBot/1.0)') }}"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('user_agent') border-red-500 @enderror"
                            >
                            @error('user_agent')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- تنظیمات امنیتی -->
                        <div class="md:col-span-2 lg:col-span-4 space-y-3">
                            <div class="flex items-center">
                                <input
                                    type="checkbox"
                                    id="verify_ssl"
                                    name="verify_ssl"
                                    value="1"
                                    {{ old('verify_ssl', '1') ? 'checked' : '' }}
                                    class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                                >
                                <label for="verify_ssl" class="mr-2 block text-sm text-gray-900">
                                    تأیید گواهی SSL (توصیه می‌شود)
                                </label>
                            </div>
                            <div class="flex items-center">
                                <input
                                    type="checkbox"
                                    id="follow_redirects"
                                    name="follow_redirects"
                                    value="1"
                                    {{ old('follow_redirects', '1') ? 'checked' : '' }}
                                    class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                                >
                                <label for="follow_redirects" class="mr-2 block text-sm text-gray-900">
                                    پیگیری ریدایرکت‌ها
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- تنظیمات API -->
                <div id="api-settings" class="border-b border-gray-200 pb-6 hidden">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات API</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Endpoint -->
                        <div class="md:col-span-2">
                            <label for="api_endpoint" class="block text-sm font-medium text-gray-700 mb-2">
                                Endpoint <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="api_endpoint"
                                name="api_endpoint"
                                value="{{ old('api_endpoint') }}"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('api_endpoint') border-red-500 @enderror"
                                placeholder="/api/books"
                            >
                            @error('api_endpoint')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- متد HTTP -->
                        <div>
                            <label for="api_method" class="block text-sm font-medium text-gray-700 mb-2">
                                متد HTTP
                            </label>
                            <select
                                id="api_method"
                                name="api_method"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('api_method') border-red-500 @enderror"
                            >
                                <option value="GET" {{ old('api_method', 'GET') === 'GET' ? 'selected' : '' }}>GET</option>
                                <option value="POST" {{ old('api_method') === 'POST' ? 'selected' : '' }}>POST</option>
                            </select>
                            @error('api_method')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- احراز هویت -->
                        <div>
                            <label for="auth_type" class="block text-sm font-medium text-gray-700 mb-2">
                                احراز هویت
                            </label>
                            <select
                                id="auth_type"
                                name="auth_type"
                                onchange="toggleAuthToken()"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('auth_type') border-red-500 @enderror"
                            >
                                <option value="none" {{ old('auth_type', 'none') === 'none' ? 'selected' : '' }}>بدون احراز</option>
                                <option value="bearer" {{ old('auth_type') === 'bearer' ? 'selected' : '' }}>Bearer Token</option>
                                <option value="basic" {{ old('auth_type') === 'basic' ? 'selected' : '' }}>Basic Auth</option>
                            </select>
                            @error('auth_type')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- توکن احراز هویت -->
                        <div id="auth-token-field" class="md:col-span-2 {{ old('auth_type', 'none') === 'none' ? 'hidden' : '' }}">
                            <label for="auth_token" class="block text-sm font-medium text-gray-700 mb-2">
                                توکن احراز هویت
                            </label>
                            <input
                                type="text"
                                id="auth_token"
                                name="auth_token"
                                value="{{ old('auth_token') }}"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('auth_token') border-red-500 @enderror"
                                placeholder="توکن یا username:password"
                            >
                            <p class="text-xs text-gray-500 mt-1">
                                برای Bearer: فقط توکن | برای Basic: username:password
                            </p>
                            @error('auth_token')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <!-- نقشه‌برداری فیلدهای API -->
                    <div class="mt-6">
                        <h3 class="text-md font-medium text-gray-900 mb-4">نقشه‌برداری فیلدهای API</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @php
                                $apiFields = [
                                    'title' => 'عنوان',
                                    'description' => 'توضیحات',
                                    'author' => 'نویسنده',
                                    'category' => 'دسته‌بندی',
                                    'publisher' => 'ناشر',
                                    'isbn' => 'شابک',
                                    'publication_year' => 'سال انتشار',
                                    'pages_count' => 'تعداد صفحات',
                                    'language' => 'زبان',
                                    'format' => 'فرمت',
                                    'file_size' => 'حجم فایل',
                                    'image_url' => 'تصویر'
                                ];
                            @endphp

                            @foreach($apiFields as $field => $label)
                                <div>
                                    <label for="api_field_{{ $field }}" class="block text-sm font-medium text-gray-700 mb-1">
                                        {{ $label }}
                                    </label>
                                    <input
                                        type="text"
                                        id="api_field_{{ $field }}"
                                        name="api_field_{{ $field }}"
                                        value="{{ old('api_field_' . $field) }}"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm"
                                        placeholder="نام فیلد در پاسخ API (مثال: data.title)"
                                    >
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-4 p-3 bg-blue-50 rounded-lg">
                            <p class="text-sm text-blue-800">
                                <strong>راهنما:</strong> برای فیلدهای nested از نقطه استفاده کنید (مثال: data.book.title یا authors.0.name)
                            </p>
                        </div>
                    </div>
                </div>

                <!-- تنظیمات Crawler -->
                <div id="crawler-settings" class="border-b border-gray-200 pb-6 hidden">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات وب کراولر</h2>

                    <!-- الگوی URL -->
                    <div class="mb-6">
                        <label for="url_pattern" class="block text-sm font-medium text-gray-700 mb-2">
                            الگوی URL
                        </label>
                        <input
                            type="text"
                            id="url_pattern"
                            name="url_pattern"
                            value="{{ old('url_pattern') }}"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('url_pattern') border-red-500 @enderror"
                            placeholder="https://example.com/book/{id} یا خالی برای فقط URL پایه"
                        >
                        <p class="text-sm text-gray-500 mt-1">
                            از {id} برای شماره‌های متوالی استفاده کنید. خالی بگذارید تا فقط URL پایه استفاده شود.
                        </p>
                        @error('url_pattern')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- سلکتورهای CSS -->
                    <div>
                        <h3 class="text-md font-medium text-gray-900 mb-4">سلکتورهای CSS</h3>
                        <div class="grid grid-cols-1 gap-4">
                            @php
                                $crawlerFields = [
                                    'title' => 'عنوان',
                                    'description' => 'توضیحات',
                                    'author' => 'نویسنده',
                                    'category' => 'دسته‌بندی',
                                    'publisher' => 'ناشر',
                                    'isbn' => 'شابک',
                                    'publication_year' => 'سال انتشار',
                                    'pages_count' => 'تعداد صفحات',
                                    'language' => 'زبان',
                                    'format' => 'فرمت',
                                    'file_size' => 'حجم فایل',
                                    'image_url' => 'تصویر'
                                ];
                            @endphp

                            @foreach($crawlerFields as $field => $label)
                                <div>
                                    <label for="crawler_selector_{{ $field }}" class="block text-sm font-medium text-gray-700 mb-1">
                                        {{ $label }}
                                    </label>
                                    <input
                                        type="text"
                                        id="crawler_selector_{{ $field }}"
                                        name="crawler_selector_{{ $field }}"
                                        value="{{ old('crawler_selector_' . $field) }}"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm"
                                        placeholder="سلکتور CSS مثل .title یا #book-title"
                                    >
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-4 p-3 bg-yellow-50 rounded-lg">
                            <p class="text-sm text-yellow-800">
                                <strong>توجه:</strong> اگر سلکتورها را خالی بگذارید، سیستم به صورت خودکار تلاش خواهد کرد فیلدها را پیدا کند.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- دکمه‌های عمل -->
                <div class="flex items-center justify-end space-x-4 space-x-reverse pt-6">
                    <a
                        href="{{ route('configs.index') }}"
                        class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors"
                    >
                        انصراف
                    </a>

                    <button
                        type="submit"
                        class="px-6 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors"
                    >
                        <span class="submit-text">ذخیره کانفیگ</span>
                        <span class="submit-loading hidden">
                            <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            در حال ذخیره...
                        </span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Toggle بین تنظیمات API و Crawler
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

        // Toggle نمایش توکن احراز هویت
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

        // تنظیم required برای فیلدها
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

        // محاسبه و نمایش سرعت اسکرپ
        function updateSpeedInfo() {
            const delaySeconds = parseInt(document.querySelector('[name="delay_seconds"]').value) || 1;
            const recordsPerRun = parseInt(document.querySelector('[name="records_per_run"]').value) || 1;

            const recordsPerMinute = Math.round((60 / delaySeconds) * recordsPerRun);
            const recordsPerHour = recordsPerMinute * 60;

            const speedText = document.getElementById('speed-text');
            speedText.innerHTML = `
                <span class="font-bold">${recordsPerMinute}</span> رکورد در دقیقه،
                <span class="font-bold">${recordsPerHour.toLocaleString()}</span> رکورد در ساعت
            `;

            // هشدار برای سرعت بالا
            const speedInfo = document.getElementById('speed-info');
            if (recordsPerMinute > 60) {
                speedInfo.className = 'text-sm text-orange-600 bg-orange-50 rounded-lg p-3';
                speedText.innerHTML += ' <span class="text-xs">(سرعت بالا - ممکن است باعث مسدود شدن شوید)</span>';
            } else if (recordsPerMinute > 120) {
                speedInfo.className = 'text-sm text-red-600 bg-red-50 rounded-lg p-3';
                speedText.innerHTML += ' <span class="text-xs font-bold">(خطر مسدود شدن بالا!)</span>';
            } else {
                speedInfo.className = 'text-sm text-blue-600 bg-blue-50 rounded-lg p-3';
            }
        }

        // اعتبارسنجی URL در سمت کلاینت
        function validateUrl(input) {
            const url = input.value;
            const urlPattern = /^https?:\/\/.+/i;

            if (url && !urlPattern.test(url)) {
                input.setCustomValidity('لطفاً یک URL معتبر وارد کنید (باید با http:// یا https:// شروع شود)');
            } else {
                input.setCustomValidity('');
            }
        }

        // رویدادهای صفحه
        document.addEventListener('DOMContentLoaded', function() {
            // محاسبه سرعت اولیه
            updateSpeedInfo();

            // نمایش فیلدهای مناسب براساس مقدار قبلی
            toggleSourceFields();
            toggleAuthToken();

            // رویدادهای تغییر
            document.querySelector('[name="delay_seconds"]').addEventListener('input', updateSpeedInfo);
            document.querySelector('[name="records_per_run"]').addEventListener('input', updateSpeedInfo);
            document.querySelector('[name="base_url"]').addEventListener('blur', function() {
                validateUrl(this);
            });

            // رویداد ارسال فرم
            document.getElementById('configForm').addEventListener('submit', function(e) {
                const submitButton = this.querySelector('button[type="submit"]');
                const submitText = submitButton.querySelector('.submit-text');
                const submitLoading = submitButton.querySelector('.submit-loading');

                // نمایش loading
                submitButton.disabled = true;
                submitText.classList.add('hidden');
                submitLoading.classList.remove('hidden');

                // اعتبارسنجی نهایی
                const sourceType = document.querySelector('[name="data_source_type"]').value;

                if (sourceType === 'api') {
                    const endpoint = document.querySelector('[name="api_endpoint"]').value;
                    if (!endpoint.trim()) {
                        e.preventDefault();
                        alert('نقطه پایانی API الزامی است');

                        // بازگرداندن حالت عادی دکمه
                        submitButton.disabled = false;
                        submitText.classList.remove('hidden');
                        submitLoading.classList.add('hidden');
                        return;
                    }
                }

                // بازگرداندن حالت عادی بعد از 10 ثانیه (fallback)
                setTimeout(() => {
                    submitButton.disabled = false;
                    submitText.classList.remove('hidden');
                    submitLoading.classList.add('hidden');
                }, 10000);
            });

            // کمک برای autocomplete فیلدهای API
            const apiFieldInputs = document.querySelectorAll('[name^="api_field_"]');
            apiFieldInputs.forEach(input => {
                input.addEventListener('focus', function() {
                    const fieldName = this.name.replace('api_field_', '');

                    // پیشنهادات متداول براساس نوع فیلد
                    const suggestions = {
                        title: ['title', 'name', 'book_title', 'data.title'],
                        description: ['description', 'summary', 'content', 'data.description'],
                        author: ['author', 'authors', 'writer', 'data.author'],
                        category: ['category', 'genre', 'data.category.name'],
                        publisher: ['publisher', 'publication', 'data.publisher.name'],
                        isbn: ['isbn', 'book_isbn', 'data.isbn'],
                        publication_year: ['year', 'publication_year', 'publish_year', 'data.year'],
                        image_url: ['image', 'cover', 'thumbnail', 'data.image_url']
                    };

                    if (suggestions[fieldName] && !this.value) {
                        this.placeholder = 'مثال: ' + suggestions[fieldName][0];
                    }
                });
            });

            // کمک برای سلکتورهای CSS
            const selectorInputs = document.querySelectorAll('[name^="crawler_selector_"]');
            selectorInputs.forEach(input => {
                input.addEventListener('focus', function() {
                    const fieldName = this.name.replace('crawler_selector_', '');

                    // پیشنهادات متداول براساس نوع فیلد
                    const suggestions = {
                        title: ['h1', '.title', '#title', '.book-title'],
                        description: ['.description', '.summary', '.content'],
                        author: ['.author', '.writer', '.by-author'],
                        category: ['.category', '.genre', '.tag'],
                        publisher: ['.publisher', '.publication'],
                        image_url: ['.book-cover img', '.product-image img', 'img.cover']
                    };

                    if (suggestions[fieldName] && !this.value) {
                        this.placeholder = 'مثال: ' + suggestions[fieldName][0];
                    }
                });
            });

            // ذخیره خودکار در localStorage
            const form = document.getElementById('configForm');
            const inputs = form.querySelectorAll('input, select, textarea');

            inputs.forEach(input => {
                // بارگذاری مقدار ذخیره شده
                const savedValue = localStorage.getItem('config_draft_' + input.name);
                if (savedValue && !input.value) {
                    input.value = savedValue;
                    if (input.type === 'checkbox') {
                        input.checked = savedValue === 'on';
                    }
                }

                // ذخیره تغییرات
                input.addEventListener('input', function() {
                    if (this.value) {
                        localStorage.setItem('config_draft_' + this.name, this.value);
                    } else {
                        localStorage.removeItem('config_draft_' + this.name);
                    }
                });
            });

            // پاک کردن draft بعد از submit موفق
            form.addEventListener('submit', function() {
                setTimeout(() => {
                    inputs.forEach(input => {
                        localStorage.removeItem('config_draft_' + input.name);
                    });
                }, 1000);
            });
        });

        // کلیدهای میانبر
        document.addEventListener('keydown', function(e) {
            // Ctrl+S برای ذخیره
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                document.getElementById('configForm').submit();
            }

            // Tab برای جابجایی بین فیلدهای نقشه‌برداری
            if (e.key === 'Tab') {
                const focused = document.activeElement;
                if (focused && (focused.name.startsWith('api_field_') || focused.name.startsWith('crawler_selector_'))) {
                    // اتوکامل ساده
                    if (!focused.value && focused.placeholder.includes('مثال: ')) {
                        e.preventDefault();
                        focused.value = focused.placeholder.replace('مثال: ', '');
                        focused.dispatchEvent(new Event('input'));
                    }
                }
            }
        });
    </script>
@endsection
