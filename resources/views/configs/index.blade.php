@extends('layouts.app')

@section('title', 'مدیریت کانفیگ‌ها')

@section('content')
    <div class="container mx-auto px-4 py-6">
        <!-- هدر صفحه -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 mb-2">مدیریت کانفیگ‌ها</h1>
                <p class="text-gray-600">کانفیگ‌های API را مدیریت و اجرا کنید</p>
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

        <!-- جدول کانفیگ‌ها -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            @if($configs->count() > 0)
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">نام</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">وضعیت</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">تنظیمات</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">آخرین اجرا</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">عملیات</th>
                        </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($configs as $config)
                            @php
                                $lastLog = \App\Models\ExecutionLog::where('config_id', $config->id)
                                    ->latest()
                                    ->first();
                            @endphp

                            <tr class="hover:bg-gray-50">
                                <!-- نام و توضیحات -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900">{{ $config->name }}</div>
                                        <div class="text-sm text-gray-500">{{ Str::limit($config->description ?? 'بدون توضیحات', 40) }}</div>
                                        <div class="text-xs text-gray-400 mt-1">{{ Str::limit($config->base_url, 50) }}</div>
                                    </div>
                                </td>

                                <!-- وضعیت -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                        @if($config->status === 'active') bg-green-100 text-green-800
                                        @elseif($config->status === 'inactive') bg-red-100 text-red-800
                                        @else bg-yellow-100 text-yellow-800 @endif">
                                        {{ $config->status_text }}
                                    </span>
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

                                <!-- آخرین اجرا -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if($lastLog)
                                        <div class="text-xs space-y-1">
                                            <div class="flex items-center">
                                                @if($lastLog->status === 'completed')
                                                    <span class="w-2 h-2 bg-green-500 rounded-full mr-2"></span>
                                                @elseif($lastLog->status === 'failed')
                                                    <span class="w-2 h-2 bg-red-500 rounded-full mr-2"></span>
                                                @else
                                                    <span class="w-2 h-2 bg-yellow-500 rounded-full mr-2"></span>
                                                @endif
                                                {{ $lastLog->started_at->diffForHumans() }}
                                            </div>
                                            @if($lastLog->status === 'completed')
                                                <div>✅ {{ number_format($lastLog->total_success) }} موفق</div>
                                                <div>🔄 {{ number_format($lastLog->total_duplicate) }} تکراری</div>
                                                @if($lastLog->total_failed > 0)
                                                    <div>❌ {{ number_format($lastLog->total_failed) }} خطا</div>
                                                @endif
                                            @elseif($lastLog->status === 'failed')
                                                <div class="text-red-600">❌ ناموفق</div>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-gray-400 text-xs">هرگز اجرا نشده</span>
                                    @endif
                                </td>

                                <!-- عملیات -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex items-center space-x-2 space-x-reverse">
                                        <!-- نمایش -->
                                        <a href="{{ route('configs.show', $config) }}"
                                           class="text-blue-600 hover:text-blue-900 p-1 rounded" title="مشاهده جزئیات">
                                            👁️
                                        </a>

                                        <!-- لاگ‌ها -->
                                        <a href="{{ route('configs.logs', $config) }}"
                                           class="text-green-600 hover:text-green-900 p-1 rounded" title="مشاهده لاگ‌ها">
                                            📊
                                        </a>

                                        @if($config->status === 'active')
                                            <!-- اجرای فوری -->
                                            <form method="POST" action="{{ route('configs.run-sync', $config) }}" class="inline">
                                                @csrf
                                                <button type="submit"
                                                        class="text-orange-600 hover:text-orange-900 p-1 rounded"
                                                        title="اجرای فوری"
                                                        onclick="return confirm('اجرای فوری شروع می‌شود. ممکن است زمان زیادی طول بکشد. ادامه می‌دهید؟')">
                                                    ⚡
                                                </button>
                                            </form>
                                        @endif

                                        <!-- ویرایش -->
                                        <a href="{{ route('configs.edit', $config) }}"
                                           class="text-blue-600 hover:text-blue-900 p-1 rounded" title="ویرایش">
                                            ✏️
                                        </a>

                                        <!-- حذف -->
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
                        $totalActive = $configs->where('status', 'active')->count();
                        $totalBooks = \App\Models\Book::count();
                        $totalExecutions = \App\Models\ExecutionLog::where('status', 'completed')->count();
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
                            <div class="text-lg font-bold text-blue-600">{{ number_format($totalBooks) }}</div>
                            <div class="text-xs text-gray-500">کل کتاب‌ها</div>
                        </div>
                        <div class="text-center">
                            <div class="text-lg font-bold text-purple-600">{{ number_format($totalExecutions) }}</div>
                            <div class="text-xs text-gray-500">اجراهای موفق</div>
                        </div>
                    </div>

                    <div class="mt-4 pt-4 border-t border-gray-200 flex justify-between items-center text-xs">
                        <div>
                            نمایش {{ $configs->firstItem() }} تا {{ $configs->lastItem() }} از {{ $configs->total() }} کانفیگ
                            @if($search ?? false)
                                برای جستجوی "{{ $search }}"
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
