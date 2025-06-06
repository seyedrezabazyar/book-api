@extends('layouts.app')

@section('title', 'ایجاد کانفیگ جدید')

@section('content')
    <div class="container mx-auto px-4 py-6">
        <div class="mb-6">
            <div class="flex items-center mb-4">
                <a href="{{ route('configs.index') }}" class="text-gray-600 hover:text-gray-800 ml-4" title="بازگشت">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">ایجاد کانفیگ جدید</h1>
                    <p class="text-gray-600">کانفیگ جدید برای دریافت اطلاعات از API</p>
                </div>
            </div>
        </div>

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

        <div class="bg-white rounded-lg shadow">
            <form method="POST" action="{{ route('configs.store') }}" class="space-y-6 p-6">
                @csrf

                <!-- اطلاعات کلی -->
                <div class="border-b border-gray-200 pb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">اطلاعات کلی</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
                                نام کانفیگ <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="name" name="name" value="{{ old('name') }}" required maxlength="255"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('name') border-red-500 @enderror"
                                   placeholder="نام منحصر به فرد برای کانفیگ">
                            @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
                                وضعیت <span class="text-red-500">*</span>
                            </label>
                            <select id="status" name="status" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('status') border-red-500 @enderror">
                                <option value="draft" {{ old('status', 'draft') === 'draft' ? 'selected' : '' }}>پیش‌نویس</option>
                                <option value="active" {{ old('status') === 'active' ? 'selected' : '' }}>فعال</option>
                                <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>غیرفعال</option>
                            </select>
                            @error('status')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div class="md:col-span-2">
                            <label for="base_url" class="block text-sm font-medium text-gray-700 mb-2">
                                آدرس پایه API <span class="text-red-500">*</span>
                            </label>
                            <input type="url" id="base_url" name="base_url" value="{{ old('base_url') }}" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('base_url') border-red-500 @enderror"
                                   placeholder="https://example.com">
                            @error('base_url')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div class="md:col-span-2">
                            <label for="description" class="block text-sm font-medium text-gray-700 mb-2">توضیحات</label>
                            <textarea id="description" name="description" rows="3" maxlength="1000"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('description') border-red-500 @enderror"
                                      placeholder="توضیحات مختصری درباره این کانفیگ...">{{ old('description') }}</textarea>
                            @error('description')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>

                <!-- تنظیمات اجرا -->
                <div class="border-b border-gray-200 pb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات اجرا</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div>
                            <label for="timeout" class="block text-sm font-medium text-gray-700 mb-2">
                                Timeout (ثانیه) <span class="text-red-500">*</span>
                            </label>
                            <input type="number" id="timeout" name="timeout" value="{{ old('timeout', 30) }}"
                                   required min="5" max="300"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('timeout') border-red-500 @enderror">
                            @error('timeout')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="delay_seconds" class="block text-sm font-medium text-gray-700 mb-2">
                                تاخیر درخواست (ثانیه) <span class="text-red-500">*</span>
                            </label>
                            <input type="number" id="delay_seconds" name="delay_seconds" value="{{ old('delay_seconds', 5) }}"
                                   required min="1" max="3600"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('delay_seconds') border-red-500 @enderror">
                            <p class="text-xs text-gray-500 mt-1">تاخیر بین هر درخواست</p>
                            @error('delay_seconds')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="page_delay" class="block text-sm font-medium text-gray-700 mb-2">
                                تاخیر صفحه (ثانیه) <span class="text-red-500">*</span>
                            </label>
                            <input type="number" id="page_delay" name="page_delay" value="{{ old('page_delay', 5) }}"
                                   required min="1" max="60"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('page_delay') border-red-500 @enderror">
                            <p class="text-xs text-gray-500 mt-1">تاخیر بین هر صفحه</p>
                            @error('page_delay')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="records_per_run" class="block text-sm font-medium text-gray-700 mb-2">
                                رکورد در هر اجرا <span class="text-red-500">*</span>
                            </label>
                            <input type="number" id="records_per_run" name="records_per_run" value="{{ old('records_per_run', 10) }}"
                                   required min="1" max="100"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('records_per_run') border-red-500 @enderror">
                            @error('records_per_run')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="crawl_mode" class="block text-sm font-medium text-gray-700 mb-2">
                                حالت کرال <span class="text-red-500">*</span>
                            </label>
                            <select id="crawl_mode" name="crawl_mode" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('crawl_mode') border-red-500 @enderror">
                                <option value="continue" {{ old('crawl_mode', 'continue') === 'continue' ? 'selected' : '' }}>ادامه از آخرین صفحه</option>
                                <option value="restart" {{ old('crawl_mode') === 'restart' ? 'selected' : '' }}>شروع مجدد از ابتدا</option>
                                <option value="update" {{ old('crawl_mode') === 'update' ? 'selected' : '' }}>به‌روزرسانی صفحات قبلی</option>
                            </select>
                            @error('crawl_mode')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="start_page" class="block text-sm font-medium text-gray-700 mb-2">صفحه شروع</label>
                            <input type="number" id="start_page" name="start_page" value="{{ old('start_page', 1) }}"
                                   min="1"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('start_page') border-red-500 @enderror">
                            <p class="text-xs text-gray-500 mt-1">صفحه‌ای که کرال از آن شروع شود</p>
                            @error('start_page')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div class="md:col-span-2 lg:col-span-3">
                            <label for="user_agent" class="block text-sm font-medium text-gray-700 mb-2">User Agent</label>
                            <input type="text" id="user_agent" name="user_agent"
                                   value="{{ old('user_agent', 'Mozilla/5.0 (compatible; ScraperBot/1.0)') }}"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('user_agent') border-red-500 @enderror">
                            @error('user_agent')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div class="md:col-span-2 lg:col-span-3 space-y-3">
                            <div class="flex items-center">
                                <input type="checkbox" id="verify_ssl" name="verify_ssl" value="1" {{ old('verify_ssl', '1') ? 'checked' : '' }}
                                class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                <label for="verify_ssl" class="mr-2 block text-sm text-gray-900">تأیید گواهی SSL (توصیه می‌شود)</label>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="follow_redirects" name="follow_redirects" value="1" {{ old('follow_redirects', '1') ? 'checked' : '' }}
                                class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                <label for="follow_redirects" class="mr-2 block text-sm text-gray-900">پیگیری ریدایرکت‌ها</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- تنظیمات API -->
                <div class="border-b border-gray-200 pb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات API</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="md:col-span-2">
                            <label for="api_endpoint" class="block text-sm font-medium text-gray-700 mb-2">
                                Endpoint <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="api_endpoint" name="api_endpoint" value="{{ old('api_endpoint') }}" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('api_endpoint') border-red-500 @enderror"
                                   placeholder="/api/books">
                            @error('api_endpoint')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="api_method" class="block text-sm font-medium text-gray-700 mb-2">متد HTTP</label>
                            <select id="api_method" name="api_method"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('api_method') border-red-500 @enderror">
                                <option value="GET" {{ old('api_method', 'GET') === 'GET' ? 'selected' : '' }}>GET</option>
                                <option value="POST" {{ old('api_method') === 'POST' ? 'selected' : '' }}>POST</option>
                            </select>
                            @error('api_method')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="auth_type" class="block text-sm font-medium text-gray-700 mb-2">احراز هویت</label>
                            <select id="auth_type" name="auth_type" onchange="toggleAuthToken()"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('auth_type') border-red-500 @enderror">
                                <option value="none" {{ old('auth_type', 'none') === 'none' ? 'selected' : '' }}>بدون احراز</option>
                                <option value="bearer" {{ old('auth_type') === 'bearer' ? 'selected' : '' }}>Bearer Token</option>
                                <option value="basic" {{ old('auth_type') === 'basic' ? 'selected' : '' }}>Basic Auth</option>
                            </select>
                            @error('auth_type')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div id="auth-token-field" class="md:col-span-2 {{ old('auth_type', 'none') === 'none' ? 'hidden' : '' }}">
                            <label for="auth_token" class="block text-sm font-medium text-gray-700 mb-2">توکن احراز هویت</label>
                            <input type="text" id="auth_token" name="auth_token" value="{{ old('auth_token') }}"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 @error('auth_token') border-red-500 @enderror"
                                   placeholder="توکن یا username:password">
                            <p class="text-xs text-gray-500 mt-1">برای Bearer: فقط توکن | برای Basic: username:password</p>
                            @error('auth_token')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <!-- نقشه‌برداری فیلدها -->
                    <div class="mt-6">
                        <h3 class="text-md font-medium text-gray-900 mb-4">نقشه‌برداری فیلدهای API</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @foreach($bookFields as $field => $label)
                                <div>
                                    <label for="api_field_{{ $field }}" class="block text-sm font-medium text-gray-700 mb-1">{{ $label }}</label>
                                    <input type="text" id="api_field_{{ $field }}" name="api_field_{{ $field }}"
                                           value="{{ old('api_field_' . $field) }}"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                                           placeholder="نام فیلد در پاسخ API">
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-4 p-3 bg-blue-50 rounded-lg">
                            <p class="text-sm text-blue-800">
                                <strong>راهنما:</strong> برای فیلدهای nested از نقطه استفاده کنید (مثال: data.book.title یا authors.0.name)
                                <br>اگر خالی بگذارید، از نقشه‌برداری پیش‌فرض استفاده می‌شود.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- دکمه‌های عمل -->
                <div class="flex items-center justify-end space-x-4 space-x-reverse pt-6">
                    <a href="{{ route('configs.index') }}"
                       class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        انصراف
                    </a>

                    <button type="submit"
                            class="px-6 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        ذخیره کانفیگ
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
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

        document.addEventListener('DOMContentLoaded', function() {
            toggleAuthToken();
        });
    </script>
@endsection
