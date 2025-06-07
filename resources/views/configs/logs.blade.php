@extends('layouts.app')
@section('title', 'لاگ‌های اجرا')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center gap-4">
            <a href="{{ route('configs.index') }}" class="text-gray-600 hover:text-gray-800">←</a>
            <div>
                <h1 class="text-2xl font-semibold">لاگ‌های اجرا</h1>
                <p class="text-gray-600">{{ $config->name }}</p>
            </div>
        </div>

        <!-- Quick Stats -->
        @php
            $totalLogs = $logs->total();
            $completedLogs = \App\Models\ExecutionLog::where('config_id', $config->id)->where('status', 'completed')->count();
            $failedLogs = \App\Models\ExecutionLog::where('config_id', $config->id)->where('status', 'failed')->count();
            $stoppedLogs = \App\Models\ExecutionLog::where('config_id', $config->id)->where('status', 'stopped')->count();
            $totalSuccessfulBooks = \App\Models\ExecutionLog::where('config_id', $config->id)->whereIn('status', ['completed', 'stopped'])->sum('total_success');
            $totalProcessedBooks = \App\Models\ExecutionLog::where('config_id', $config->id)->whereIn('status', ['completed', 'stopped'])->sum('total_processed');

            // شمارش کتاب‌های واقعی در دیتابیس
            $actualBooksInDb = \App\Models\Book::where('created_at', '>=', $config->created_at)->count();
        @endphp

        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-blue-600">{{ number_format($totalLogs) }}</div>
                <div class="text-sm text-gray-600">کل اجراها</div>
            </div>
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-green-600">{{ number_format($completedLogs) }}</div>
                <div class="text-sm text-gray-600">تمام شده</div>
            </div>
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-orange-600">{{ number_format($stoppedLogs) }}</div>
                <div class="text-sm text-gray-600">متوقف شده</div>
            </div>
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-red-600">{{ number_format($failedLogs) }}</div>
                <div class="text-sm text-gray-600">ناموفق</div>
            </div>
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-purple-600">{{ number_format($totalSuccessfulBooks) }}</div>
                <div class="text-sm text-gray-600">کتاب‌های دریافتی</div>
                <div class="text-xs text-gray-500">از {{ number_format($totalProcessedBooks) }} پردازش شده</div>
            </div>
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-indigo-600">{{ number_format($actualBooksInDb) }}</div>
                <div class="text-sm text-gray-600">کتاب در دیتابیس</div>
                <div class="text-xs text-gray-500">کل واقعی</div>
            </div>
        </div>

        <!-- Config Current Stats -->
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4">
            <h3 class="text-lg font-semibold text-blue-800 mb-2">📊 آمار کلی کانفیگ</h3>
            <div class="grid grid-cols-3 gap-4 text-sm">
                <div>
                    <span class="text-blue-700">کل پردازش شده:</span>
                    <span class="font-bold text-blue-900">{{ number_format($config->total_processed) }}</span>
                </div>
                <div>
                    <span class="text-green-700">موفقیت‌آمیز:</span>
                    <span class="font-bold text-green-900">{{ number_format($config->total_success) }}</span>
                </div>
                <div>
                    <span class="text-red-700">خطا:</span>
                    <span class="font-bold text-red-900">{{ number_format($config->total_failed) }}</span>
                </div>
            </div>
            @if($config->total_processed > 0)
                <div class="mt-3">
                    <div class="text-sm text-blue-700 mb-1">نرخ موفقیت کلی: {{ round(($config->total_success / $config->total_processed) * 100, 1) }}%</div>
                    <div class="w-full bg-blue-200 rounded-full h-2">
                        <div class="bg-blue-600 h-2 rounded-full" style="width: {{ round(($config->total_success / $config->total_processed) * 100, 1) }}%"></div>
                    </div>
                </div>
            @endif
        </div>

        <!-- Logs List -->
        <div class="bg-white rounded shadow overflow-hidden">
            @if($logs->count() > 0)
                <table class="w-full">
                    <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-right">شناسه اجرا</th>
                        <th class="px-4 py-3 text-right">زمان شروع</th>
                        <th class="px-4 py-3 text-right">مدت زمان</th>
                        <th class="px-4 py-3 text-right">آمار تفصیلی</th>
                        <th class="px-4 py-3 text-right">وضعیت</th>
                        <th class="px-4 py-3 text-right">عمل</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y">
                    @foreach($logs as $log)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="text-sm font-mono">{{ $log->execution_id }}</div>
                                <div class="text-xs text-gray-500">ID: {{ $log->id }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-sm">{{ $log->started_at->format('Y/m/d H:i:s') }}</div>
                                <div class="text-xs text-gray-500">{{ $log->started_at->diffForHumans() }}</div>
                                @if($log->finished_at)
                                    <div class="text-xs text-gray-400">تا {{ $log->finished_at->format('H:i:s') }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($log->execution_time)
                                    <div class="text-sm font-medium">{{ round($log->execution_time) }}s</div>
                                    @if($log->execution_time > 60)
                                        <div class="text-xs text-gray-500">≈{{ round($log->execution_time / 60, 1) }} دقیقه</div>
                                    @endif
                                @elseif($log->status === 'running')
                                    <div class="text-sm text-yellow-600">
                                        <div class="flex items-center">
                                            <svg class="animate-spin h-4 w-4 mr-1" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            در حال اجرا...
                                        </div>
                                    </div>
                                @else
                                    <div class="text-sm text-gray-400">-</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-xs space-y-1">
                                    @if($log->status === 'completed' || $log->status === 'stopped')
                                        @if($log->total_processed > 0)
                                            <div class="grid grid-cols-2 gap-2">
                                                <div>کل: <span class="font-medium">{{ number_format($log->total_processed) }}</span></div>
                                                <div>✅ موفق: <span class="font-medium text-green-600">{{ number_format($log->total_success) }}</span></div>
                                                @if($log->total_duplicate > 0)
                                                    <div>🔄 تکراری: <span class="font-medium text-yellow-600">{{ number_format($log->total_duplicate) }}</span></div>
                                                @endif
                                                @if($log->total_failed > 0)
                                                    <div>❌ خطا: <span class="font-medium text-red-600">{{ number_format($log->total_failed) }}</span></div>
                                                @endif
                                            </div>
                                            <div class="pt-1 border-t border-gray-200">
                                                <div>نرخ موفقیت: <span class="font-medium">{{ $log->success_rate }}%</span></div>
                                                <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                                                    <div class="bg-green-600 h-1.5 rounded-full" style="width: {{ $log->success_rate }}%"></div>
                                                </div>
                                            </div>
                                        @else
                                            <div class="text-gray-500">هیچ رکوردی پردازش نشد</div>
                                        @endif
                                    @elseif($log->status === 'failed')
                                        <div class="text-red-600">اجرا با خطا متوقف شد</div>
                                        @if($log->error_message)
                                            <div class="text-xs text-red-500">{{ Str::limit($log->error_message, 60) }}</div>
                                        @endif
                                    @else
                                        <div class="text-yellow-600">در حال پردازش...</div>
                                        @if($log->total_processed > 0)
                                            <div class="text-xs">تاکنون: {{ $log->total_success }} از {{ $log->total_processed }}</div>
                                        @endif
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                @if($log->status === 'completed')
                                    <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">✅ تمام شده</span>
                                @elseif($log->status === 'failed')
                                    <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded">❌ ناموفق</span>
                                @elseif($log->status === 'stopped')
                                    <span class="px-2 py-1 text-xs bg-orange-100 text-orange-800 rounded">⏹️ متوقف شده</span>
                                @elseif($log->status === 'running')
                                    <span class="inline-flex items-center px-2 py-1 text-xs bg-yellow-100 text-yellow-800 rounded">
                                    <svg class="animate-spin -ml-1 mr-1 h-3 w-3" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    در حال اجرا
                                </span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <a href="{{ route('configs.log-details', [$config, $log]) }}"
                                   class="text-blue-600 hover:text-blue-800 text-sm">📋 جزئیات</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>

                @if($logs->hasPages())
                    <div class="px-4 py-3 border-t">
                        {{ $logs->links() }}
                    </div>
                @endif
            @else
                <div class="text-center py-12">
                    <div class="text-6xl mb-4">📊</div>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">هیچ لاگی ثبت نشده!</h3>
                    <p class="text-gray-500 mb-6">هنوز هیچ اجرایی برای این کانفیگ انجام نشده است.</p>
                    @if($config->status === 'active')
                        <div class="space-x-3 space-x-reverse">
                            <button onclick="executeBackground({{ $config->id }})"
                                    class="px-6 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                                🚀 اولین اجرای بک‌گراند
                            </button>
                            <form method="POST" action="{{ route('configs.run-sync', $config) }}" class="inline">
                                @csrf
                                <button type="submit"
                                        class="px-6 py-2 bg-orange-600 text-white rounded hover:bg-orange-700"
                                        onclick="return confirm('اجرای فوری شروع می‌شود. ادامه می‌دهید؟')">
                                    ⚡ اولین اجرای فوری
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        <!-- Bottom Stats Summary -->
        @if($totalLogs > 0)
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <h3 class="text-sm font-medium text-gray-800 mb-2">📈 خلاصه عملکرد:</h3>
                <div class="text-xs text-gray-700 space-y-1">
                    <div>• <strong>کل اجراها:</strong> {{ $totalLogs }} بار ({{ $completedLogs }} موفق، {{ $stoppedLogs }} متوقف، {{ $failedLogs }} ناموفق)</div>
                    <div>• <strong>کل رکوردهای پردازش شده:</strong> {{ number_format($totalProcessedBooks) }} رکورد</div>
                    <div>• <strong>کل کتاب‌های دریافتی:</strong> {{ number_format($totalSuccessfulBooks) }} کتاب</div>
                    <div>• <strong>کتاب‌های واقعی در دیتابیس:</strong> {{ number_format($actualBooksInDb) }} کتاب</div>
                    @if($totalProcessedBooks > 0)
                        <div>• <strong>نرخ موفقیت کلی:</strong> {{ round(($totalSuccessfulBooks / $totalProcessedBooks) * 100, 1) }}%</div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <script>
        function executeBackground(configId) {
            if (!confirm('اجرای بک‌گراند شروع می‌شود. ادامه می‌دهید؟')) return;

            fetch(`/configs/${configId}/execute-background`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                }
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('✅ ' + data.message);
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        alert('❌ ' + data.message);
                    }
                })
                .catch(() => alert('❌ خطا در شروع اجرا'));
        }
    </script>
@endsection
