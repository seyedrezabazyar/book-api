@extends('layouts.app')

@section('title', 'مدیریت کانفیگ‌ها')

@section('content')
    <div class="container mx-auto px-4 py-6">
        {{-- هدر صفحه --}}
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 mb-2">مدیریت کانفیگ‌ها</h1>
                <p class="text-gray-600">کانفیگ‌های سیستم را مدیریت کنید</p>
            </div>

            <div class="flex flex-col sm:flex-row gap-3 mt-4 md:mt-0">
                {{-- جعبه جستجو --}}
                <form method="GET" action="{{ route('simple-configs.index') }}" class="flex">
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

                {{-- دکمه ایجاد کانفیگ جدید --}}
                <a
                    href="{{ route('simple-configs.create') }}"
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
                                توضیحات
                            </th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                وضعیت
                            </th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                تاریخ ایجاد
                            </th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                عملیات
                            </th>
                        </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                        @foreach($configs as $config)
                            <tr class="hover:bg-gray-50">
                                {{-- نام کانفیگ --}}
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">
                                                {{ $config->name }}
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                {{ Str::limit($config->config_data['base_url'] ?? '-', 40) }}
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                {{-- توضیحات --}}
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-900">
                                        {{ Str::limit($config->description ?? 'بدون توضیحات', 50) }}
                                    </div>
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

                                {{-- تاریخ ایجاد --}}
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <div>{{ $config->created_at->format('Y/m/d') }}</div>
                                    <div class="text-xs">{{ $config->created_at->format('H:i') }}</div>
                                </td>

                                {{-- عملیات --}}
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex items-center space-x-2 space-x-reverse">
                                        {{-- نمایش --}}
                                        <a
                                            href="{{ route('simple-configs.show', $config) }}"
                                            class="text-blue-600 hover:text-blue-900 px-2 py-1 rounded"
                                            title="مشاهده جزئیات"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                            </svg>
                                        </a>

                                        {{-- ویرایش --}}
                                        <a
                                            href="{{ route('simple-configs.edit', $config) }}"
                                            class="text-indigo-600 hover:text-indigo-900 px-2 py-1 rounded"
                                            title="ویرایش"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                        </a>

                                        {{-- تغییر وضعیت --}}
                                        <form
                                            method="POST"
                                            action="{{ route('simple-configs.toggle-status', $config) }}"
                                            class="inline"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <button
                                                type="submit"
                                                class="@if($config->isActive()) text-orange-600 hover:text-orange-900 @else text-green-600 hover:text-green-900 @endif px-2 py-1 rounded"
                                                title="@if($config->isActive()) غیرفعال کردن @else فعال کردن @endif"
                                                onclick="return confirm('آیا از تغییر وضعیت این کانفیگ اطمینان دارید؟')"
                                            >
                                                @if($config->isActive())
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    </svg>
                                                @else
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h1m4 0h1m-6 4h6m2 5H7a2 2 0 01-2-2V7a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                    </svg>
                                                @endif
                                            </button>
                                        </form>

                                        {{-- حذف --}}
                                        <form
                                            method="POST"
                                            action="{{ route('simple-configs.destroy', $config) }}"
                                            class="inline"
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <button
                                                type="submit"
                                                class="text-red-600 hover:text-red-900 px-2 py-1 rounded"
                                                title="حذف"
                                                onclick="return confirm('آیا از حذف این کانفیگ اطمینان دارید؟ این عمل غیرقابل بازگشت است.')"
                                            >
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        </form>
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
                            href="{{ route('simple-configs.create') }}"
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
            // حذف پیام‌های موفقیت پس از 5 ثانیه
            document.addEventListener('DOMContentLoaded', function() {
                const successAlert = document.querySelector('.bg-green-100');
                if (successAlert) {
                    setTimeout(() => {
                        successAlert.style.transition = 'opacity 0.5s';
                        successAlert.style.opacity = '0';
                        setTimeout(() => {
                            successAlert.remove();
                        }, 500);
                    }, 5000);
                }
            });
        </script>
    @endpush
@endsection
