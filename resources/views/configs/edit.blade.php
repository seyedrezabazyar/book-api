@extends('layouts.app')
@section('title', 'ویرایش کانفیگ')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center gap-4">
            <a href="{{ route('configs.show', $config) }}" class="text-gray-600 hover:text-gray-800">←</a>
            <div>
                <h1 class="text-2xl font-semibold">ویرایش کانفیگ</h1>
                <p class="text-gray-600">{{ $config->name }}</p>
            </div>
        </div>

        @if($errors->any())
            <div class="bg-red-100 border border-red-400 text-red-700 p-4 rounded">
                <div class="font-medium mb-2">خطاهای زیر را بررسی کنید:</div>
                <ul class="list-disc list-inside space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="bg-white rounded shadow p-6">
            <form method="POST" action="{{ route('configs.update', $config) }}" class="space-y-6">
                @csrf @method('PUT')

                <!-- Basic Info -->
                <div class="border-b border-gray-200 pb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">اطلاعات کلی</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
                                نام کانفیگ <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="name" name="name" value="{{ old('name', $config->name) }}" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('name') border-red-500 @enderror">
                            @error('name')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
                                وضعیت <span class="text-red-500">*</span>
                            </label>
                            <select id="status" name="status" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('status') border-red-500 @enderror">
                                @foreach(\App\Models\Config::getStatuses() as $value => $label)
                                    <option value="{{ $value }}" {{ old('status', $config->status) === $value ? 'selected' : '' }}>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            @error('status')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div class="md:col-span-2">
                            <label for="base_url" class="block text-sm font-medium text-gray-700 mb-2">
                                آدرس پایه API <span class="text-red-500">*</span>
                            </label>
                            <input type="url" id="base_url" name="base_url" value="{{ old('base_url', $config->base_url) }}" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('base_url') border-red-500 @enderror">
                            @error('base_url')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div class="md:col-span-2">
                            <label for="description" class="block text-sm font-medium text-gray-700 mb-2">توضیحات</label>
                            <textarea id="description" name="description" rows="3" maxlength="1000"
                                      class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('description') border-red-500 @enderror"
                                      placeholder="توضیحات مختصری درباره این کانفیگ...">{{ old('description', $config->description) }}</textarea>
                            @error('description')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </div>

                <!-- Execution Settings -->
                <div class="border-b border-gray-200 pb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات اجرا</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div>
                            <label for="timeout" class="block text-sm font-medium text-gray-700 mb-2">
                                Timeout (ثانیه) <span class="text-red-500">*</span>
                            </label>
                            <input type="number" id="timeout" name="timeout" value="{{ old('timeout', $config->timeout) }}"
                                   required min="1" max="300"
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('timeout') border-red-500 @enderror">
                            @error('timeout')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="delay_seconds" class="block text-sm font-medium text-gray-700 mb-2">
                                تاخیر درخواست (ثانیه) <span class="text-red-500">*</span>
                            </label>
                            <input type="number" id="delay_seconds" name="delay_seconds" value="{{ old('delay_seconds', $config->delay_seconds) }}"
                                   required min="1" max="3600"
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('delay_seconds') border-red-500 @enderror">
                            <p class="text-xs text-gray-500 mt-1">تاخیر بین هر درخواست</p>
                            @error('delay_seconds')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="page_delay" class="block text-sm font-medium text-gray-700 mb-2">
                                تاخیر صفحه (ثانیه) <span class="text-red-500">*</span>
                            </label>
                            <input type="number" id="page_delay" name="page_delay" value="{{ old('page_delay', $config->page_delay) }}"
                                   required min="1" max="60"
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('page_delay') border-red-500 @enderror">
                            <p class="text-xs text-gray-500 mt-1">تاخیر بین هر صفحه</p>
                            @error('page_delay')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="records_per_run" class="block text-sm font-medium text-gray-700 mb-2">
                                رکورد در هر اجرا <span class="text-red-500">*</span>
                            </label>
                            <input type="number" id="records_per_run" name="records_per_run" value="{{ old('records_per_run', $config->records_per_run) }}"
                                   required min="1" max="100"
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('records_per_run') border-red-500 @enderror">
                            @error('records_per_run')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="crawl_mode" class="block text-sm font-medium text-gray-700 mb-2">
                                حالت کرال <span class="text-red-500">*</span>
                            </label>
                            <select id="crawl_mode" name="crawl_mode" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('crawl_mode') border-red-500 @enderror">
                                @foreach(\App\Models\Config::getCrawlModes() as $value => $label)
                                    <option value="{{ $value }}" {{ old('crawl_mode', $config->crawl_mode) === $value ? 'selected' : '' }}>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            @error('crawl_mode')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="start_page" class="block text-sm font-medium text-gray-700 mb-2">صفحه شروع</label>
                            <input type="number" id="start_page" name="start_page" value="{{ old('start_page', $config->start_page) }}"
                                   min="1"
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('start_page') border-red-500 @enderror">
                            <p class="text-xs text-gray-500 mt-1">خالی = صفحه فعلی</p>
                            @error('start_page')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        @php $generalSettings = $config->getGeneralSettings(); @endphp
                        <div class="md:col-span-2 lg:col-span-3">
                            <label for="user_agent" class="block text-sm font-medium text-gray-700 mb-2">User Agent</label>
                            <input type="text" id="user_agent" name="user_agent"
                                   value="{{ old('user_agent', $generalSettings['user_agent'] ?? 'Mozilla/5.0 (compatible; ScraperBot/1.0)') }}"
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('user_agent') border-red-500 @enderror">
                            @error('user_agent')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div class="md:col-span-2 lg:col-span-3 space-y-3">
                            <div class="flex items-center">
                                <input type="checkbox" id="verify_ssl" name="verify_ssl" value="1"
                                       {{ old('verify_ssl', $generalSettings['verify_ssl'] ?? true) ? 'checked' : '' }}
                                       class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                <label for="verify_ssl" class="mr-2 block text-sm text-gray-900">تأیید گواهی SSL</label>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="follow_redirects" name="follow_redirects" value="1"
                                       {{ old('follow_redirects', $generalSettings['follow_redirects'] ?? true) ? 'checked' : '' }}
                                       class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                <label for="follow_redirects" class="mr-2 block text-sm text-gray-900">پیگیری ریدایرکت‌ها</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- API Settings -->
                <div class="border-b border-gray-200 pb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات API</h2>
                    @php $apiSettings = $config->getApiSettings(); @endphp

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="md:col-span-2">
                            <label for="api_endpoint" class="block text-sm font-medium text-gray-700 mb-2">
                                Endpoint <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="api_endpoint" name="api_endpoint"
                                   value="{{ old('api_endpoint', $apiSettings['endpoint'] ?? '') }}" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('api_endpoint') border-red-500 @enderror"
                                   placeholder="/api/books">
                            @error('api_endpoint')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="api_method" class="block text-sm font-medium text-gray-700 mb-2">متد HTTP</label>
                            <select id="api_method" name="api_method"
                                    class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('api_method') border-red-500 @enderror">
                                <option value="GET" {{ old('api_method', $apiSettings['method'] ?? 'GET') === 'GET' ? 'selected' : '' }}>GET</option>
                                <option value="POST" {{ old('api_method', $apiSettings['method'] ?? 'GET') === 'POST' ? 'selected' : '' }}>POST</option>
                            </select>
                            @error('api_method')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div>
                            <label for="auth_type" class="block text-sm font-medium text-gray-700 mb-2">احراز هویت</label>
                            <select id="auth_type" name="auth_type" onchange="toggleAuthToken()"
                                    class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('auth_type') border-red-500 @enderror">
                                <option value="none" {{ old('auth_type', $apiSettings['auth_type'] ?? 'none') === 'none' ? 'selected' : '' }}>بدون احراز</option>
                                <option value="bearer" {{ old('auth_type', $apiSettings['auth_type'] ?? 'none') === 'bearer' ? 'selected' : '' }}>Bearer Token</option>
                                <option value="basic" {{ old('auth_type', $apiSettings['auth_type'] ?? 'none') === 'basic' ? 'selected' : '' }}>Basic Auth</option>
                            </select>
                            @error('auth_type')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>

                        <div id="auth-token-field" class="md:col-span-2 {{ old('auth_type', $apiSettings['auth_type'] ?? 'none') === 'none' ? 'hidden' : '' }}">
                            <label for="auth_token" class="block text-sm font-medium text-gray-700 mb-2">توکن احراز هویت</label>
                            <input type="text" id="auth_token" name="auth_token"
                                   value="{{ old('auth_token', $apiSettings['auth_token'] ?? '') }}"
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('auth_token') border-red-500 @enderror"
                                   placeholder="توکن یا username:password">
                            <p class="text-xs text-gray-500 mt-1">برای Bearer: فقط توکن | برای Basic: username:password</p>
                            @error('auth_token')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    <!-- Field Mapping -->
                    <div class="mt-6">
                        <h3 class="text-md font-medium text-gray-900 mb-4">نقشه‌برداری فیلدهای API</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @foreach($bookFields as $field => $label)
                                <div>
                                    <label for="api_field_{{ $field }}" class="block text-sm font-medium text-gray-700 mb-1">
                                        {{ $label }}
                                    </label>
                                    <input type="text" id="api_field_{{ $field }}" name="api_field_{{ $field }}"
                                           value="{{ old('api_field_' . $field, $apiSettings['field_mapping'][$field] ?? '') }}"
                                           class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 text-sm"
                                           placeholder="نام فیلد در پاسخ API">
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-4 p-3 bg-blue-50 rounded">
                            <p class="text-sm text-blue-800">
                                <strong>راهنما:</strong> برای فیلدهای nested از نقطه استفاده کنید (مثال: data.book.title یا authors.0.name)
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-end space-x-4 space-x-reverse pt-6">
                    <a href="{{ route('configs.show', $config) }}"
                       class="px-4 py-2 border border-gray-300 rounded text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                        انصراف
                    </a>
                    <button type="submit"
                            class="px-6 py-2 border border-transparent rounded shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                        ذخیره تغییرات
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
