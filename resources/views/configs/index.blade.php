@extends('layouts.app')
@section('title', 'کانفیگ‌ها')

@section('content')
    <div class="space-y-6">
        <!-- Worker Management Panel -->
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4">
            <div class="flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-semibold text-blue-800 mb-2">🔧 مدیریت Worker</h3>
                    <div id="worker-status" class="text-sm text-blue-700">
                        در حال بررسی وضعیت...
                    </div>
                </div>
                <div class="flex gap-2">
                    <button onclick="manageWorker('start')"
                            class="px-3 py-2 bg-green-600 text-white rounded text-sm hover:bg-green-700">
                        🚀 شروع
                    </button>
                    <button onclick="manageWorker('restart')"
                            class="px-3 py-2 bg-yellow-600 text-white rounded text-sm hover:bg-yellow-700">
                        🔄 راه‌اندازی مجدد
                    </button>
                    <button onclick="manageWorker('stop')"
                            class="px-3 py-2 bg-red-600 text-white rounded text-sm hover:bg-red-700">
                        ⏹️ توقف
                    </button>
                    <button onclick="checkWorkerStatus()"
                            class="px-3 py-2 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">
                        🔍 بررسی
                    </button>
                </div>
            </div>
        </div>

        <!-- Header -->
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-semibold">مدیریت کانفیگ‌ها</h1>
                <p class="text-gray-600">کانفیگ‌های API را مدیریت و اجرا کنید</p>
            </div>

            <div class="flex gap-3">
                <!-- Search -->
                <form method="GET" class="flex">
                    <input type="text" name="search" value="{{ $search ?? '' }}"
                           placeholder="جستجو..."
                           class="px-3 py-2 border rounded-r-md focus:ring-2 focus:ring-blue-500">
                    <button type="submit" class="px-3 py-2 bg-gray-600 text-white rounded-l-md hover:bg-gray-700">
                        🔍
                    </button>
                </form>

                <a href="{{ route('configs.create') }}"
                   class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    ➕ کانفیگ جدید
                </a>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-3 gap-4">
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-blue-600">{{ $configs->total() }}</div>
                <div class="text-sm text-gray-600">کل کانفیگ‌ها</div>
            </div>
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-green-600">{{ $configs->where('status', 'active')->count() }}</div>
                <div class="text-sm text-gray-600">فعال</div>
            </div>
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-yellow-600">{{ $configs->where('is_running', true)->count() }}</div>
                <div class="text-sm text-gray-600">در حال اجرا</div>
            </div>
        </div>

        <!-- Configs List -->
        <div class="bg-white rounded shadow overflow-hidden">
            @if($configs->count() > 0)
                <table class="w-full">
                    <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-right">نام</th>
                        <th class="px-4 py-3 text-right">وضعیت</th>
                        <th class="px-4 py-3 text-right">تنظیمات</th>
                        <th class="px-4 py-3 text-right">آخرین اجرا</th>
                        <th class="px-4 py-3 text-right">عملیات</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y">
                    @foreach($configs as $config)
                        @php
                            $lastLog = \App\Models\ExecutionLog::where('config_id', $config->id)->latest()->first();
                        @endphp
                        <tr class="hover:bg-gray-50" id="config-row-{{ $config->id }}">
                            <td class="px-4 py-3">
                                <div class="font-medium">{{ $config->name }}</div>
                                <div class="text-sm text-gray-600">{{ Str::limit($config->description ?? 'بدون توضیحات', 40) }}</div>
                                <div class="text-xs text-gray-400">{{ Str::limit($config->base_url, 50) }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-col gap-1">
                                <span class="px-2 py-1 text-xs rounded
                                    @if($config->status === 'active') bg-green-100 text-green-800
                                    @elseif($config->status === 'inactive') bg-red-100 text-red-800
                                    @else bg-yellow-100 text-yellow-800 @endif">
                                    @if($config->status === 'active') فعال
                                    @elseif($config->status === 'inactive') غیرفعال
                                    @else پیش‌نویس @endif
                                </span>

                                    <div id="running-status-{{ $config->id }}">
                                        @if($config->is_running)
                                            <span class="inline-flex items-center px-2 py-1 text-xs bg-blue-100 text-blue-800 rounded">
                                            <svg class="animate-spin -ml-1 mr-1 h-3 w-3" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            در حال اجرا
                                        </span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-xs space-y-1">
                                    <div>هر <span class="font-medium">{{ $config->delay_seconds }}</span> ثانیه</div>
                                    <div><span class="font-medium">{{ $config->records_per_run }}</span> رکورد</div>
                                    <div class="text-gray-500">
                                        ≈ {{ round((60 / $config->delay_seconds) * $config->records_per_run) }} رکورد/دقیقه
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
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
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('configs.show', $config) }}"
                                       class="text-blue-600 hover:text-blue-800" title="جزئیات">👁️</a>

                                    <a href="{{ route('configs.logs', $config) }}"
                                       class="text-green-600 hover:text-green-800" title="لاگ‌ها">📊</a>

                                    @if($config->status === 'active')
                                        <div id="action-buttons-{{ $config->id }}">
                                            @if(!$config->is_running)
                                                <button onclick="executeBackground({{ $config->id }})"
                                                        class="text-green-600 hover:text-green-800" title="اجرای بک‌گراند">🚀</button>
                                                <button onclick="runSync({{ $config->id }})"
                                                        class="text-orange-600 hover:text-orange-800" title="اجرای فوری">⚡</button>
                                            @else
                                                <button onclick="stopExecution({{ $config->id }})"
                                                        class="text-red-600 hover:text-red-800" title="متوقف کردن">⏹️</button>
                                            @endif
                                        </div>
                                    @endif

                                    <a href="{{ route('configs.edit', $config) }}"
                                       class="text-blue-600 hover:text-blue-800" title="ویرایش">✏️</a>

                                    <form method="POST" action="{{ route('configs.destroy', $config) }}" class="inline">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-red-600 hover:text-red-800"
                                                title="حذف" onclick="return confirm('حذف کامل کانفیگ؟')">🗑️</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>

                @if($configs->hasPages())
                    <div class="px-4 py-3 border-t">
                        {{ $configs->appends(request()->query())->links() }}
                    </div>
                @endif
            @else
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
                               class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700">
                                🔍 نمایش همه
                            </a>
                        @endif
                        <a href="{{ route('configs.create') }}"
                           class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                            ➕ ایجاد کانفیگ جدید
                        </a>
                    </div>
                </div>
            @endif
        </div>

        <!-- Help Tips -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <h3 class="text-sm font-medium text-blue-800 mb-2">💡 نکات مهم:</h3>
            <ul class="text-xs text-blue-700 space-y-1">
                <li><strong>🔧 Worker:</strong> ابتدا Worker را شروع کنید تا Jobs اجرا شوند.</li>
                <li><strong>🚀 اجرای بک‌گراند:</strong> بهترین گزینه برای کانفیگ‌های با تاخیر بالا.</li>
                <li><strong>⚡ اجرای فوری:</strong> فقط برای تست کانفیگ‌های سریع استفاده کنید.</li>
                <li><strong>📊 لاگ‌ها:</strong> همیشه لاگ‌ها را بررسی کنید تا از صحت اجرا مطمئن شوید.</li>
            </ul>
        </div>
    </div>

    <script>
        let workerStatusInterval;

        document.addEventListener('DOMContentLoaded', function() {
            checkWorkerStatus();
            workerStatusInterval = setInterval(checkWorkerStatus, 10000);
        });

        function checkWorkerStatus() {
            fetch('/configs/worker/status', {
                method: 'GET',
                headers: { 'Accept': 'application/json' }
            })
                .then(response => response.json())
                .then(data => {
                    const statusElement = document.getElementById('worker-status');
                    const workerStatus = data.worker_status;
                    const queueStats = data.queue_stats;

                    const statusText = workerStatus.is_running ?
                        `✅ Worker فعال (PID: ${workerStatus.pid})` :
                        '❌ Worker غیرفعال';

                    statusElement.innerHTML = `
            <div class="space-y-1">
                <div class="font-medium">${statusText}</div>
                <div class="text-xs">
                    📊 Jobs در صف: ${queueStats.pending_jobs} | ❌ شکست خورده: ${queueStats.failed_jobs}
                </div>
            </div>
        `;
                })
                .catch(() => {
                    document.getElementById('worker-status').innerHTML = '⚠️ خطا در بررسی وضعیت Worker';
                });
        }

        function manageWorker(action) {
            const configId = document.querySelector('[id^="config-row-"]')?.id.replace('config-row-', '') || 1;

            showNotification('در حال ' + action + ' Worker...', 'warning');

            fetch(`/configs/${configId}/worker/manage`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ action: action })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message, 'success');
                        checkWorkerStatus();
                    } else {
                        showNotification(data.message, 'error');
                    }
                })
                .catch(() => showNotification('خطا در مدیریت Worker', 'error'));
        }

        function executeBackground(configId) {
            if (!confirm('اجرا در پس‌زمینه شروع می‌شود. ادامه می‌دهید؟')) return;

            showNotification('در حال شروع اجرا...', 'warning');

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
                        showNotification(data.message, 'success');
                        updateConfigStatus(configId, 'running');
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        showNotification(data.message, 'error');
                    }
                })
                .catch(() => showNotification('خطا در شروع اجرا', 'error'));
        }

        function stopExecution(configId) {
            if (!confirm('آیا از توقف اجرا اطمینان دارید؟')) return;

            showNotification('در حال توقف اجرا...', 'warning');

            fetch(`/configs/${configId}/stop-execution`, {
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
                        showNotification(data.message, 'success');
                        updateConfigStatus(configId, 'stopped');
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        showNotification(data.message, 'error');
                    }
                })
                .catch(() => showNotification('خطا در توقف اجرا', 'error'));
        }

        function runSync(configId) {
            if (!confirm('اجرای فوری ممکن است سایت را کند کند. ادامه می‌دهید؟')) return;
            window.location.href = `/configs/${configId}/run-sync`;
        }

        function updateConfigStatus(configId, status) {
            const runningStatusElement = document.getElementById(`running-status-${configId}`);
            const actionButtonsElement = document.getElementById(`action-buttons-${configId}`);

            if (status === 'running') {
                runningStatusElement.innerHTML = `
            <span class="inline-flex items-center px-2 py-1 text-xs bg-blue-100 text-blue-800 rounded">
                <svg class="animate-spin -ml-1 mr-1 h-3 w-3" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                در حال اجرا
            </span>
        `;
                actionButtonsElement.innerHTML = `
            <button onclick="stopExecution(${configId})" class="text-red-600 hover:text-red-800" title="متوقف کردن">⏹️</button>
        `;
            } else if (status === 'stopped') {
                runningStatusElement.innerHTML = '';
                actionButtonsElement.innerHTML = `
            <button onclick="executeBackground(${configId})" class="text-green-600 hover:text-green-800" title="اجرای بک‌گراند">🚀</button>
            <button onclick="runSync(${configId})" class="text-orange-600 hover:text-orange-800" title="اجرای فوری">⚡</button>
        `;
            }
        }

        function showNotification(message, type = 'success') {
            const container = document.getElementById('notifications');
            const notification = document.createElement('div');

            const colors = {
                success: 'bg-green-100 text-green-800 border-green-200',
                error: 'bg-red-100 text-red-800 border-red-200',
                warning: 'bg-yellow-100 text-yellow-800 border-yellow-200'
            };

            notification.className = `notification border rounded p-3 mb-2 ${colors[type]}`;
            notification.innerHTML = `
        <div class="flex items-center justify-between">
            <span>${message}</span>
            <button onclick="this.parentElement.parentElement.remove()" class="font-bold">✕</button>
        </div>
    `;

            container.appendChild(notification);

            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 5000);
        }

        window.addEventListener('beforeunload', function() {
            if (workerStatusInterval) {
                clearInterval(workerStatusInterval);
            }
        });
    </script>
@endsection
