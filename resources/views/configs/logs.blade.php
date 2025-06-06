@extends('layouts.app')

@section('title', 'لاگ‌های اجرا')

@section('content')
    <div class="container mx-auto px-4 py-6">
        <!-- هدر صفحه -->
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
                    <h1 class="text-2xl font-bold text-gray-800">لاگ‌های اجرا</h1>
                    <p class="text-gray-600">{{ $config->name }}</p>
                </div>
            </div>
        </div>

        <!-- آمار سریع -->
        @php
            $totalLogs = $logs->total();
            $completedLogs = \App\Models\ExecutionLog::where('config_id', $config->id)->where('status', 'completed')->count();
            $failedLogs = \App\Models\ExecutionLog::where('config_id', $config->id)->where('status', 'failed')->count();
            $totalSuccessfulBooks = \App\Models\ExecutionLog::where('config_id', $config->id)->where('status', 'completed')->sum('total_success');
        @endphp

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white p-4 rounded-lg shadow">
                <div class="text-sm text-gray-500">کل اجراها</div>
                <div class="text-2xl font-bold text-blue-600">{{ number_format($totalLogs) }}</div>
            </div>
            <div class="bg-white p-4 rounded-lg shadow">
                <div class="text-sm text-gray-500">موفق</div>
                <div class="text-2xl font-bold text-green-600">{{ number_format($completedLogs) }}</div>
            </div>
            <div class="bg-white p-4 rounded-lg shadow">
                <div class="text-sm text-gray-500">ناموفق</div>
                <div class="text-2xl font-bold text-red-600">{{ number_format($failedLogs) }}</div>
            </div>
            <div class="bg-white p-4 rounded-lg shadow">
                <div class="text-sm text-gray-500">کل کتاب‌های دریافتی</div>
                <div class="text-2xl font-bold text-purple-600">{{ number_format($totalSuccessfulBooks) }}</div>
            </div>
        </div>

        <!-- لیست لاگ‌ها -->
        <div class="bg-white rounded-lg shadow">
            @if($logs->count() > 0)
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">شناسه اجرا</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">زمان شروع</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">مدت زمان</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">آمار</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">وضعیت</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">عمل</th>
                        </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                        @foreach($logs as $log)
                            <tr class="hover:bg-gray-50">
                                <!-- شناسه اجرا -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-mono text-gray-900">{{ $log->execution_id }}</div>
                                    <div class="text-xs text-gray-500">ID: {{ $log->id }}</div>
                                </td>

                                <!-- زمان شروع -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">{{ $log->started_at->format('Y/m/d H:i:s') }}</div>
                                    <div class="text-xs text-gray-500">{{ $log->started_at->diffForHumans() }}</div>
                                </td>

                                <!-- مدت زمان -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if($log->execution_time)
                                        <div class="text-sm text-gray-900">{{ $log->execution_time }}s</div>
                                    @elseif($log->status === 'running')
                                        <div class="text-sm text-yellow-600">در حال اجرا...</div>
                                    @else
                                        <div class="text-sm text-gray-400">-</div>
                                    @endif
                                </td>

                                <!-- آمار -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-xs space-y-1">
                                        @if($log->status === 'completed')
                                            <div>کل: <span class="font-medium">{{ number_format($log->total_processed) }}</span></div>
                                            <div>✅ موفق: <span class="font-medium text-green-600">{{ number_format($log->total_success) }}</span></div>
                                            @if($log->total_duplicate > 0)
                                                <div>🔄 تکراری: <span class="font-medium text-yellow-600">{{ number_format($log->total_duplicate) }}</span></div>
                                            @endif
                                            @if($log->total_failed > 0)
                                                <div>❌ خطا: <span class="font-medium text-red-600">{{ number_format($log->total_failed) }}</span></div>
                                            @endif
                                            @if($log->total_processed > 0)
                                                <div>نرخ: <span class="font-medium">{{ $log->success_rate }}%</span></div>
                                            @endif
                                        @else
                                            <div class="text-gray-400">-</div>
                                        @endif
                                    </div>
                                </td>

                                <!-- وضعیت -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if($log->status === 'completed')
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                            ✅ تمام شده
                                        </span>
                                    @elseif($log->status === 'failed')
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                            ❌ ناموفق
                                        </span>
                                    @elseif($log->status === 'running')
                                        <span class="inline-flex items-center px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                            <svg class="animate-spin -ml-1 mr-1 h-3 w-3" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            در حال اجرا
                                        </span>
                                    @endif
                                </td>

                                <!-- عملیات -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <a href="{{ route('configs.log-details', [$config, $log]) }}"
                                       class="text-blue-600 hover:text-blue-900 text-sm"
                                       title="مشاهده جزئیات">
                                        📋 جزئیات
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <!-- صفحه‌بندی -->
                @if($logs->hasPages())
                    <div class="px-4 py-3 border-t">
                        {{ $logs->links() }}
                    </div>
                @endif
            @else
                <!-- پیام عدم وجود لاگ -->
                <div class="text-center py-12">
                    <div class="text-6xl mb-4">📊</div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">هیچ لاگی ثبت نشده!</h3>
                    <p class="text-gray-500 mb-6">هنوز هیچ اجرایی برای این کانفیگ انجام نشده است.</p>
                    @if($config->status === 'active')
                        <form method="POST" action="{{ route('configs.run-sync', $config) }}" class="inline">
                            @csrf
                            <button
                                type="submit"
                                class="px-6 py-2 bg-orange-600 text-white rounded-md hover:bg-orange-700"
                                onclick="return confirm('اجرای فوری شروع می‌شود. ادامه می‌دهید؟')"
                            >
                                ⚡ اولین اجرای فوری
                            </button>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endsection
