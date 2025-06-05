@extends('layouts.app')

@section('title', 'آمار کانفیگ')

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
                    <h1 class="text-2xl font-bold text-gray-800">آمار و گزارش کانفیگ</h1>
                    <p class="text-gray-600">{{ $config->name }}</p>
                </div>
            </div>
        </div>

        {{-- وضعیت فعلی --}}
        <div class="bg-white rounded-lg shadow mb-6 p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">وضعیت فعلی</h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                {{-- وضعیت کانفیگ --}}
                <div class="text-center">
                    <div class="text-sm text-gray-500 mb-2">وضعیت کانفیگ</div>
                    <span class="inline-flex px-3 py-1 text-sm font-semibold rounded-full
                        @if($config->status === 'active') bg-green-100 text-green-800
                        @elseif($config->status === 'inactive') bg-red-100 text-red-800
                        @else bg-yellow-100 text-yellow-800 @endif
                    ">
                        {{ $config->status_text }}
                    </span>
                </div>

                {{-- وضعیت اجرا --}}
                <div class="text-center">
                    <div class="text-sm text-gray-500 mb-2">وضعیت اجرا</div>
                    @if($isRunning)
                        <span class="inline-flex items-center px-3 py-1 text-sm font-medium bg-yellow-100 text-yellow-800 rounded-full">
                            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-yellow-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            در حال اجرا
                        </span>
                    @elseif($error)
                        <span class="inline-flex px-3 py-1 text-sm font-medium bg-red-100 text-red-800 rounded-full">
                            ❌ خطا
                        </span>
                    @else
                        <span class="inline-flex px-3 py-1 text-sm font-medium bg-gray-100 text-gray-800 rounded-full">
                            آماده
                        </span>
                    @endif
                </div>

                {{-- نوع منبع --}}
                <div class="text-center">
                    <div class="text-sm text-gray-500 mb-2">نوع منبع</div>
                    <span class="inline-flex px-3 py-1 text-sm font-semibold rounded-full
                        @if($config->data_source_type === 'api') bg-blue-100 text-blue-800
                        @else bg-purple-100 text-purple-800 @endif
                    ">
                        {{ $config->data_source_type_text }}
                    </span>
                </div>
            </div>
        </div>

        {{-- دکمه‌های عمل --}}
        <div class="flex items-center gap-4 mb-6">
            @if($config->isActive())
                @if(!$isRunning)
                    {{-- اجرای عادی --}}
                    <form method="POST" action="{{ route('configs.run', $config) }}" class="inline">
                        @csrf
                        <button
                            type="submit"
                            class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500"
                            title="اجرای کانفیگ (صف)"
                        >
                            ▶️ اجرای کانفیگ
                        </button>
                    </form>

                    {{-- اجرای فوری --}}
                    <form method="POST" action="{{ route('configs.run-sync', $config) }}" class="inline">
                        @csrf
                        <button
                            type="submit"
                            class="px-4 py-2 bg-orange-600 text-white rounded-md hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-500"
                            title="اجرای فوری (همزمان)"
                            onclick="return confirm('اجرای فوری ممکن است زمان زیادی طول بکشد. ادامه می‌دهید؟')"
                        >
                            ⚡ اجرای فوری
                        </button>
                    </form>
                @else
                    {{-- متوقف کردن --}}
                    <form method="POST" action="{{ route('configs.stop', $config) }}" class="inline">
                        @csrf
                        <button
                            type="submit"
                            class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500"
                            title="متوقف کردن"
                            onclick="return confirm('آیا مطمئن هستید که می‌خواهید اجرا را متوقف کنید؟')"
                        >
                            ⏹️ متوقف کردن
                        </button>
                    </form>
                @endif
            @endif

            {{-- پاک کردن آمار --}}
            <form method="POST" action="{{ route('configs.clear-stats', $config) }}" class="inline">
                @csrf
                @method('DELETE')
                <button
                    type="submit"
                    class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500"
                    title="پاک کردن آمار"
                    onclick="return confirm('آیا مطمئن هستید که می‌خواهید آمار را پاک کنید؟')"
                >
                    🗑️ پاک کردن آمار
                </button>
            </form>
        </div>

        {{-- آمار آخرین اجرا --}}
        @if($stats)
            <div class="bg-white rounded-lg shadow mb-6 p-6">
                <h2 class="text-lg font-medium text-gray-900 mb-4">آمار آخرین اجرا</h2>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                    {{-- کل رکوردها --}}
                    <div class="text-center p-4 bg-blue-50 rounded-lg">
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($stats['total']) }}</div>
                        <div class="text-sm text-blue-800">کل رکوردها</div>
                    </div>

                    {{-- موفق --}}
                    <div class="text-center p-4 bg-green-50 rounded-lg">
                        <div class="text-2xl font-bold text-green-600">{{ number_format($stats['success']) }}</div>
                        <div class="text-sm text-green-800">موفق</div>
                    </div>

                    {{-- خطا --}}
                    <div class="text-center p-4 bg-red-50 rounded-lg">
                        <div class="text-2xl font-bold text-red-600">{{ number_format($stats['failed']) }}</div>
                        <div class="text-sm text-red-800">خطا</div>
                    </div>

                    {{-- تکراری --}}
                    <div class="text-center p-4 bg-yellow-50 rounded-lg">
                        <div class="text-2xl font-bold text-yellow-600">{{ number_format($stats['duplicate']) }}</div>
                        <div class="text-sm text-yellow-800">تکراری</div>
                    </div>
                </div>

                {{-- اطلاعات تکمیلی --}}
                <div class="mt-6 border-t border-gray-200 pt-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <div class="text-sm text-gray-500">زمان آخرین اجرا</div>
                            <div class="text-lg font-medium">
                                {{ \Carbon\Carbon::parse($stats['last_run'])->format('Y/m/d H:i:s') }}
                            </div>
                        </div>

                        @if(isset($stats['execution_time']))
                            <div>
                                <div class="text-sm text-gray-500">مدت زمان اجرا</div>
                                <div class="text-lg font-medium">{{ $stats['execution_time'] }} ثانیه</div>
                            </div>
                        @endif
                    </div>

                    {{-- نرخ موفقیت --}}
                    @if($stats['total'] > 0)
                        <div class="mt-4">
                            <div class="text-sm text-gray-500 mb-2">نرخ موفقیت</div>
                            @php
                                $successRate = round(($stats['success'] / $stats['total']) * 100, 1);
                            @endphp
                            <div class="w-full bg-gray-200 rounded-full h-2.5">
                                <div class="bg-green-600 h-2.5 rounded-full" style="width: {{ $successRate }}%"></div>
                            </div>
                            <div class="text-sm text-gray-600 mt-1">{{ $successRate }}%</div>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- خطاهای رخ داده --}}
        @if($error)
            <div class="bg-white rounded-lg shadow mb-6 p-6">
                <h2 class="text-lg font-medium text-gray-900 mb-4">آخرین خطا</h2>

                <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="mr-3">
                            <h3 class="text-sm font-medium text-red-800">پیام خطا</h3>
                            <div class="mt-2 text-sm text-red-700">
                                <p>{{ $error['message'] }}</p>
                            </div>
                            <div class="mt-2 text-xs text-red-600">
                                زمان: {{ $error['time'] }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- اطلاعات کانفیگ --}}
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">اطلاعات کانفیگ</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <div class="text-sm text-gray-500">آدرس پایه</div>
                    <div class="text-sm font-medium break-all">{{ $config->base_url }}</div>
                </div>

                <div>
                    <div class="text-sm text-gray-500">Timeout</div>
                    <div class="text-sm font-medium">{{ $config->timeout }} ثانیه</div>
                </div>

                <div>
                    <div class="text-sm text-gray-500">تعداد تلاش مجدد</div>
                    <div class="text-sm font-medium">{{ $config->max_retries }}</div>
                </div>

                <div>
                    <div class="text-sm text-gray-500">تاخیر</div>
                    <div class="text-sm font-medium">{{ $config->delay }} میلی‌ثانیه</div>
                </div>

                @if($config->isApiSource())
                    @php $apiSettings = $config->getApiSettings(); @endphp
                    <div>
                        <div class="text-sm text-gray-500">Endpoint</div>
                        <div class="text-sm font-medium">{{ $apiSettings['endpoint'] ?? '-' }}</div>
                    </div>

                    <div>
                        <div class="text-sm text-gray-500">متد HTTP</div>
                        <div class="text-sm font-medium">{{ $apiSettings['method'] ?? '-' }}</div>
                    </div>
                @endif

                <div class="md:col-span-2">
                    <div class="text-sm text-gray-500">توضیحات</div>
                    <div class="text-sm font-medium">{{ $config->description ?? 'بدون توضیحات' }}</div>
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

    @push('scripts')
        <script>
            // به‌روزرسانی خودکار صفحه هر 10 ثانیه اگر کانفیگ در حال اجرا باشد
            @if($isRunning)
            setInterval(function() {
                location.reload();
            }, 10000);
            @endif
        </script>
    @endpush
@endsection
