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
                    <p class="text-gray-600">کانفیگ جدید برای سیستم ایجاد کنید</p>
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
            <form method="POST" action="{{ route('configs.store') }}" class="space-y-6 p-6">
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
                                <option value="">انتخاب کنید</option>
                                @foreach(\App\Models\Config::getStatuses() as $value => $label)
                                    <option
                                        value="{{ $value }}"
                                        {{ old('status') === $value ? 'selected' : '' }}
                                    >
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            @error('status')
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
                        <p class="mt-1 text-sm text-gray-500">حداکثر 1000 کاراکتر</p>
                    </div>
                </div>

                {{-- بخش تنظیمات کانفیگ --}}
                <div class="border-b border-gray-200 pb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات کانفیگ</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        {{-- آدرس پایه --}}
                        <div class="md:col-span-2">
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
                            <p class="mt-1 text-sm text-gray-500">بین 1 تا 300 ثانیه</p>
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
                            <p class="mt-1 text-sm text-gray-500">بین 0 تا 10</p>
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
                            <p class="mt-1 text-sm text-gray-500">بین 0 تا 10000 میلی‌ثانیه</p>
                        </div>

                        {{-- User Agent --}}
                        <div>
                            <label for="user_agent" class="block text-sm font-medium text-gray-700 mb-2">
                                User Agent
                            </label>
                            <input
                                type="text"
                                id="user_agent"
                                name="user_agent"
                                value="{{ old('user_agent', 'Mozilla/5.0 (compatible; SimpleBot/1.0)') }}"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent @error('user_agent') border-red-500 @enderror"
                            >
                            @error('user_agent')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                {{-- بخش تنظیمات پیشرفته --}}
                <div>
                    <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات پیشرفته</h2>

                    <div class="space-y-4">
                        {{-- تأیید SSL --}}
                        <div class="flex items-center">
                            <input
                                type="checkbox"
                                id="verify_ssl"
                                name="verify_ssl"
                                value="1"
                                {{ old('verify_ssl') ? 'checked' : '' }}
                                class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                            >
                            <label for="verify_ssl" class="mr-2 block text-sm text-gray-900">
                                تأیید گواهی SSL
                            </label>
                        </div>

                        {{-- پیگیری ریدایرکت‌ها --}}
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

            // محدودیت کاراکتر برای توضیحات
            const descriptionField = document.getElementById('description');
            const maxLength = 1000;

            descriptionField.addEventListener('input', function() {
                const currentLength = this.value.length;
                const remaining = maxLength - currentLength;

                // ایجاد یا به‌روزرسانی شمارنده کاراکتر
                let counter = document.getElementById('description-counter');
                if (!counter) {
                    counter = document.createElement('p');
                    counter.id = 'description-counter';
                    counter.className = 'mt-1 text-sm text-gray-500';
                    this.parentNode.appendChild(counter);
                }

                counter.textContent = `${remaining} کاراکتر باقی مانده`;

                if (remaining < 0) {
                    counter.className = 'mt-1 text-sm text-red-600';
                } else if (remaining < 100) {
                    counter.className = 'mt-1 text-sm text-yellow-600';
                } else {
                    counter.className = 'mt-1 text-sm text-gray-500';
                }
            });
        </script>
    @endpush
@endsection
