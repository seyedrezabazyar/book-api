@extends('layouts.app')
@section('title', 'مدیریت کانفیگ‌ها')

@section('content')
    <!-- Worker Status -->
    <div class="bg-white rounded shadow p-4 mb-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-lg font-medium">🔧 مدیریت Worker</h2>
                <div class="flex items-center gap-4 mt-2">
                    @if($workerStatus['is_running'])
                        <span class="text-green-600">✅ Worker فعال</span>
                    @else
                        <span class="text-red-600">❌ Worker غیرفعال</span>
                    @endif
                    <span class="text-sm text-gray-600">
                        📊 Jobs در صف: {{ $workerStatus['pending_jobs'] }} |
                        @if($workerStatus['failed_jobs'] > 0)
                            ❌ شکست خورده: {{ $workerStatus['failed_jobs'] }}
                        @else
                            ✅ شکست خورده: 0
                        @endif
                    </span>
                </div>
            </div>
            <div class="flex gap-2">
                @if(!$workerStatus['is_running'])
                    <button onclick="startWorker()" class="px-3 py-1 bg-green-600 text-white rounded text-sm hover:bg-green-700">
                        🚀 شروع
                    </button>
                @endif
                <button onclick="restartWorker()" class="px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">
                    🔄 راه‌اندازی مجدد
                </button>
                @if($workerStatus['is_running'])
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
            <!-- Search -->
            <div class="relative">
                <input type="text" id="search" placeholder="جستجو..."
                       class="pl-8 pr-4 py-2 border rounded-lg w-64" onkeyup="filterConfigs()">
                <span class="absolute left-2 top-2.5 text-gray-400">🔍</span>
            </div>
            <!-- Add New Config -->
            <a href="{{ route('configs.create') }}"
               class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
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
            <div class="text-sm text-gray-600 mt-1">فعال</div>
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
                <th class="text-right p-4 font-medium">وضعیت</th>
                <th class="text-right p-4 font-medium">آمار کلی</th>
                <th class="text-right p-4 font-medium">آخرین اجرا</th>
                <th class="text-center p-4 font-medium">عملیات</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
            @forelse($configs as $config)
                <tr class="config-row hover:bg-gray-50" data-name="{{ strtolower($config->name) }}" data-url="{{ strtolower($config->api_url) }}">
                    <!-- نام و جزئیات -->
                    <td class="p-4">
                        <div class="font-medium">{{ $config->name }}</div>
                        <div class="text-sm text-gray-600">
                            {{ $config->description ?: 'بدون توضیحات' }}
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
                            {{ parse_url($config->api_url, PHP_URL_HOST) ?: $config->api_url }}
                        </div>
                        <div class="text-xs text-gray-400">
                            هر {{ $config->delay_seconds }}s |
                            {{ $config->records_per_page }} رکورد |
                            ≈{{ round(60 / max($config->delay_seconds, 1) * $config->records_per_page) }}/دقیقه
                        </div>
                    </td>

                    <!-- وضعیت -->
                    <td class="p-4">
                        <div class="flex flex-col gap-1">
                            @if($config->status === 'active')
                                <span class="inline-flex items-center px-2 py-1 text-xs bg-green-100 text-green-800 rounded-full w-fit">
                                        فعال
                                    </span>
                            @else
                                <span class="inline-flex items-center px-2 py-1 text-xs bg-red-100 text-red-800 rounded-full w-fit">
                                        غیرفعال
                                    </span>
                            @endif

                            @if($config->is_running)
                                <span class="inline-flex items-center px-2 py-1 text-xs bg-yellow-100 text-yellow-800 rounded-full w-fit">
                                        🔄 در حال اجرا
                                    </span>
                            @endif
                        </div>
                    </td>

                    <!-- آمار -->
                    <td class="p-4">
                        @php
                            $displayStats = $config->getDisplayStats();
                            $executionStats = $config->getExecutionStats();
                        @endphp

                        <div class="text-sm">
                            <div class="font-medium text-gray-900">📊 کل آمار:</div>
                            <div class="text-xs text-gray-600 mt-1">
                                🔢 پردازش شده: {{ number_format($displayStats['total_processed']) }}<br>
                                ✅ موفق: {{ number_format($displayStats['total_success']) }}<br>
                                🏃 اجراها: {{ $executionStats['total_executions'] }}<br>
                                ⏹️ متوقف: {{ $executionStats['stopped_executions'] }}
                            </div>
                        </div>
                    </td>

                    <!-- آخرین اجرا -->
                    <td class="p-4">
                        @if($config->last_run_at)
                            <div class="flex items-center gap-2">
                                @if($config->is_running)
                                    <span class="text-yellow-600">🔄 در حال اجرا</span>
                                @else
                                    <span class="text-orange-600">⏹️ متوقف شده</span>
                                @endif
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                {{ $config->last_run_at->diffForHumans() }}
                            </div>
                            @if($config->latestExecutionLog)
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
                                    @if($latestLog->total_processed > 0)
                                        {{ number_format($latestLog->total_success) }}/{{ number_format($latestLog->total_processed) }} موفق
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
                            <a href="{{ route('configs.show', $config) }}"
                               class="text-blue-600 hover:text-blue-800" title="مشاهده جزئیات">
                                👁️
                            </a>

                            <!-- مشاهده آمار -->
                            <a href="{{ route('configs.logs', $config) }}"
                               class="text-green-600 hover:text-green-800" title="مشاهده آمار">
                                📊
                            </a>

                            <!-- دکمه‌های اجرا/توقف -->
                            @if($config->is_running)
                                <!-- دکمه توقف -->
                                <button onclick="stopExecution({{ $config->id }})"
                                        class="text-red-600 hover:text-red-800"
                                        title="متوقف کردن اجرا"
                                        id="stop-btn-{{ $config->id }}">
                                    ⏹️
                                </button>

                                <!-- نمایشگر در حال اجرا -->
                                <span class="text-yellow-600" title="در حال اجرا">🔄</span>
                            @else
                                <!-- دکمه اجرا -->
                                <button onclick="startExecution({{ $config->id }})"
                                        class="text-green-600 hover:text-green-800"
                                        title="شروع اجرا"
                                        id="start-btn-{{ $config->id }}">
                                    🚀
                                </button>

                                <!-- دکمه اجرا سریع -->
                                <button onclick="startExecution({{ $config->id }}, true)"
                                        class="text-blue-600 hover:text-blue-800"
                                        title="اجرا سریع"
                                        id="fast-start-btn-{{ $config->id }}">
                                    ⚡
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
                    <td colspan="5" class="text-center py-8 text-gray-500">
                        <div class="text-4xl mb-2">📋</div>
                        <p>هیچ کانفیگی یافت نشد</p>
                        <a href="{{ route('configs.create') }}" class="text-blue-600 hover:underline mt-2 inline-block">
                            اولین کانفیگ خود را ایجاد کنید
                        </a>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    @if($configs instanceof \Illuminate\Pagination\LengthAwarePaginator)
        <div class="mt-6">
            {{ $configs->links() }}
        </div>
    @endif

    <script>
        /**
         * فیلتر کردن کانفیگ‌ها
         */
        function filterConfigs() {
            const searchTerm = document.getElementById('search').value.toLowerCase();
            const rows = document.querySelectorAll('.config-row');

            rows.forEach(row => {
                const name = row.dataset.name || '';
                const url = row.dataset.url || '';
                const isVisible = name.includes(searchTerm) || url.includes(searchTerm);
                row.style.display = isVisible ? '' : 'none';
            });
        }

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
        function startExecution(configId, fastMode = false) {
            const startBtn = document.getElementById(fastMode ? `fast-start-btn-${configId}` : `start-btn-${configId}`);

            if (!confirm(fastMode ? 'شروع اجرا سریع؟' : 'شروع اجرا عادی؟')) {
                return;
            }

            // غیرفعال کردن دکمه
            startBtn.disabled = true;
            startBtn.innerHTML = '⏳';

            const url = fastMode ? `/configs/${configId}/start-fast` : `/configs/${configId}/start`;

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

                        // بروزرسانی UI
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    } else {
                        showAlert(data.message || 'خطا در شروع اجرا', 'error');
                    }
                })
                .catch(error => {
                    console.error('خطا در شروع اجرا:', error);
                    showAlert('خطا در ارتباط با سرور', 'error');
                })
                .finally(() => {
                    // بازگرداندن دکمه
                    startBtn.disabled = false;
                    startBtn.innerHTML = fastMode ? '⚡' : '🚀';
                });
        }

        /**
         * حذف کانفیگ
         */
        function deleteConfig(configId) {
            if (!confirm('آیا مطمئن هستید که می‌خواهید این کانفیگ را حذف کنید؟\nتمام داده‌ها و آمار مرتبط نیز حذف خواهد شد.')) {
                return;
            }

            fetch(`/configs/${configId}`, {
                method: 'DELETE',
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
                        location.reload();
                    } else {
                        showAlert(data.message || 'خطا در حذف کانفیگ', 'error');
                    }
                })
                .catch(error => {
                    console.error('خطا در حذف کانفیگ:', error);
                    showAlert('خطا در ارتباط با سرور', 'error');
                });
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
                    const message = `وضعیت Worker:\n${data.status}\nJobs در صف: ${data.pending_jobs}\nJobs شکست خورده: ${data.failed_jobs}`;
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
