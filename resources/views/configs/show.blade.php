@extends('layouts.app')

@section('title', 'نمایش کانفیگ')

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
                    <h1 class="text-2xl font-bold text-gray-800">{{ $config->name }}</h1>
                    <p class="text-gray-600">جزئیات کانفیگ</p>
                </div>
            </div>
        </div>

        {{-- اطلاعات کانفیگ --}}
        <div class="bg-white rounded-lg shadow">
            <div class="p-6">
                {{-- اطلاعات کلی --}}
                <div class="border-b border-gray-200 pb-6 mb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">اطلاعات کلی</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">نام کانفیگ</label>
                            <p class="text-sm text-gray-900">{{ $config->name }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">وضعیت</label>
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                            @if($config->status === 'active') bg-green-100 text-green-800
                            @elseif($config->status === 'inactive') bg-red-100 text-red-800
                            @else bg-yellow-100 text-yellow-800 @endif
                        ">
                            {{ $config->status_text }}
                        </span>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">توضیحات</label>
                            <p class="text-sm text-gray-900">{{ $config->description ?? 'بدون توضیحات' }}</p>
                        </div>
                    </div>
                </div>

                {{-- تنظیمات کانفیگ --}}
                <div class="border-b border-gray-200 pb-6 mb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات کانفیگ</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">آدرس پایه</label>
                            <p class="text-sm text-gray-900 break-all">{{ $config->config_data['base_url'] ?? '-' }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Timeout (ثانیه)</label>
                            <p class="text-sm text-gray-900">{{ $config->config_data['timeout'] ?? '-' }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">تعداد تلاش مجدد</label>
                            <p class="text-sm text-gray-900">{{ $config->config_data['max_retries'] ?? '-' }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">تاخیر (میلی‌ثانیه)</label>
                            <p class="text-sm text-gray-900">{{ $config->config_data['delay'] ?? '-' }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">User Agent</label>
                            <p class="text-sm text-gray-900 break-all">{{ $config->config_data['settings']['user_agent'] ?? '-' }}</p>
                        </div>
                    </div>
                </div>

                {{-- تنظیمات پیشرفته --}}
                <div class="border-b border-gray-200 pb-6 mb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات پیشرفته</h2>

                    <div class="space-y-4">
                        <div class="flex items-center">
                            <span class="text-sm font-medium text-gray-700 ml-3">تأیید گواهی SSL:</span>
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                            @if($config->config_data['settings']['verify_ssl'] ?? false) bg-green-100 text-green-800 @else bg-red-100 text-red-800 @endif
                        ">
                            {{ ($config->config_data['settings']['verify_ssl'] ?? false) ? 'فعال' : 'غیرفعال' }}
                        </span>
                        </div>

                        <div class="flex items-center">
                            <span class="text-sm font-medium text-gray-700 ml-3">پیگیری ریدایرکت‌ها:</span>
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                            @if($config->config_data['settings']['follow_redirects'] ?? false) bg-green-100 text-green-800 @else bg-red-100 text-red-800 @endif
                        ">
                            {{ ($config->config_data['settings']['follow_redirects'] ?? false) ? 'فعال' : 'غیرفعال' }}
                        </span>
                        </div>
                    </div>
                </div>

                {{-- اطلاعات تاریخی --}}
                <div>
                    <h2 class="text-lg font-medium text-gray-900 mb-4">اطلاعات تاریخی</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">تاریخ ایجاد</label>
                            <p class="text-sm text-gray-900">{{ $config->created_at->format('Y/m/d H:i') }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">آخرین به‌روزرسانی</label>
                            <p class="text-sm text-gray-900">{{ $config->updated_at->format('Y/m/d H:i') }}</p>
                        </div>
                    </div>
                </div>

                {{-- دکمه‌های عمل --}}
                <div class="flex items-center justify-end space-x-4 space-x-reverse pt-6 border-t border-gray-200 mt-6">
                    <a
                        href="{{ route('configs.index') }}"
                        class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                    >
                        بازگشت
                    </a>

                    <a
                        href="{{ route('configs.edit', $config) }}"
                        class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                    >
                        ویرایش کانفیگ
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
