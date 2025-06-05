@extends('layouts.app')

@section('title', 'ایجاد کانفیگ جدید')

@section('content')
    <div class="container mx-auto px-4 py-6">
        {{-- هدر صفحه --}}
        <div class="mb-6">
            <div class="flex items-center mb-4">
                <a
                    href="{{ route('configs.index') }}"
                    class="text-gray-600 hover:text-gray-800 ml-4"
                    title="بازگشت به لیست"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">ایجاد کانفیگ جدید</h1>
                    <p class="text-gray-600">کانفیگ جدید برای دریافت اطلاعات از منابع خارجی ایجاد کنید</p>
                </div>
            </div>
        </div>

        {{-- نمایش خطاهای اعتبارسنجی --}}
        @if($errors->any())
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                <div class="font-medium">خطاهای زیر را بررسی کنید:</div>
                <ul class="mt-2 list-disc list-inside text-sm">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- فرم ایجاد کانفیگ --}}
        <div class="bg-white rounded-lg shadow">
            <form method="POST" action="{{ route('configs.store') }}" class="space-y-6 p-6" id="configForm">
                @csrf

                {{-- بخش اطلاعات کلی --}}
                <div class="border-b border-gray-200 pb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">اطلاعات کلی</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        {{-- نام کانفیگ --}}
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
                                placeholder="نام منحصر به فرد برای کانفیگ وارد کنید"
                            >
                            @error('name')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- نوع منبع داده --}}
                        <div>
                            <label for="data_source_type" class="block text-sm font-medium text-gray-700 mb-2">
                                نوع منبع داده <span class="text-red-500">*</span>
                            </label>
                            <select
                                id="data_source_type"
                                name="data_source_type"
                                required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('data_source_type') border-red-500 @enderror"
                                onchange="toggleSourceTypeFields()"
                            >
                                <option value="">انتخاب کنید</option>
                                @foreach($dataSourceTypes as $value => $label)
                                    <option
                                        value="{{ $value }}"
                                        {{ old('data_source_type') === $value ? 'selected' : '' }}
                                    >
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            @error('data_source_type')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- وضعیت --}}
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
                                @foreach(\App\Models\Config::getStatuses() as $value => $label)
                                    <option
                                        value="{{ $value }}"
                                        {{ old('status', 'draft') === $value ? 'selected' : '' }}
                                    >
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            @error('status')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- آدرس پایه --}}
                        <div>
                            <label for="base_url" class="block text-sm font-medium text-gray-700 mb-2">
                                آدرس پایه سایت <span class="text-red-500">*</span>
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
                    </div>

                    {{-- توضیحات --}}
                    <div class="mt-6">
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                            توضیحات
                        </label>
                        <textarea
                            id="description"
                            name="description"
                            rows="3"
                            maxlength="1000"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('description') border-red-500 @enderror"
                            placeholder="توضیحات مختصری درباره این کانفیگ بنویسید..."
                        >{{ old('description') }}</textarea>
                        @error('description')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                {{-- بخش تنظیمات اتصال --}}
                <div class="border-b border-gray-200 pb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات اتصال</h2>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        {{-- تایم‌اوت --}}
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
                                min="1"
                                max="300"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('timeout') border-red-500 @enderror"
                            >
                            @error('timeout')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- تعداد تلاش مجدد --}}
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

                        {{-- تاخیر --}}
                        <div>
                            <label for="delay" class="block text-sm font-medium text-gray-700 mb-2">
                                تاخیر (میلی‌ثانیه) <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="number"
                                id="delay"
                                name="delay"
                                value="{{ old('delay', 1000) }}"
                                required
                                min="0"
                                max="10000"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('delay') border-red-500 @enderror"
                            >
                            @error('delay')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    {{-- User Agent --}}
                    <div class="mt-6">
                        <label for="user_agent" class="block text-sm font-medium text-gray-700 mb-2">
                            User Agent
                        </label>
                        <input
                            type="text"
                            id="user_agent"
                            name="user_agent"
                            value="{{ old('user_agent', 'Mozilla/5.0 (compatible; LaravelBot/1.0)') }}"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('user_agent') border-red-500 @enderror"
                        >
                        @error('user_agent')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- تنظیمات امنیتی --}}
                    <div class="mt-6 space-y-4">
                        <div class="flex items-center">
                            <input
                                type="checkbox"
                                id="verify_ssl"
                                name="verify_ssl"
                                value="1"
                                {{ old('verify_ssl', true) ? 'checked' : '' }}
                                class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                            >
                            <label for="verify_ssl" class="mr-2 block text-sm text-gray-900">
                                تأیید گواهی SSL
                            </label>
                        </div>

                        <div class="flex items-center">
                            <input
                                type="checkbox"
                                id="follow_redirects"
                                name="follow_redirects"
                                value="1"
                                {{ old('follow_redirects', true) ? 'checked' : '' }}
                                class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                            >
                            <label for="follow_redirects" class="mr-2 block text-sm text-gray-900">
                                پیگیری ریدایرکت‌ها
                            </label>
                        </div>
                    </div>
                </div>

                {{-- بخش تنظیمات API --}}
                <div id="api-settings" class="border-b border-gray-200 pb-6" style="display: none;">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات API</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        {{-- آدرس Endpoint --}}
                        <div class="md:col-span-2">
                            <label for="api_endpoint" class="block text-sm font-medium text-gray-700 mb-2">
                                آدرس Endpoint <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="api_endpoint"
                                name="api_endpoint"
                                value="{{ old('api_endpoint') }}"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                placeholder="/api/books"
                            >
                        </div>

                        {{-- متد HTTP --}}
                        <div>
                            <label for="api_method" class="block text-sm font-medium text-gray-700 mb-2">
                                متد HTTP <span class="text-red-500">*</span>
                            </label>
                            <select
                                id="api_method"
                                name="api_method"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            >
                                <option value="GET" {{ old('api_method', 'GET') === 'GET' ? 'selected' : '' }}>GET</option>
                                <option value="POST" {{ old('api_method') === 'POST' ? 'selected' : '' }}>POST</option>
                            </select>
                        </div>

                        {{-- نوع احراز هویت --}}
                        <div>
                            <label for="auth_type" class="block text-sm font-medium text-gray-700 mb-2">
                                نوع احراز هویت <span class="text-red-500">*</span>
                            </label>
                            <select
                                id="auth_type"
                                name="auth_type"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                onchange="toggleAuthToken()"
                            >
                                <option value="none" {{ old('auth_type', 'none') === 'none' ? 'selected' : '' }}>بدون احراز هویت</option>
                                <option value="bearer" {{ old('auth_type') === 'bearer' ? 'selected' : '' }}>Bearer Token</option>
                                <option value="basic" {{ old('auth_type') === 'basic' ? 'selected' : '' }}>Basic Auth</option>
                            </select>
                        </div>

                        {{-- توکن احراز هویت --}}
                        <div id="auth-token-field" class="md:col-span-2" style="display: none;">
                            <label for="auth_token" class="block text-sm font-medium text-gray-700 mb-2">
                                توکن احراز هویت <span class="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="auth_token"
                                name="auth_token"
                                value="{{ old('auth_token') }}"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                placeholder="توکن یا اطلاعات احراز هویت"
                            >
                        </div>
                    </div>

                    {{-- نقشه‌برداری فیلدهای API --}}
                    <div class="mt-6">
                        <h3 class="text-md font-medium text-gray-900 mb-4">نقشه‌برداری فیلدهای API</h3>
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <p class="text-sm text-gray-600 mb-4">
                                مشخص کنید هر فیلد کتاب از کدام فیلد پاسخ API دریافت شود:
                            </p>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                @foreach($bookFields as $field => $label)
                                    <div>
                                        <label for="api_field_{{ $field }}" class="block text-sm font-medium text-gray-700 mb-1">
                                            {{ $label }}
                                        </label>
                                        <input
                                            type="text"
                                            id="api_field_{{ $field }}"
                                            name="api_field_{{ $field }}"
                                            value="{{ old("api_field_{$field}") }}"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm"
                                            placeholder="نام فیلد در پاسخ API"
                                        >
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                {{-- بخش تنظیمات Crawler --}}
                <div id="crawler-settings" class="border-b border-gray-200 pb-6" style="display: none;">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات وب کراولر</h2>

                    {{-- نقشه‌برداری سلکتورهای Crawler --}}
                    <div class="mt-6">
                        <h3 class="text-md font-medium text-gray-900 mb-4">سلکتورهای CSS</h3>
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <p class="text-sm text-gray-600 mb-4">
                                مشخص کنید هر فیلد کتاب از کدام سلکتور CSS استخراج شود:
                            </p>
                            <div class="grid grid-cols-1 gap-4">
                                @foreach($bookFields as $field => $label)
                                    <div>
                                        <label for="crawler_selector_{{ $field }}" class="block text-sm font-medium text-gray-700 mb-1">
                                            {{ $label }}
                                        </label>
                                        <input
                                            type="text"
                                            id="crawler_selector_{{ $field }}"
                                            name="crawler_selector_{{ $field }}"
                                            value="{{ old("crawler_selector_{$field}") }}"
                                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm"
                                            placeholder="سلکتور CSS (مثال: .book-title, #title)"
                                        >
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- تنظیمات صفحه‌بندی --}}
                    <div class="mt-6">
                        <h3 class="text-md font-medium text-gray-900 mb-4">تنظیمات صفحه‌بندی</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="flex items-center">
                                <input
                                    type="checkbox"
                                    id="pagination_enabled"
                                    name="pagination_enabled"
                                    value="1"
                                    {{ old('pagination_enabled') ? 'checked' : '' }}
                                    class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                                    onchange="togglePaginationFields()"
                                >
                                <label for="pagination_enabled" class="mr-2 block text-sm text-gray-900">
                                    فعال‌سازی صفحه‌بندی
                                </label>
                            </div>
                        </div>

                        <div id="pagination-fields" style="display: none;">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-4">
                                <div>
                                    <label for="pagination_selector" class="block text-sm font-medium text-gray-700 mb-2">
                                        سلکتور دکمه صفحه بعد
                                    </label>
                                    <input
                                        type="text"
                                        id="pagination_selector"
                                        name="pagination_selector"
                                        value="{{ old('pagination_selector') }}"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                        placeholder=".next-page, .pagination-next"
                                    >
                                </div>

                                <div>
                                    <label for="pagination_max_pages" class="block text-sm font-medium text-gray-700 mb-2">
                                        حداکثر تعداد صفحات
                                    </label>
                                    <input
                                        type="number"
                                        id="pagination_max_pages"
                                        name="pagination_max_pages"
                                        value="{{ old('pagination_max_pages', 10) }}"
                                        min="1"
                                        max="100"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    >
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- تنظیمات پیشرفته Crawler --}}
                    <div class="mt-6">
                        <h3 class="text-md font-medium text-gray-900 mb-4">تنظیمات پیشرفته</h3>
                        <div class="space-y-4">
                            <div>
                                <label for="wait_for_element" class="block text-sm font-medium text-gray-700 mb-2">
                                    انتظار برای عنصر
                                </label>
                                <input
                                    type="text"
                                    id="wait_for_element"
                                    name="wait_for_element"
                                    value="{{ old('wait_for_element') }}"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    placeholder="سلکتور عنصری که باید منتظر بارگذاری آن باشد"
                                >
                            </div>

                            <div class="flex items-center">
                                <input
                                    type="checkbox"
                                    id="javascript_enabled"
                                    name="javascript_enabled"
                                    value="1"
                                    {{ old('javascript_enabled') ? 'checked' : '' }}
                                    class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                                >
                                <label for="javascript_enabled" class="mr-2 block text-sm text-gray-900">
                                    فعال‌سازی JavaScript
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- دکمه‌های عمل --}}
                <div class="flex items-center justify-end space-x-4 space-x-reverse pt-6 border-t border-gray-200">
                    <a
                        href="{{ route('configs.index') }}"
                        class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                    >
                        انصراف
                    </a>

                    <button
                        type="submit"
                        class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                    >
                        ذخیره کانفیگ
                    </button>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
        <script>
            // تابع تغییر نوع منبع داده
            function toggleSourceTypeFields() {
                const sourceType = document.getElementById('data_source_type').value;
                const apiSettings = document.getElementById('api-settings');
                const crawlerSettings = document.getElementById('crawler-settings');

                // مخفی کردن همه بخش‌ها
                apiSettings.style.display = 'none';
                crawlerSettings.style.display = 'none';

                // نمایش بخش مناسب
                if (sourceType === 'api') {
                    apiSettings.style.display = 'block';
                    // فعال‌سازی اعتبارسنجی فیلدهای API
                    setFieldsRequired(['api_endpoint', 'api_method', 'auth_type'], true);
                    // بررسی وضعیت احراز هویت
                    toggleAuthToken();
                } else if (sourceType === 'crawler') {
                    crawlerSettings.style.display = 'block';
                    // غیرفعال‌سازی اعتبارسنجی فیلدهای API
                    setFieldsRequired(['api_endpoint', 'api_method', 'auth_type', 'auth_token'], false);
                } else {
                    // غیرفعال‌سازی تمام اعتبارسنجی‌های اضافی
                    setFieldsRequired(['api_endpoint', 'api_method', 'auth_type', 'auth_token'], false);
                }
            }

            // تابع تغییر نوع احراز هویت
            function toggleAuthToken() {
                const authType = document.getElementById('auth_type').value;
                const authTokenField = document.getElementById('auth-token-field');
                const authTokenInput = document.getElementById('auth_token');

                if (authType === 'none') {
                    authTokenField.style.display = 'none';
                    authTokenInput.removeAttribute('required');
                    authTokenInput.value = ''; // پاک کردن مقدار
                } else {
                    authTokenField.style.display = 'block';
                    authTokenInput.setAttribute('required', 'required');
                }
            }

            // تابع تغییر تنظیمات صفحه‌بندی
            function togglePaginationFields() {
                const paginationEnabled = document.getElementById('pagination_enabled').checked;
                const paginationFields = document.getElementById('pagination-fields');

                if (paginationEnabled) {
                    paginationFields.style.display = 'block';
                } else {
                    paginationFields.style.display = 'none';
                }
            }

            // تابع کمکی برای تنظیم الزامی بودن فیلدها
            function setFieldsRequired(fieldIds, required) {
                fieldIds.forEach(function(fieldId) {
                    const field = document.getElementById(fieldId);
                    if (field) {
                        if (required) {
                            field.setAttribute('required', 'required');
                        } else {
                            field.removeAttribute('required');
                            field.value = ''; // پاک کردن مقدار
                        }
                    }
                });
            }

            // اجرای تنظیمات اولیه هنگام بارگذاری صفحه
            document.addEventListener('DOMContentLoaded', function() {
                toggleSourceTypeFields();
                togglePaginationFields();
            });

            // اعتبارسنجی فرمت URL در سمت کلاینت
            document.getElementById('base_url').addEventListener('blur', function() {
                const url = this.value;
                const urlPattern = /^https?:\/\/.+/i;

                if (url && !urlPattern.test(url)) {
                    this.setCustomValidity('لطفاً یک URL معتبر وارد کنید (باید با http:// یا https:// شروع شود)');
                } else {
                    this.setCustomValidity('');
                }
            });

            // اعتبارسنجی قبل از ارسال فرم
            document.getElementById('configForm').addEventListener('submit', function(e) {
                const sourceType = document.getElementById('data_source_type').value;

                if (sourceType === 'api') {
                    const authType = document.getElementById('auth_type').value;
                    const authToken = document.getElementById('auth_token').value;

                    // اگر نوع احراز هویت bearer یا basic است ولی توکن خالی است
                    if ((authType === 'bearer' || authType === 'basic') && !authToken.trim()) {
                        e.preventDefault();
                        alert('لطفاً توکن احراز هویت را وارد کنید.');
                        document.getElementById('auth_token').focus();
                        return false;
                    }

                    // اگر نوع احراز هویت none است، توکن را پاک کن
                    if (authType === 'none') {
                        document.getElementById('auth_token').value = '';
                    }
                }
            });
        </script>
    @endpush
@endsection
