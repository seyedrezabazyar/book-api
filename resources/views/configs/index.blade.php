@extends('layouts.app')

@section('title', 'مدیریت کانفیگ‌ها')

@section('content')
    <div class="container mx-auto px-4 py-6">
        <!-- هدر صفحه -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 mb-2">مدیریت کانفیگ‌ها</h1>
                <p class="text-gray-600">کانفیگ‌های اسکرپر را مدیریت و اجرا کنید</p>
            </div>

            <div class="flex flex-col sm:flex-row gap-3 mt-4 md:mt-0">
                <!-- جستجو -->
                <form method="GET" class="flex">
                    <input
                        type="text"
                        name="search"
                        value="{{ $search ?? '' }}"
                        placeholder="جستجو..."
                        class="px-4 py-2 border border-gray-300 rounded-r-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                    <button type="submit" class="px-4 py-2 bg-gray-600 text-white rounded-l-md hover:bg-gray-700">
                        🔍
                    </button>
                </form>

                <!-- دکمه‌های کنترل همه -->
                <form method="POST" action="{{ route('configs.start-all') }}" class="inline">
                    @csrf
                    <button
                        type="submit"
                        class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700"
                        onclick="return confirm('شروع همه کانفیگ‌های فعال؟')"
                    >
                        ▶️ شروع همه
                    </button>
                </form>

                <form method="POST" action="{{ route('configs.stop-all') }}" class="inline">
                    @csrf
                    <button
                        type="submit"
                        class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700"
                        onclick="return confirm('متوقف کردن همه اسکرپرها؟')"
                    >
                        ⏹️ توقف همه
                    </button>
                </form>

                <!-- کانفیگ جدید -->
                <a
                    href="{{ route('configs.create') }}"
                    class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-center"
                >
                    ➕ کانفیگ جدید
                </a>
            </div>
        </div>

        <!-- پیام‌ها -->
        @if(session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                {{ session('error') }}
            </div>
        @endif

        @if(session('warning'))
            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                {{ session('warning') }}
            </div>
        @endif

        @if(session('info'))
            <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded mb-4">
                {{ session('info') }}
            </div>
        @endif

        <!-- جدول کانفیگ‌ها -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            @if($configs->count() > 0)
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">نام</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">نوع</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">وضعیت</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">آمار</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">تنظیمات</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">عملیات</th>
                        </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($configs as $config)
                            <tr class="hover:bg-gray-50">
                                <!-- نام و توضیحات -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">{{ $config->name }}</div>
                                        <div class="text-sm text-gray-500">{{ Str::limit($config->description ?? 'بدون توضیحات', 40) }}</div>
                                        <div class="text-xs text-gray-400 mt-1">{{ Str::limit($config->base_url, 50) }}</div>
                                    </div>
                                </td>

                                <!-- نوع منبع -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                        @if($config->data_source_type === 'api') bg-blue-100 text-blue-800
                                        @else bg-purple-100 text-purple-800 @endif">
                                        {{ $config->data_source_type_text }}
                                    </span>
                                </td>

                                <!-- وضعیت -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex flex-col space-y-1">
                                        <!-- وضعیت کانفیگ -->
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                            @if($config->status === 'active') bg-green-100 text-green-800
                                            @elseif($config->status === 'inactive') bg-red-100 text-red-800
                                            @else bg-yellow-100 text-yellow-800 @endif">
                                            {{ $config->status_text }}
                                        </span>

                                        <!-- وضعیت اجرا -->
                                        @if($config->is_running)
                                            <span class="inline-flex items-center px-2 py-1 text-xs font-medium bg-yellow-100 text-yellow-800 rounded-full">
                                                <svg class="animate-spin -ml-1 mr-1 h-3 w-3" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                                در حال اجرا
                                            </span>
                                        @else
                                            <span class="inline-flex px-2 py-1 text-xs font-medium bg-gray-100 text-gray-700 rounded-full">
                                                آماده
                                            </span>
                                        @endif
                                    </div>
                                </td>

                                <!-- آمار -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-xs space-y-1">
                                        <div>کل: <span class="font-medium">{{ number_format($config->total_processed) }}</span></div>
                                        <div>موفق: <span class="font-medium text-green-600">{{ number_format($config->total_success) }}</span></div>
                                        <div>خطا: <span class="font-medium text-red-600">{{ number_format($config->total_failed) }}</span></div>
                                        @if($config->total_processed > 0)
                                            <div>نرخ: <span class="font-medium">{{ $config->getSuccessRate() }}%</span></div>
                                        @endif
                                        @if($config->last_run_at)
                                            <div class="text-gray-500">آخرین اجرا: {{ $config->last_run_at->diffForHumans() }}</div>
                                        @endif
                                    </div>
                                </td>

                                <!-- تنظیمات سرعت -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-xs space-y-1">
                                        <div>هر <span class="font-medium">{{ $config->delay_seconds }}</span> ثانیه</div>
                                        <div><span class="font-medium">{{ $config->records_per_run }}</span> رکورد</div>
                                        <div class="text-gray-500">
                                            ≈ {{ round((60 / $config->delay_seconds) * $config->records_per_run) }} رکورد/دقیقه
                                        </div>
                                    </div>
                                </td>

                                <!-- عملیات -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex items-center space-x-2 space-x-reverse">
                                        <!-- نمایش -->
                                        <a href="{{ route('configs.show', $config) }}"
                                           class="text-blue-600 hover:text-blue-900 p-1 rounded" title="مشاهده جزئیات">
                                            👁️
                                        </a>

                                        <!-- آمار -->
                                        <a href="{{ route('configs.stats', $config) }}"
                                           class="text-green-600 hover:text-green-900 p-1 rounded" title="آمار و گزارش">
                                            📊
                                        </a>

                                        <!-- شکست‌ها -->
                                        @php $unresolvedFailures = $config->failures()->where('is_resolved', false)->count(); @endphp
                                        <a href="{{ route('configs.failures', $config) }}"
                                           class="text-orange-600 hover:text-orange-900 p-1 rounded relative" title="شکست‌ها">
                                            ⚠️
                                            @if($unresolvedFailures > 0)
                                                <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-4 w-4 flex items-center justify-center">
                                                    {{ $unresolvedFailures > 9 ? '9+' : $unresolvedFailures }}
                                                </span>
                                            @endif
                                        </a>

                                        @if($config->status === 'active')
                                            @if($config->is_running)
                                                <!-- متوقف کردن -->
                                                <form method="POST" action="{{ route('configs.stop', $config) }}" class="inline">
                                                    @csrf
                                                    <button type="submit"
                                                            class="text-red-600 hover:text-red-900 p-1 rounded"
                                                            title="متوقف کردن"
                                                            onclick="return confirm('متوقف کردن اسکرپر؟')">
                                                        ⏹️
                                                    </button>
                                                </form>
                                            @else
                                                <!-- شروع -->
                                                <form method="POST" action="{{ route('configs.start', $config) }}" class="inline">
                                                    @csrf
                                                    <button type="submit"
                                                            class="text-green-600 hover:text-green-900 p-1 rounded"
                                                            title="شروع اسکرپر">
                                                        ▶️
                                                    </button>
                                                </form>

                                                <!-- ریست -->
                                                <form method="POST" action="{{ route('configs.reset', $config) }}" class="inline">
                                                    @csrf
                                                    <button type="submit"
                                                            class="text-orange-600 hover:text-orange-900 p-1 rounded"
                                                            title="شروع از اول"
                                                            onclick="return confirm('پاک کردن پیشرفت و شروع از اول؟')">
                                                        🔄
                                                    </button>
                                                </form>
                                            @endif
                                        @endif

                                        <!-- ویرایش -->
                                        <a href="{{ route('configs.edit', $config) }}"
                                           class="text-blue-600 hover:text-blue-900 p-1 rounded" title="ویرایش">
                                            ✏️
                                        </a>

                                        <!-- حذف -->
                                        @if(!$config->is_running)
                                            <form method="POST" action="{{ route('configs.destroy', $config) }}" class="inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="text-red-600 hover:text-red-900 p-1 rounded"
                                                        title="حذف کانفیگ"
                                                        onclick="return confirm('حذف کامل کانفیگ و تمام اطلاعات آن؟')">
                                                    🗑️
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- صفحه‌بندی -->
                @if($configs->hasPages())
                    <div class="bg-white px-4 py-3 border-t border-gray-200 sm:px-6">
                        {{ $configs->appends(request()->query())->links() }}
                    </div>
                @endif
            @else
                <!-- پیام عدم وجود کانفیگ -->
                <div class="text-center py-12">
                    <div class="text-6xl mb-4">📄</div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">
                        @if($search ?? false)
                            هیچ کانفیگی برای "{{ $search }}" یافت نشد
                        @else
                            هیچ کانفیگی موجود نیست
                        @endif
                    </h3>
                    <p class="text-gray-500 mb-6">
                        @if($search ?? false)
                            جستجوی دیگری امتحان کنید یا کانفیگ جدید ایجاد کنید
                        @else
                            اولین کانفیگ خود را ایجاد کنید تا شروع کنید
                        @endif
                    </p>
                    <div class="space-x-3 space-x-reverse">
                        @if($search ?? false)
                            <a href="{{ route('configs.index') }}"
                               class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700">
                                🔍 نمایش همه
                            </a>
                        @endif
                        <a href="{{ route('configs.create') }}"
                           class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                            ➕ ایجاد کانفیگ جدید
                        </a>
                    </div>
                </div>
            @endif
        </div>

        <!-- آمار کلی -->
        @if($configs->total() > 0)
            <div class="mt-6 bg-gray-50 rounded-lg p-4">
                <div class="text-sm text-gray-600">
                    @php
                        $totalRunning = $configs->where('is_running', true)->count();
                        $totalActive = $configs->where('status', 'active')->count();
                        $totalProcessed = $configs->sum('total_processed');
                        $totalSuccess = $configs->sum('total_success');
                        $totalFailed = $configs->sum('total_failed');
                    @endphp

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="text-center">
                            <div class="text-lg font-bold text-gray-800">{{ $configs->total() }}</div>
                            <div class="text-xs text-gray-500">کل کانفیگ‌ها</div>
                        </div>
                        <div class="text-center">
                            <div class="text-lg font-bold text-green-600">{{ $totalActive }}</div>
                            <div class="text-xs text-gray-500">فعال</div>
                        </div>
                        <div class="text-center">
                            <div class="text-lg font-bold text-yellow-600">{{ $totalRunning }}</div>
                            <div class="text-xs text-gray-500">در حال اجرا</div>
                        </div>
                        <div class="text-center">
                            <div class="text-lg font-bold text-blue-600">{{ number_format($totalProcessed) }}</div>
                            <div class="text-xs text-gray-500">کل پردازش شده</div>
                        </div>
                    </div>

                    <div class="mt-4 pt-4 border-t border-gray-200 flex justify-between items-center text-xs">
                        <div>
                            نمایش {{ $configs->firstItem() }} تا {{ $configs->lastItem() }} از {{ $configs->total() }} کانفیگ
                            @if($search ?? false)
                                برای جستجوی "{{ $search }}"
                            @endif
                        </div>
                        @if($totalProcessed > 0)
                            <div class="text-gray-500">
                                نرخ موفقیت کلی: {{ round(($totalSuccess / $totalProcessed) * 100, 1) }}%
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>

    <!-- به‌روزرسانی خودکار صفحه -->
    <script>
        // به‌روزرسانی خودکار هر 30 ثانیه اگر کانفیگی در حال اجرا باشد
        @if($configs->where('is_running', true)->count() > 0)
        setInterval(() => {
            // بررسی اینکه آیا هنوز در همان صفحه هستیم
            if (window.location.pathname === '{{ route('configs.index') }}') {
                window.location.reload();
            }
        }, 30000);
        @endif

        // تأیید عملیات‌های مهم
        document.addEventListener('DOMContentLoaded', function() {
            // اضافه کردن تأیید برای دکمه‌های خطرناک
            document.querySelectorAll('form button[title*="حذف"], form button[title*="ریست"]').forEach(button => {
                button.addEventListener('click', function(e) {
                    const form = this.closest('form');
                    const action = this.getAttribute('title');

                    if (!confirm(`آیا مطمئن هستید که می‌خواهید ${action} را انجام دهید؟`)) {
                        e.preventDefault();
                    }
                });
            });
        });
    </script>
@endsection
