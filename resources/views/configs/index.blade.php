@extends('layouts.app')

@section('title', 'مدیریت کانفیگ‌ها')

@section('content')
    <div class="container mx-auto px-4 py-6">
        {{-- هدر صفحه --}}
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 mb-2">مدیریت کانفیگ‌ها</h1>
                <p class="text-gray-600">کانفیگ‌های دریافت اطلاعات را مدیریت و اجرا کنید</p>
            </div>

            <div class="flex flex-col sm:flex-row gap-3 mt-4 md:mt-0">
                {{-- جعبه جستجو --}}
                <form method="GET" action="#" class="flex">
                    <input
                        type="text"
                        name="search"
                        value="{{ $search }}"
                        placeholder="جستجو در کانفیگ‌ها..."
                        class="px-4 py-2 border border-gray-300 rounded-r-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    >
                    <button
                        type="submit"
                        class="px-4 py-2 bg-gray-600 text-white rounded-l-md hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </button>
                </form>

                {{-- دکمه اجرای همه --}}
                <form method="POST" action="{{ route('configs.run-all') }}" class="inline">
                    @csrf
                    <button
                        type="submit"
                        class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500"
                        onclick="return confirm('آیا مطمئن هستید که می‌خواهید همه کانفیگ‌های فعال را اجرا کنید؟')"
                        title="اجرای همه کانفیگ‌های فعال"
                    >
                        ▶️ اجرای همه
                    </button>
                </form>

                {{-- دکمه ایجاد کانفیگ جدید --}}
                <a
                    href="{{ route('configs.create') }}"
                    class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 text-center"
                >
                    + کانفیگ جدید
                </a>
            </div>
        </div>

        {{-- پیام‌های سیستم --}}
        @if(session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4" role="alert">
                <span class="block sm:inline">{{ session('success') }}</span>
            </div>
        @endif

        @if(session('warning'))
            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4" role="alert">
                <span class="block sm:inline">{{ session('warning') }}</span>
            </div>
        @endif

        @if(session('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4" role="alert">
                <span class="block sm:inline">{{ session('error') }}</span>
            </div>
        @endif

        {{-- جدول کانفیگ‌ها --}}
        <div class="bg-white rounded-lg shadow overflow-hidden">
            @if($configs->count() > 0)
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                نام کانفیگ
                            </th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                نوع منبع
                            </th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                وضعیت
                            </th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                آخرین اجرا
                            </th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                وضعیت اجرا
                            </th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                عملیات
                            </th>
                        </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($configs as $config)
                            @php
                                $lastRun = $config->config_data['last_run'] ?? null;
                                $isRunning = \Illuminate\Support\Facades\Cache::has("config_processing_{$config->id}");
                                $hasError = \Illuminate\Support\Facades\Cache::has("config_error_{$config->id}");
                            @endphp
                            <tr class="hover:bg-gray-50">
                                {{-- نام کانفیگ --}}
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">
                                                {{ $config->name }}
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                {{ Str::limit($config->description ?? 'بدون توضیحات', 40) }}
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                {{-- نوع منبع --}}
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                        @if($config->data_source_type === 'api') bg-blue-100 text-blue-800
                                        @else bg-purple-100 text-purple-800 @endif
                                    ">
                                        {{ $config->data_source_type_text }}
                                    </span>
                                </td>

                                {{-- وضعیت --}}
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                        @if($config->status === 'active') bg-green-100 text-green-800
                                        @elseif($config->status === 'inactive') bg-red-100 text-red-800
                                        @else bg-yellow-100 text-yellow-800 @endif
                                    ">
                                        {{ $config->status_text }}
                                    </span>
                                </td>

                                {{-- آخرین اجرا --}}
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    @if($lastRun)
                                        <div>{{ \Carbon\Carbon::parse($lastRun)->format('Y/m/d') }}</div>
                                        <div class="text-xs">{{ \Carbon\Carbon::parse($lastRun)->format('H:i') }}</div>
                                    @else
                                        <span class="text-gray-400">هرگز</span>
                                    @endif
                                </td>

                                {{-- وضعیت اجرا --}}
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if($isRunning)
                                        <span class="inline-flex items-center px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-800 rounded-full">
                                            <svg class="animate-spin -ml-1 mr-2 h-3 w-3 text-yellow-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            در حال اجرا
                                        </span>
                                    @elseif($hasError)
                                        <span class="inline-flex px-2 py-1 text-xs font-medium bg-red-100 text-red-800 rounded-full">
                                            ❌ خطا
                                        </span>
                                    @else
                                        <span class="inline-flex px-2 py-1 text-xs font-medium bg-gray-100 text-gray-800 rounded-full">
                                            آماده
                                        </span>
                                    @endif
                                </td>

                                {{-- عملیات --}}
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex items-center space-x-2 space-x-reverse">
                                        {{-- نمایش --}}
                                        <a
                                            href="{{ route('configs.show', $config) }}"
                                            class="text-blue-600 hover:text-blue-900 px-2 py-1 rounded"
                                            title="مشاهده جزئیات"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                            </svg>
                                        </a>

                                        {{-- آمار --}}
                                        <a
                                            href="{{ route('configs.stats', $config) }}"
                                            class="text-indigo-600 hover:text-indigo-900 px-2 py-1 rounded"
                                            title="آمار و گزارش"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                            </svg>
                                        </a>

                                        @if($config->isActive())
                                            @if($isRunning)
                                                {{-- متوقف کردن --}}
                                                <form method="POST" action="{{ route('configs.stop', $config) }}" class="inline">
                                                    @csrf
                                                    <button
                                                        type="submit"
                                                        class="text-red-600 hover:text-red-900 px-2 py-1 rounded"
                                                        title="متوقف کردن"
                                                        onclick="return confirm('آیا مطمئن هستید که می‌خواهید اجرا را متوقف کنید؟')"
                                                    >
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 10h6v4H9z"></path>
                                                        </svg>
                                                    </button>
                                                </form>
                                            @else
                                                {{-- اجرای عادی --}}
                                                <form method="POST" action="{{ route('configs.run', $config) }}" class="inline">
                                                    @csrf
                                                    <button
                                                        type="submit"
                                                        class="text-green-600 hover:text-green-900 px-2 py-1 rounded"
                                                        title="اجرای کانفیگ (صف)"
                                                    >
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h1m4 0h1m-6 4h6m2 5H7a2 2 0 01-2-2V7a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                        </svg>
                                                    </button>
                                                </form>

                                                {{-- اجرای فوری --}}
                                                <form method="POST" action="{{ route('configs.run-sync', $config) }}" class="inline">
                                                    @csrf
                                                    <button
                                                        type="submit"
                                                        class="text-orange-600 hover:text-orange-900 px-2 py-1 rounded"
                                                        title="اجرای فوری (همزمان)"
                                                        onclick="return confirm('اجرای فوری ممکن است زمان زیادی طول بکشد. ادامه می‌دهید؟')"
                                                    >
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                                        </svg>
                                                    </button>
                                                </form>
                                            @endif
                                        @endif

                                        {{-- ویرایش --}}
                                        <a
                                            href="{{ route('configs.edit', $config) }}"
                                            class="text-blue-600 hover:text-blue-900 px-2 py-1 rounded"
                                            title="ویرایش"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- صفحه‌بندی --}}
                @if($configs->hasPages())
                    <div class="bg-white px-4 py-3 border-t border-gray-200 sm:px-6">
                        {{ $configs->links() }}
                    </div>
                @endif
            @else
                {{-- پیام عدم وجود کانفیگ --}}
                <div class="text-center py-12">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V7a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <h3 class="mt-2 text-sm font-medium text-gray-900">
                        @if($search)
                            هیچ کانفیگی برای "{{ $search }}" یافت نشد
                        @else
                            هیچ کانفیگی موجود نیست
                        @endif
                    </h3>
                    <p class="mt-1 text-sm text-gray-500">
                        @if($search)
                            جستجوی دیگری امتحان کنید یا کانفیگ جدید ایجاد کنید.
                        @else
                            برای شروع، اولین کانفیگ خود را ایجاد کنید.
                        @endif
                    </p>
                    <div class="mt-6">
                        <a
                            href="{{ route('configs.create') }}"
                            class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                        >
                            + ایجاد کانفیگ جدید
                        </a>
                    </div>
                </div>
            @endif
        </div>

        {{-- آمار کوتاه --}}
        @if($configs->total() > 0)
            <div class="mt-6 bg-gray-50 rounded-lg p-4">
                <div class="text-sm text-gray-600">
                    نمایش {{ $configs->firstItem() }} تا {{ $configs->lastItem() }} از {{ $configs->total() }} کانفیگ
                    @if($search)
                        برای جستجوی "{{ $search }}"
                    @endif
                </div>
            </div>
        @endif
    </div>

    @push('scripts')
        <script>
            // به‌روزرسانی خودکار وضعیت صفحه هر 30 ثانیه
            setInterval(function() {
                // فقط اگر کانفیگی در حال اجرا باشد
                if (document.querySelector('.animate-spin')) {
                    location.reload();
                }
            }, 30000);

            // حذف پیام‌های موفقیت پس از 5 ثانیه
            document.addEventListener('DOMContentLoaded', function() {
                const alerts = document.querySelectorAll('.bg-green-100, .bg-yellow-100');
                alerts.forEach(function(alert) {
                    setTimeout(() => {
                        alert.style.transition = 'opacity 0.5s';
                        alert.style.opacity = '0';
                        setTimeout(() => {
                            alert.remove();
                        }, 500);
                    }, 5000);
                });
            });
        </script>
    @endpush
@endsection
