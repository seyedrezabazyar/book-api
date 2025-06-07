@extends('layouts.app')
@section('title', 'جزئیات لاگ اجرا')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center gap-4">
            <a href="{{ route('configs.logs', $config) }}" class="text-gray-600 hover:text-gray-800">←</a>
            <div>
                <h1 class="text-2xl font-semibold">جزئیات لاگ اجرا</h1>
                <p class="text-gray-600">{{ $config->name }} - {{ $log->execution_id }}</p>
            </div>

            <!-- دکمه رفرش برای بروزرسانی آمار -->
            <button onclick="location.reload()"
                class="ml-auto px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">
                🔄 رفرش
            </button>
        </div>

        @php
            // محاسبه زمان اجرا صحیح - اصلاح شده
            $executionTimeSeconds = 0;

            if ($log->execution_time && $log->execution_time > 0) {
                $executionTimeSeconds = $log->execution_time;
            } elseif ($log->started_at && $log->finished_at) {
                $executionTimeSeconds = max(0, $log->finished_at->diffInSeconds($log->started_at));
            } elseif ($log->started_at && $log->status === 'running') {
                $executionTimeSeconds = max(0, now()->diffInSeconds($log->started_at));
            }

            // اصلاح وضعیت در صورت نیاز (نمایش موقت)
            $displayStatus = $log->status;
            if ($log->status === 'running' && $config->is_running === false) {
                $displayStatus = 'stopped';
            }
        @endphp

        <!-- Alert برای مشکلات احتمالی -->
        @if ($log->status === 'running' && !$config->is_running)
            <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                <div class="flex items-center">
                    <span class="text-yellow-600">⚠️</span>
                    <div class="ml-2">
                        <h3 class="text-yellow-800 font-medium">وضعیت نامطابق شناسایی شد</h3>
                        <p class="text-yellow-700 text-sm">این لاگ به عنوان "در حال اجرا" ثبت شده اما کانفیگ متوقف است.
                            <button onclick="fixLogStatus({{ $log->id }})" class="underline">اصلاح خودکار</button>
                        </p>
                    </div>
                </div>
            </div>
        @endif

        @if ($executionTimeSeconds < 0)
            <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                <div class="flex items-center">
                    <span class="text-red-600">❌</span>
                    <div class="ml-2">
                        <h3 class="text-red-800 font-medium">زمان اجرا نامعتبر</h3>
                        <p class="text-red-700 text-sm">زمان اجرا منفی محاسبه شده است. این نشان‌دهنده مشکل در ثبت زمان‌هاست.
                        </p>
                    </div>
                </div>
            </div>
        @endif

        <!-- Basic Info -->
        <div class="bg-white rounded shadow p-6">
            <h2 class="text-lg font-medium mb-4">اطلاعات کلی</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div>
                    <div class="text-sm text-gray-600">شناسه اجرا</div>
                    <div class="text-sm font-mono">{{ $log->execution_id }}</div>
                    <div class="text-xs text-gray-500">ID: {{ $log->id }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-600">وضعیت</div>
                    <div>
                        @if ($displayStatus === 'completed')
                            <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">✅ تمام شده</span>
                        @elseif($displayStatus === 'failed')
                            <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded">❌ ناموفق</span>
                        @elseif($displayStatus === 'stopped')
                            <span class="px-2 py-1 text-xs bg-orange-100 text-orange-800 rounded">⏹️ متوقف شده</span>
                        @else
                            <span class="px-2 py-1 text-xs bg-yellow-100 text-yellow-800 rounded">🔄 در حال اجرا</span>
                        @endif
                    </div>
                    @if ($log->status !== $displayStatus)
                        <div class="text-xs text-gray-500">(وضعیت واقعی: {{ $log->status }})</div>
                    @endif
                </div>
                <div>
                    <div class="text-sm text-gray-600">زمان شروع</div>
                    <div class="text-sm">{{ $log->started_at->format('Y/m/d H:i:s') }}</div>
                    <div class="text-xs text-gray-500">{{ $log->started_at->diffForHumans() }}</div>
                </div>
                @if ($log->finished_at)
                    <div>
                        <div class="text-sm text-gray-600">زمان پایان</div>
                        <div class="text-sm">{{ $log->finished_at->format('Y/m/d H:i:s') }}</div>
                        <div class="text-xs text-gray-500">{{ $log->finished_at->diffForHumans() }}</div>
                    </div>
                @endif
                <div>
                    <div class="text-sm text-gray-600">مدت زمان اجرا</div>
                    @if ($executionTimeSeconds > 0)
                        <div class="text-sm font-medium">{{ round($executionTimeSeconds) }} ثانیه</div>
                        @if ($executionTimeSeconds > 60)
                            <div class="text-xs text-gray-500">≈ {{ round($executionTimeSeconds / 60, 1) }} دقیقه</div>
                        @endif
                    @else
                        <div class="text-sm text-red-600">نامعتبر ({{ round($executionTimeSeconds, 2) }}s)</div>
                    @endif
                </div>
                @if ($log->current_page)
                    <div>
                        <div class="text-sm text-gray-600">صفحه فعلی</div>
                        <div class="text-sm">{{ $log->current_page }}</div>
                        @if ($log->total_pages)
                            <div class="text-xs text-gray-500">از {{ $log->total_pages }}</div>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        <!-- Stats -->
        @if ($log->status !== 'running' || $log->total_processed > 0)
            <div class="bg-white rounded shadow p-6">
                <h2 class="text-lg font-medium mb-4">آمار اجرا</h2>

                @if ($log->total_processed > 0)
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                        <div class="text-center p-4 bg-blue-50 rounded">
                            <div class="text-2xl font-bold text-blue-600">{{ number_format($log->total_processed) }}</div>
                            <div class="text-sm text-blue-800">کل رکوردها</div>
                        </div>
                        <div class="text-center p-4 bg-green-50 rounded">
                            <div class="text-2xl font-bold text-green-600">{{ number_format($log->total_success) }}</div>
                            <div class="text-sm text-green-800">موفق</div>
                        </div>
                        @if ($log->total_duplicate > 0)
                            <div class="text-center p-4 bg-yellow-50 rounded">
                                <div class="text-2xl font-bold text-yellow-600">{{ number_format($log->total_duplicate) }}
                                </div>
                                <div class="text-sm text-yellow-800">تکراری</div>
                            </div>
                        @endif
                        <div class="text-center p-4 bg-red-50 rounded">
                            <div class="text-2xl font-bold text-red-600">{{ number_format($log->total_failed) }}</div>
                            <div class="text-sm text-red-800">خطا</div>
                        </div>
                    </div>

                    <div class="mt-6">
                        <div class="text-sm text-gray-500 mb-2">نرخ موفقیت</div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5">
                            <div class="bg-green-600 h-2.5 rounded-full"
                                style="width: {{ $log->success_rate ?? ($log->total_processed > 0 ? round(($log->total_success / $log->total_processed) * 100, 1) : 0) }}%">
                            </div>
                        </div>
                        <div class="text-sm text-gray-600 mt-1">
                            {{ $log->success_rate ?? ($log->total_processed > 0 ? round(($log->total_success / $log->total_processed) * 100, 1) : 0) }}%
                        </div>
                    </div>
                @else
                    <!-- نمایش آمار از کانفیگ اگر در لاگ موجود نیست -->
                    <div class="bg-yellow-50 border border-yellow-200 rounded p-4">
                        <h3 class="text-yellow-800 font-medium mb-2">⚠️ آمار لاگ ناقص - نمایش آمار کانفیگ:</h3>
                        <div class="grid grid-cols-3 gap-4 text-sm">
                            <div>
                                <span class="text-gray-600">کل پردازش شده:</span>
                                <span class="font-medium">{{ number_format($config->total_processed) }}</span>
                            </div>
                            <div>
                                <span class="text-gray-600">موفق:</span>
                                <span class="font-medium text-green-600">{{ number_format($config->total_success) }}</span>
                            </div>
                            <div>
                                <span class="text-gray-600">خطا:</span>
                                <span class="font-medium text-red-600">{{ number_format($config->total_failed) }}</span>
                            </div>
                        </div>
                        <button onclick="syncLogStats({{ $log->id }})"
                            class="mt-2 px-3 py-1 bg-yellow-600 text-white rounded text-sm hover:bg-yellow-700">
                            🔄 همگام‌سازی آمار لاگ
                        </button>
                    </div>
                @endif
            </div>
        @endif

        <!-- Error -->
        @if ($log->status === 'failed' && $log->error_message)
            <div class="bg-white rounded shadow p-6">
                <h2 class="text-lg font-medium text-red-800 mb-4">خطای اجرا</h2>
                <div class="bg-red-50 border border-red-200 rounded p-4">
                    <div class="text-red-800">
                        <strong>پیام خطا:</strong>
                        <pre class="mt-2 text-sm whitespace-pre-wrap">{{ $log->error_message }}</pre>
                    </div>
                </div>
            </div>
        @endif

        <!-- Stop Reason -->
        @if ($log->status === 'stopped' && $log->stop_reason)
            <div class="bg-white rounded shadow p-6">
                <h2 class="text-lg font-medium text-orange-800 mb-4">دلیل توقف</h2>
                <div class="bg-orange-50 border border-orange-200 rounded p-4">
                    <div class="text-orange-800">
                        <strong>دلیل:</strong> {{ $log->stop_reason }}
                    </div>
                </div>
            </div>
        @endif

        <!-- Performance Stats -->
        @if ($log->performance_stats || $log->records_per_minute)
            <div class="bg-white rounded shadow p-6">
                <h2 class="text-lg font-medium mb-4">آمار عملکرد</h2>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
                    @if ($log->records_per_minute)
                        <div>
                            <span class="text-gray-600">سرعت پردازش:</span>
                            <span class="font-medium">{{ $log->records_per_minute }} رکورد/دقیقه</span>
                        </div>
                    @endif
                    @if ($executionTimeSeconds > 0 && $log->total_processed > 0)
                        <div>
                            <span class="text-gray-600">میانگین زمان هر رکورد:</span>
                            <span class="font-medium">{{ round($executionTimeSeconds / $log->total_processed, 2) }}
                                ثانیه</span>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        <!-- Log Details -->
        @if ($log->log_details && count($log->log_details) > 0)
            <div class="bg-white rounded shadow p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-medium">جزئیات لاگ اجرا ({{ count($log->log_details) }} ورودی)</h2>
                    <button onclick="toggleAllLogs()" class="text-sm text-blue-600 hover:text-blue-800">
                        باز/بسته کردن همه
                    </button>
                </div>
                <div class="space-y-3 max-h-96 overflow-y-auto">
                    @foreach ($log->log_details as $index => $logEntry)
                        <div class="border rounded">
                            <div class="p-3 cursor-pointer hover:bg-gray-50 flex items-center justify-between"
                                onclick="toggleLog({{ $index }})">
                                <div class="flex items-center">
                                    <span class="text-xs text-gray-500 mr-3">
                                        {{ \Carbon\Carbon::parse($logEntry['timestamp'])->format('H:i:s') }}
                                    </span>
                                    <span class="text-sm">{{ $logEntry['message'] }}</span>

                                    <!-- نمایش خلاصه آمار در کنار پیام -->
                                    @if (isset($logEntry['context']) && is_array($logEntry['context']))
                                        @if (isset($logEntry['context']['total']) || isset($logEntry['context']['success']))
                                            <span class="text-xs text-blue-600 mr-2">
                                                [{{ $logEntry['context']['total'] ?? 0 }} کل،
                                                {{ $logEntry['context']['success'] ?? 0 }} موفق]
                                            </span>
                                        @endif
                                    @endif
                                </div>
                                <svg class="w-4 h-4 text-gray-400 transform transition-transform"
                                    id="icon-{{ $index }}" fill="none" stroke="currentColor"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
                            @if (!empty($logEntry['context']))
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
        @else
            <div class="bg-white rounded shadow p-6">
                <h2 class="text-lg font-medium mb-4">جزئیات لاگ اجرا</h2>
                <div class="text-center py-8 text-gray-500">
                    <div class="text-4xl mb-2">📝</div>
                    <p>هیچ جزئیات لاگی ثبت نشده است</p>
                    <p class="text-sm mt-1">این ممکن است به دلیل قطع ناگهانی اجرا باشد</p>
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

        function fixLogStatus(logId) {
            if (!confirm('آیا می‌خواهید وضعیت این لاگ را اصلاح کنید؟')) return;

            fetch(`/admin/logs/${logId}/fix-status`, {
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
                        alert('✅ وضعیت لاگ اصلاح شد!');
                        location.reload();
                    } else {
                        alert('❌ خطا: ' + data.message);
                    }
                })
                .catch(() => alert('❌ خطا در اصلاح وضعیت'));
        }

        function syncLogStats(logId) {
            if (!confirm('آیا می‌خواهید آمار این لاگ را با کانفیگ همگام‌سازی کنید؟')) return;

            fetch(`/admin/logs/${logId}/sync-stats`, {
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
                        alert('✅ آمار لاگ همگام‌سازی شد!');
                        location.reload();
                    } else {
                        alert('❌ خطا: ' + data.message);
                    }
                })
                .catch(() => alert('❌ خطا در همگام‌سازی آمار'));
        }
    </script>
@endsection
