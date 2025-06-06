@extends('layouts.app')

@section('title', 'جزئیات لاگ اجرا')

@section('content')
    <div class="container mx-auto px-4 py-6">
        <!-- هدر صفحه -->
        <div class="mb-6">
            <div class="flex items-center mb-4">
                <a
                    href="{{ route('configs.logs', $config) }}"
                    class="text-gray-600 hover:text-gray-800 ml-4"
                    title="بازگشت به لاگ‌ها"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">جزئیات لاگ اجرا</h1>
                    <p class="text-gray-600">{{ $config->name }} - {{ $log->execution_id }}</p>
                </div>
            </div>
        </div>

        <!-- اطلاعات کلی -->
        <div class="bg-white rounded-lg shadow mb-6 p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">اطلاعات کلی</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div>
                    <div class="text-sm text-gray-500">شناسه اجرا</div>
                    <div class="text-sm font-mono text-gray-900">{{ $log->execution_id }}</div>
                </div>

                <div>
                    <div class="text-sm text-gray-500">وضعیت</div>
                    <div>
                        @if($log->status === 'completed')
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                ✅ تمام شده
                            </span>
                        @elseif($log->status === 'failed')
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                ❌ ناموفق
                            </span>
                        @else
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                🔄 در حال اجرا
                            </span>
                        @endif
                    </div>
                </div>

                <div>
                    <div class="text-sm text-gray-500">زمان شروع</div>
                    <div class="text-sm text-gray-900">{{ $log->started_at->format('Y/m/d H:i:s') }}</div>
                    <div class="text-xs text-gray-500">{{ $log->started_at->diffForHumans() }}</div>
                </div>

                @if($log->finished_at)
                    <div>
                        <div class="text-sm text-gray-500">زمان پایان</div>
                        <div class="text-sm text-gray-900">{{ $log->finished_at->format('Y/m/d H:i:s') }}</div>
                    </div>
                @endif

                @if($log->execution_time)
                    <div>
                        <div class="text-sm text-gray-500">مدت زمان اجرا</div>
                        <div class="text-sm font-medium text-gray-900">{{ $log->execution_time }} ثانیه</div>
                    </div>
                @endif
            </div>
        </div>

        <!-- آمار اجرا -->
        @if($log->status === 'completed')
            <div class="bg-white rounded-lg shadow mb-6 p-6">
                <h2 class="text-lg font-medium text-gray-900 mb-4">آمار اجرا</h2>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                    <!-- کل رکوردها -->
                    <div class="text-center p-4 bg-blue-50 rounded-lg">
                        <div class="text-2xl font-bold text-blue-600">{{ number_format($log->total_processed) }}</div>
                        <div class="text-sm text-blue-800">کل رکوردها</div>
                    </div>

                    <!-- موفق -->
                    <div class="text-center p-4 bg-green-50 rounded-lg">
                        <div class="text-2xl font-bold text-green-600">{{ number_format($log->total_success) }}</div>
                        <div class="text-sm text-green-800">موفق</div>
                    </div>

                    <!-- تکراری -->
                    <div class="text-center p-4 bg-yellow-50 rounded-lg">
                        <div class="text-2xl font-bold text-yellow-600">{{ number_format($log->total_duplicate) }}</div>
                        <div class="text-sm text-yellow-800">تکراری</div>
                    </div>

                    <!-- خطا -->
                    <div class="text-center p-4 bg-red-50 rounded-lg">
                        <div class="text-2xl font-bold text-red-600">{{ number_format($log->total_failed) }}</div>
                        <div class="text-sm text-red-800">خطا</div>
                    </div>
                </div>

                <!-- نرخ موفقیت -->
                @if($log->total_processed > 0)
                    <div class="mt-6">
                        <div class="text-sm text-gray-500 mb-2">نرخ موفقیت</div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5">
                            <div class="bg-green-600 h-2.5 rounded-full" style="width: {{ $log->success_rate }}%"></div>
                        </div>
                        <div class="text-sm text-gray-600 mt-1">{{ $log->success_rate }}%</div>
                    </div>
                @endif
            </div>
        @endif

        <!-- خطای اجرا -->
        @if($log->status === 'failed' && $log->error_message)
            <div class="bg-white rounded-lg shadow mb-6 p-6">
                <h2 class="text-lg font-medium text-red-800 mb-4">خطای اجرا</h2>

                <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                    <div class="text-red-800">
                        <strong>پیام خطا:</strong>
                        <pre class="mt-2 text-sm whitespace-pre-wrap">{{ $log->error_message }}</pre>
                    </div>
                </div>
            </div>
        @endif

        <!-- جزئیات لاگ -->
        @if($log->log_details && count($log->log_details) > 0)
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-medium text-gray-900">جزئیات لاگ اجرا</h2>
                    <button
                        onclick="toggleAllLogs()"
                        class="text-sm text-blue-600 hover:text-blue-800"
                    >
                        باز/بسته کردن همه
                    </button>
                </div>

                <div class="space-y-3 max-h-96 overflow-y-auto">
                    @foreach($log->log_details as $index => $logEntry)
                        <div class="border border-gray-200 rounded-lg">
                            <div
                                class="p-3 cursor-pointer hover:bg-gray-50 flex items-center justify-between"
                                onclick="toggleLog({{ $index }})"
                            >
                                <div class="flex items-center">
                                    <span class="text-xs text-gray-500 mr-3">
                                        {{ \Carbon\Carbon::parse($logEntry['timestamp'])->format('H:i:s') }}
                                    </span>
                                    <span class="text-sm text-gray-900">{{ $logEntry['message'] }}</span>
                                </div>
                                <svg class="w-4 h-4 text-gray-400 transform transition-transform" id="icon-{{ $index }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>

                            @if(!empty($logEntry['context']))
                                <div class="hidden px-3 pb-3" id="content-{{ $index }}">
                                    <div class="bg-gray-50 rounded p-3">
                                        <pre class="text-xs text-gray-700 whitespace-pre-wrap">{{ json_encode($logEntry['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <script>
        function toggleLog(index) {
            const content = document.getElementById(`content-${index}`);
            const icon = document.getElementById(`icon-${index}`);

            if (content.classList.contains('hidden')) {
                content.classList.remove('hidden');
                icon.style.transform = 'rotate(180deg)';
            } else {
                content.classList.add('hidden');
                icon.style.transform = 'rotate(0deg)';
            }
        }

        function toggleAllLogs() {
            const allContents = document.querySelectorAll('[id^="content-"]');
            const allIcons = document.querySelectorAll('[id^="icon-"]');

            let allHidden = true;
            allContents.forEach(content => {
                if (!content.classList.contains('hidden')) {
                    allHidden = false;
                }
            });

            allContents.forEach((content, index) => {
                if (allHidden) {
                    content.classList.remove('hidden');
                    allIcons[index].style.transform = 'rotate(180deg)';
                } else {
                    content.classList.add('hidden');
                    allIcons[index].style.transform = 'rotate(0deg)';
                }
            });
        }
    </script>
@endsection
