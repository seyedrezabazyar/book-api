@extends('layouts.app')

@section('title', 'مدیریت کانفیگ‌ها')

@section('content')
    <div class="container mx-auto px-4 py-6">
        <!-- پنل مدیریت Worker -->
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4 mb-6">
            <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center">
                <div class="mb-4 lg:mb-0">
                    <h3 class="text-lg font-semibold text-blue-800 mb-2">🔧 مدیریت Worker</h3>
                    <div id="worker-status" class="text-sm text-blue-700">
                        در حال بررسی وضعیت Worker...
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button onclick="manageWorker('start')"
                            class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 text-sm transition-colors">
                        🚀 شروع Worker
                    </button>
                    <button onclick="manageWorker('restart')"
                            class="px-4 py-2 bg-yellow-600 text-white rounded-md hover:bg-yellow-700 text-sm transition-colors">
                        🔄 راه‌اندازی مجدد
                    </button>
                    <button onclick="manageWorker('stop')"
                            class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 text-sm transition-colors">
                        ⏹️ توقف Worker
                    </button>
                    <button onclick="checkWorkerStatus()"
                            class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 text-sm transition-colors">
                        🔍 بررسی وضعیت
                    </button>
                </div>
            </div>
        </div>

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

        @if(session('warning'))
            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                {{ session('warning') }}
            </div>
        @endif

        <!-- نوتیفیکیشن‌های Ajax -->
        <div id="ajax-notifications" class="mb-4"></div>

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

                            <tr class="hover:bg-gray-50" id="config-row-{{ $config->id }}">
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
                                    <div class="flex flex-col space-y-1">
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                            @if($config->status === 'active') bg-green-100 text-green-800
                                            @elseif($config->status === 'inactive') bg-red-100 text-red-800
                                            @else bg-yellow-100 text-yellow-800 @endif">
                                            @if($config->status === 'active') فعال
                                            @elseif($config->status === 'inactive') غیرفعال
                                            @else پیش‌نویس @endif
                                        </span>

                                        <div id="running-status-{{ $config->id }}">
                                            @if($config->is_running)
                                                <span class="inline-flex items-center px-2 py-1 text-xs bg-blue-100 text-blue-800 rounded-full">
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
                                            <div id="action-buttons-{{ $config->id }}">
                                                @if(!$config->is_running)
                                                    <!-- اجرای بک‌گراند -->
                                                    <button onclick="executeBackground({{ $config->id }})"
                                                            class="text-green-600 hover:text-green-900 p-1 rounded"
                                                            title="اجرای بک‌گراند (بهینه)">
                                                        🚀
                                                    </button>

                                                    <!-- اجرای فوری -->
                                                    <button onclick="runSync({{ $config->id }})"
                                                            class="text-orange-600 hover:text-orange-900 p-1 rounded"
                                                            title="اجرای فوری (کند)">
                                                        ⚡
                                                    </button>
                                                @else
                                                    <!-- متوقف کردن -->
                                                    <button onclick="stopExecution({{ $config->id }})"
                                                            class="text-red-600 hover:text-red-900 p-1 rounded"
                                                            title="متوقف کردن">
                                                        ⏹️
                                                    </button>
                                                @endif
                                            </div>
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
                        $totalRunning = $configs->where('is_running', true)->count();
                    @endphp

                    <div class="grid grid-cols-3 gap-4">
                        <div class="text-center">
                            <div class="text-lg font-bold text-gray-800">{{ $configs->total() }}</div>
                            <div class="text-xs text-gray-500">کل کانفیگ‌ها</div>
                        </div>
                        <div class="text-center">
                            <div class="text-lg font-bold text-green-600">{{ $totalActive }}</div>
                            <div class="text-xs text-gray-500">فعال</div>
                        </div>
                        <div class="text-center">
                            <div class="text-lg font-bold text-blue-600">{{ $totalRunning }}</div>
                            <div class="text-xs text-gray-500">در حال اجرا</div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <!-- راهنمای اجرا -->
        <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
            <h3 class="text-sm font-medium text-blue-800 mb-2">💡 نکات مهم:</h3>
            <ul class="text-xs text-blue-700 space-y-1 list-disc list-inside">
                <li><strong>🔧 Worker:</strong> ابتدا Worker را شروع کنید تا Jobs اجرا شوند.</li>
                <li><strong>🚀 اجرای بک‌گراند:</strong> بهترین گزینه برای کانفیگ‌های با تاخیر بالا. سرعت سایت حفظ می‌شود.</li>
                <li><strong>⚡ اجرای فوری:</strong> فقط برای تست کانفیگ‌های سریع استفاده کنید.</li>
                <li><strong>📊 لاگ‌ها:</strong> همیشه لاگ‌ها را بررسی کنید تا از صحت اجرا مطمئن شوید.</li>
                <li><strong>⚙️ تنظیمات بهینه:</strong> تاخیر 5-30 ثانیه برای سرورهای عمومی توصیه می‌شود.</li>
            </ul>
        </div>
    </div>

    <style>
        /* نوتیفیکیشن‌ها */
        .notification {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 8px;
            animation: slideIn 0.3s ease-out;
        }

        .notification.success {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }

        .notification.error {
            background: #fef2f2;
            border: 1px solid #fca5a5;
            color: #991b1b;
        }

        .notification.warning {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>

    <script>
        // متغیرهای سراسری
        let workerStatusInterval;

        // شروع بررسی وضعیت Worker
        document.addEventListener('DOMContentLoaded', function() {
            checkWorkerStatus();
            workerStatusInterval = setInterval(checkWorkerStatus, 10000);
        });

        // بررسی وضعیت Worker
        function checkWorkerStatus() {
            fetch('/configs/worker/status', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                }
            })
                .then(response => response.json())
                .then(data => {
                    updateWorkerStatus(data);
                })
                .catch(error => {
                    document.getElementById('worker-status').innerHTML = '⚠️ خطا در بررسی وضعیت Worker';
                });
        }

        // بروزرسانی نمایش وضعیت Worker
        function updateWorkerStatus(data) {
            const statusElement = document.getElementById('worker-status');
            const workerStatus = data.worker_status;
            const queueStats = data.queue_stats;

            const statusText = workerStatus.is_running ?
                `✅ Worker فعال (PID: ${workerStatus.pid})` :
                '❌ Worker غیرفعال';

            statusElement.innerHTML = `
                <div class="space-y-1">
                    <div class="font-medium">${statusText}</div>
                    <div class="text-xs space-y-0.5">
                        <div>📊 Jobs در صف: ${queueStats.pending_jobs}</div>
                        <div>❌ Jobs شکست خورده: ${queueStats.failed_jobs}</div>
                    </div>
                </div>
            `;
        }

        // مدیریت Worker
        function manageWorker(action) {
            const configId = document.querySelector('[id^="config-row-"]')?.id.replace('config-row-', '') || 1;

            showNotification('در حال ' + action + ' Worker...', 'warning');

            fetch(`/configs/${configId}/worker/manage`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
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
                .catch(error => {
                    showNotification('خطا در مدیریت Worker', 'error');
                });
        }

        // اجرای بک‌گراند کانفیگ
        function executeBackground(configId) {
            if (!confirm('اجرا در پس‌زمینه شروع می‌شود. ادامه می‌دهید؟')) {
                return;
            }

            showNotification('در حال شروع اجرا...', 'warning');

            fetch(`/configs/${configId}/execute-background`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
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
                .catch(error => {
                    showNotification('خطا در شروع اجرا', 'error');
                });
        }

        // توقف اجرای کانفیگ - اینجا route درست شد!
        function stopExecution(configId) {
            if (!confirm('آیا از توقف اجرا اطمینان دارید؟')) {
                return;
            }

            showNotification('در حال توقف اجرا...', 'warning');

            fetch(`/configs/${configId}/stop-execution`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
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
                .catch(error => {
                    showNotification('خطا در توقف اجرا', 'error');
                    console.error('Error:', error);
                });
        }

        // اجرای فوری
        function runSync(configId) {
            if (!confirm('اجرای فوری ممکن است سایت را کند کند. ادامه می‌دهید؟')) {
                return;
            }
            window.location.href = `/configs/${configId}/run-sync`;
        }

        // بروزرسانی وضعیت کانفیگ در UI
        function updateConfigStatus(configId, status) {
            const runningStatusElement = document.getElementById(`running-status-${configId}`);
            const actionButtonsElement = document.getElementById(`action-buttons-${configId}`);

            if (status === 'running') {
                runningStatusElement.innerHTML = `
                    <span class="inline-flex items-center px-2 py-1 text-xs bg-blue-100 text-blue-800 rounded-full">
                        <svg class="animate-spin -ml-1 mr-1 h-3 w-3" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        در حال اجرا
                    </span>
                `;

                actionButtonsElement.innerHTML = `
                    <button onclick="stopExecution(${configId})"
                            class="text-red-600 hover:text-red-900 p-1 rounded"
                            title="متوقف کردن">
                        ⏹️
                    </button>
                `;
            } else if (status === 'stopped') {
                runningStatusElement.innerHTML = '';

                actionButtonsElement.innerHTML = `
                    <button onclick="executeBackground(${configId})"
                            class="text-green-600 hover:text-green-900 p-1 rounded"
                            title="اجرای بک‌گراند (بهینه)">
                        🚀
                    </button>
                    <button onclick="runSync(${configId})"
                            class="text-orange-600 hover:text-orange-900 p-1 rounded"
                            title="اجرای فوری (کند)">
                        ⚡
                    </button>
                `;
            }
        }

        // نمایش نوتیفیکیشن
        function showNotification(message, type = 'success') {
            const notificationsContainer = document.getElementById('ajax-notifications');

            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = message.replace(/\n/g, '<br>');

            notificationsContainer.appendChild(notification);

            // حذف خودکار بعد از 5 ثانیه
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.parentNode.removeChild(notification);
                }
            }, 5000);
        }

        // پاک کردن interval هنگام خروج از صفحه
        window.addEventListener('beforeunload', function() {
            if (workerStatusInterval) {
                clearInterval(workerStatusInterval);
            }
        });
    </script>

    <!-- Meta tag برای CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">
@endsection
