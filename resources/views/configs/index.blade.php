@extends('layouts.app')
@section('title', 'مدیریت کانفیگ‌ها')

@section('content')
    <!-- Worker Status -->
    <div class="bg-white rounded shadow p-4 mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-lg font-medium">🔧 مدیریت Worker</h2>
                <div class="flex items-center gap-4 mt-2">
                    @if ($workerStatus['is_running'])
                        <span class="text-green-600">✅ Worker فعال</span>
                    @else
                        <span class="text-red-600">❌ Worker غیرفعال</span>
                    @endif
                    <span class="text-sm text-gray-600">
                        📊 Jobs در صف: {{ $workerStatus['pending_jobs'] }} |
                        @if ($workerStatus['failed_jobs'] > 0)
                            ❌ شکست خورده: {{ $workerStatus['failed_jobs'] }}
                        @else
                            ✅ شکست خورده: 0
                        @endif
                    </span>
                </div>
            </div>
            <div class="flex gap-2">
                @if (!$workerStatus['is_running'])
                    <button onclick="startWorker()"
                        class="px-3 py-1 bg-green-600 text-white rounded text-sm hover:bg-green-700">
                        🚀 شروع
                    </button>
                @endif
                <button onclick="restartWorker()"
                    class="px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">
                    🔄 راه‌اندازی مجدد
                </button>
                @if ($workerStatus['is_running'])
                    <button onclick="stopWorker()" class="px-3 py-1 bg-red-600 text-white rounded text-sm hover:bg-red-700">
                        ⏹️ توقف
                    </button>
                @endif
                <button onclick="checkWorker()" class="px-3 py-1 bg-gray-600 text-white rounded text-sm hover:bg-gray-700">
                    🔍 بررسی
                </button>
            </div>
        </div>
    </div>

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-semibold">مدیریت کانفیگ‌ها</h1>
            <p class="text-gray-600">کانفیگ‌های API را مدیریت و اجرا کنید</p>
        </div>
        <div class="flex items-center gap-4">
            <!-- Add New Config -->
            <a href="{{ route('configs.create') }}" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                ➕ کانفیگ جدید
            </a>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
        <div class="bg-white p-6 rounded shadow">
            <div class="flex items-center">
                <div class="text-3xl font-bold text-blue-600">{{ $stats['total_configs'] }}</div>
                <div class="ml-auto text-2xl">⚙️</div>
            </div>
            <div class="text-sm text-gray-600 mt-1">کل کانفیگ‌ها</div>
        </div>

        <div class="bg-white p-6 rounded shadow">
            <div class="flex items-center">
                <div class="text-3xl font-bold text-green-600">{{ $stats['active_configs'] }}</div>
                <div class="ml-auto text-2xl">✅</div>
            </div>
            <div class="text-sm text-gray-600 mt-1">همه فعال</div>
        </div>

        <div class="bg-white p-6 rounded shadow">
            <div class="flex items-center">
                <div class="text-3xl font-bold text-purple-600">{{ $stats['total_books'] }}</div>
                <div class="ml-auto text-2xl">📚</div>
            </div>
            <div class="text-sm text-gray-600 mt-1">کل کتاب‌ها در دیتابیس</div>
        </div>

        <div class="bg-white p-6 rounded shadow">
            <div class="flex items-center">
                <div class="text-3xl font-bold text-yellow-600">{{ $stats['running_configs'] }}</div>
                <div class="ml-auto text-2xl">🔄</div>
            </div>
            <div class="text-sm text-gray-600 mt-1">در حال اجرا</div>
        </div>
    </div>

    <!-- Configs Table -->
    <div class="bg-white rounded shadow overflow-hidden">
        <table class="w-full" id="configsTable">
            <thead class="bg-gray-50">
                <tr>
                    <th class="text-right p-4 font-medium">نام کانفیگ</th>
                    <th class="text-right p-4 font-medium">آمار کلی</th>
                    <th class="text-right p-4 font-medium">آخرین اجرا</th>
                    <th class="text-center p-4 font-medium">عملیات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($configs as $config)
                    <tr class="hover:bg-gray-50">
                        <!-- نام و جزئیات -->
                        <td class="p-4">
                            <div class="font-medium">{{ $config->name }}</div>
                            <div class="text-xs text-gray-500 mt-1">
                                {{ parse_url($config->base_url, PHP_URL_HOST) ?: $config->base_url }}
                            </div>
                            <div class="text-xs text-gray-400">
                                هر {{ $config->delay_seconds }}s |
                                {{ $config->records_per_run }} رکورد |
                                ≈{{ round((60 / max($config->delay_seconds, 1)) * $config->records_per_run) }}/دقیقه
                            </div>
                            @if ($config->is_running)
                                <span
                                    class="inline-flex items-center px-2 py-1 text-xs bg-yellow-100 text-yellow-800 rounded-full w-fit mt-1">
                                    🔄 در حال اجرا
                                </span>
                            @endif
                        </td>

                        <!-- آمار -->
                        <td class="p-4">
                            @php
                                $displayStats = $config->getDisplayStats();
                                $executionLogs = $config->executionLogs();
                            @endphp

                            <div class="text-sm">
                                <div class="font-medium text-gray-900">📊 کل آمار:</div>
                                <div class="text-xs text-gray-600 mt-1">
                                    🔢 پردازش شده: {{ number_format($displayStats['total_processed']) }}<br>
                                    ✅ موفق: {{ number_format($displayStats['total_success']) }}<br>
                                    🏃 اجراها: {{ $displayStats['total_executions'] }}<br>
                                    @if ($displayStats['total_executions'] > 0)
                                        ⏹️ متوقف: {{ $displayStats['stopped_executions'] }}<br>
                                        ❌ ناموفق: {{ $displayStats['failed_executions'] }}
                                    @endif
                                </div>

                                @if ($displayStats['total_processed'] > 0)
                                    <div class="mt-2 text-xs">
                                        <div class="text-gray-500">نرخ موفقیت: {{ $displayStats['success_rate'] }}%</div>
                                        <div class="w-full bg-gray-200 rounded-full h-1 mt-1">
                                            <div class="bg-green-600 h-1 rounded-full"
                                                style="width: {{ $displayStats['success_rate'] }}%"></div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </td>

                        <!-- آخرین اجرا -->
                        <td class="p-4">
                            @if ($config->last_run_at)
                                <div class="flex items-center gap-2">
                                    @if ($config->is_running)
                                        <span class="text-yellow-600">🔄 در حال اجرا</span>
                                    @else
                                        <span class="text-orange-600">⏹️ متوقف شده</span>
                                    @endif
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    {{ $config->last_run_at->diffForHumans() }}
                                </div>
                                @if ($config->latestExecutionLog)
                                    @php
                                        $latestLog = $config->latestExecutionLog;
                                        $executionTime = $latestLog->execution_time;
                                        if ($executionTime <= 0 && $latestLog->started_at) {
                                            $executionTime = $latestLog->finished_at
                                                ? $latestLog->finished_at->diffInSeconds($latestLog->started_at)
                                                : now()->diffInSeconds($latestLog->started_at);
                                        }
                                    @endphp
                                    <div class="text-xs text-gray-400">
                                        @if ($latestLog->total_processed > 0)
                                            {{ number_format($latestLog->total_success) }}/{{ number_format($latestLog->total_processed) }}
                                            موفق
                                        @else
                                            بدون آمار
                                        @endif
                                        <br>
                                        ⏱️ {{ $executionTime > 0 ? round($executionTime) . 's' : 'نامعلوم' }}
                                    </div>
                                @endif
                            @else
                                <span class="text-gray-400 text-sm">هرگز اجرا نشده</span>
                            @endif
                        </td>

                        <!-- دکمه‌های عملیات -->
                        <td class="p-4">
                            <div class="flex items-center justify-center gap-2">
                                <!-- مشاهده جزئیات -->
                                <a href="{{ route('configs.show', $config) }}" class="text-blue-600 hover:text-blue-800"
                                    title="مشاهده جزئیات">
                                    👁️
                                </a>

                                <!-- مشاهده آمار -->
                                <a href="{{ route('configs.logs', $config) }}" class="text-green-600 hover:text-green-800"
                                    title="مشاهده آمار">
                                    📊
                                </a>

                                <!-- دکمه‌های اجرا/توقف -->
                                @if ($config->is_running)
                                    <!-- دکمه توقف -->
                                    <button onclick="stopExecution({{ $config->id }})"
                                        class="text-red-600 hover:text-red-800" title="متوقف کردن اجرا"
                                        id="stop-btn-{{ $config->id }}">
                                        ⏹️
                                    </button>

                                    <!-- نمایشگر در حال اجرا -->
                                    <span class="text-yellow-600" title="در حال اجرا">🔄</span>
                                @else
                                    <!-- دکمه اجرا -->
                                    <button onclick="startExecution({{ $config->id }})"
                                        class="text-green-600 hover:text-green-800" title="شروع اجرا"
                                        id="start-btn-{{ $config->id }}">
                                        🚀
                                    </button>
                                @endif

                                <!-- ویرایش -->
                                <a href="{{ route('configs.edit', $config) }}"
                                    class="text-yellow-600 hover:text-yellow-800" title="ویرایش">
                                    ✏️
                                </a>

                                <!-- حذف -->
                                <button onclick="deleteConfig({{ $config->id }})"
                                    class="text-red-600 hover:text-red-800" title="حذف">
                                    🗑️
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center py-8 text-gray-500">
                            <div class="text-4xl mb-2">📋</div>
                            <p>هیچ کانفیگی یافت نشد</p>
                            <a href="{{ route('configs.create') }}"
                                class="text-blue-600 hover:underline mt-2 inline-block">
                                اولین کانفیگ خود را ایجاد کنید
                            </a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    @if ($configs instanceof \Illuminate\Pagination\LengthAwarePaginator)
        <div class="mt-6">
            {{ $configs->links() }}
        </div>
    @endif

    <script>
        /**
         * متوقف کردن اجرا
         */
        function stopExecution(configId) {
            const stopBtn = document.getElementById(`stop-btn-${configId}`);

            if (!confirm('آیا مطمئن هستید که می‌خواهید اجرا را متوقف کنید؟')) {
                return;
            }

            // غیرفعال کردن دکمه تا جلوی کلیک‌های مکرر را بگیریم
            stopBtn.disabled = true;
            stopBtn.innerHTML = '⏳';
            stopBtn.title = 'در حال متوقف کردن...';

            fetch(`/configs/${configId}/stop`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    }
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        showAlert(data.message, 'success');

                        // بروزرسانی UI
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    } else {
                        showAlert(data.message || 'خطا در متوقف کردن اجرا', 'error');
                        // بازگرداندن دکمه به حالت اولیه
                        stopBtn.disabled = false;
                        stopBtn.innerHTML = '⏹️';
                        stopBtn.title = 'متوقف کردن اجرا';
                    }
                })
                .catch(error => {
                    console.error('خطا در درخواست توقف:', error);
                    showAlert('خطا در ارتباط با سرور: ' + error.message, 'error');

                    // بازگرداندن دکمه به حالت اولیه
                    stopBtn.disabled = false;
                    stopBtn.innerHTML = '⏹️';
                    stopBtn.title = 'متوقف کردن اجرا';
                });
        }

        /**
         * شروع اجرا
         */
        function startExecution(configId) {
            const startBtn = document.getElementById(`start-btn-${configId}`);

            if (!confirm('شروع اجرا عادی؟')) {
                return;
            }

            // غیرفعال کردن دکمه
            startBtn.disabled = true;
            startBtn.innerHTML = '⏳';

            const url = `/configs/${configId}/start`;

            fetch(url, {
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
                        showAlert(data.message, 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showAlert(data.message || 'خطا در شروع اجرا', 'error');
                    }
                })
                .catch(error => {
                    console.error('خطا در شروع اجرا:', error);
                    showAlert('خطا در ارتباط با سرور', 'error');
                })
                .finally(() => {
                    startBtn.disabled = false;
                    startBtn.innerHTML = '🚀';
                });
        }

        /**
         * حذف کانفیگ - اصلاح شده
         */
        function deleteConfig(configId) {
            if (!confirm(
                    'آیا مطمئن هستید که می‌خواهید این کانفیگ را حذف کنید؟\nتمام داده‌ها و آمار مرتبط نیز حذف خواهد شد.')) {
                return;
            }

            // ایجاد form برای DELETE request
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = `/configs/${configId}`;
            form.style.display = 'none';

            // اضافه کردن CSRF token
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = '_token';
            csrfInput.value = document.querySelector('meta[name="csrf-token"]').content;
            form.appendChild(csrfInput);

            // اضافه کردن method override
            const methodInput = document.createElement('input');
            methodInput.type = 'hidden';
            methodInput.name = '_method';
            methodInput.value = 'DELETE';
            form.appendChild(methodInput);

            // اضافه کردن form به DOM و submit کردن
            document.body.appendChild(form);
            form.submit();
        }

        /**
         * مدیریت Worker
         */
        function startWorker() {
            fetch('/admin/worker/start', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    showAlert(data.message, data.success ? 'success' : 'error');
                    if (data.success) {
                        setTimeout(() => location.reload(), 1000);
                    }
                })
                .catch(error => {
                    console.error('خطا در شروع Worker:', error);
                    showAlert('خطا در ارتباط با سرور', 'error');
                });
        }

        function stopWorker() {
            if (!confirm('آیا مطمئن هستید که می‌خواهید Worker را متوقف کنید؟')) {
                return;
            }

            fetch('/admin/worker/stop', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    showAlert(data.message, data.success ? 'success' : 'error');
                    if (data.success) {
                        setTimeout(() => location.reload(), 1000);
                    }
                })
                .catch(error => {
                    console.error('خطا در توقف Worker:', error);
                    showAlert('خطا در ارتباط با سرور', 'error');
                });
        }

        function restartWorker() {
            if (!confirm('آیا مطمئن هستید که می‌خواهید Worker را راه‌اندازی مجدد کنید؟')) {
                return;
            }

            fetch('/admin/worker/restart', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    showAlert(data.message, data.success ? 'success' : 'error');
                    if (data.success) {
                        setTimeout(() => location.reload(), 2000);
                    }
                })
                .catch(error => {
                    console.error('خطا در راه‌اندازی مجدد Worker:', error);
                    showAlert('خطا در ارتباط با سرور', 'error');
                });
        }

        function checkWorker() {
            fetch('/admin/worker/status', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    const status = data.worker_status.is_running ? 'فعال' : 'غیرفعال';
                    const message =
                        `وضعیت Worker: ${status}\nJobs در صف: ${data.queue_stats.pending_jobs}\nJobs شکست خورده: ${data.queue_stats.failed_jobs}`;
                    showAlert(message, 'info');
                })
                .catch(error => {
                    console.error('خطا در بررسی Worker:', error);
                    showAlert('خطا در ارتباط با سرور', 'error');
                });
        }

        /**
         * نمایش پیام
         */
        function showAlert(message, type = 'info') {
            // ایجاد alert box
            const alertBox = document.createElement('div');
            alertBox.className = `fixed top-4 right-4 z-50 p-4 rounded shadow-lg max-w-md ${
                type === 'success' ? 'bg-green-100 border border-green-400 text-green-700' :
                    type === 'error' ? 'bg-red-100 border border-red-400 text-red-700' :
                        'bg-blue-100 border border-blue-400 text-blue-700'
            }`;

            alertBox.innerHTML = `
        <div class="flex items-center justify-between">
            <div class="flex-1">
                <pre class="whitespace-pre-wrap text-sm">${message}</pre>
            </div>
            <button onclick="this.parentElement.parentElement.remove()" class="ml-2 text-lg leading-none">&times;</button>
        </div>
    `;

            document.body.appendChild(alertBox);

            // حذف خودکار بعد از 5 ثانیه
            setTimeout(() => {
                if (alertBox.parentElement) {
                    alertBox.remove();
                }
            }, 5000);
        }

        // رفرش خودکار هر 30 ثانیه برای نمایش وضعیت به‌روز
        setInterval(() => {
            const runningConfigs = document.querySelectorAll('[id^="stop-btn-"]');
            if (runningConfigs.length > 0) {
                location.reload();
            }
        }, 30000);
    </script>
@endsection
