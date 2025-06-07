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
            $totalSuccessfulBooks = \App\Models\ExecutionLog::where('config_id', $config->id)->where('status', 'completed')->sum('total_success');
        @endphp

        <div class="grid grid-cols-4 gap-4">
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-blue-600">{{ number_format($totalLogs) }}</div>
                <div class="text-sm text-gray-600">کل اجراها</div>
            </div>
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-green-600">{{ number_format($completedLogs) }}</div>
                <div class="text-sm text-gray-600">موفق</div>
            </div>
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-red-600">{{ number_format($failedLogs) }}</div>
                <div class="text-sm text-gray-600">ناموفق</div>
            </div>
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-purple-600">{{ number_format($totalSuccessfulBooks) }}</div>
                <div class="text-sm text-gray-600">کل کتاب‌های دریافتی</div>
            </div>
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
                        <th class="px-4 py-3 text-right">آمار</th>
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
                            </td>
                            <td class="px-4 py-3">
                                @if($log->execution_time)
                                    <div class="text-sm">{{ $log->execution_time }}s</div>
                                @elseif($log->status === 'running')
                                    <div class="text-sm text-yellow-600">در حال اجرا...</div>
                                @else
                                    <div class="text-sm text-gray-400">-</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
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
                            <td class="px-4 py-3">
                                @if($log->status === 'completed')
                                    <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">✅ تمام شده</span>
                                @elseif($log->status === 'failed')
                                    <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded">❌ ناموفق</span>
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
                        <form method="POST" action="{{ route('configs.run-sync', $config) }}" class="inline">
                            @csrf
                            <button type="submit"
                                    class="px-6 py-2 bg-orange-600 text-white rounded hover:bg-orange-700"
                                    onclick="return confirm('اجرای فوری شروع می‌شود. ادامه می‌دهید؟')">
                                ⚡ اولین اجرای فوری
                            </button>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endsection
