@extends('layouts.app')
@section('title', 'مدیریت کانفیگ‌ها')

@section('content')
    <div class="container mx-auto px-4 py-6">
        <!-- Worker Status -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-6 transition-all duration-300 hover:shadow-xl">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800 flex items-center gap-2">
                        <span>🔧</span> مدیریت Worker
                    </h2>
                    <div class="flex flex-wrap items-center gap-4 mt-2 text-sm">
                        <span class="{{ $workerStatus['is_running'] ? 'text-green-600' : 'text-red-600' }}">
                            {{ $workerStatus['is_running'] ? '✅ فعال' : '❌ غیرفعال' }}
                        </span>
                        <span class="text-gray-600">
                            📊 Jobs در صف: {{ $workerStatus['pending_jobs'] ?? 0 }} |
                            {{ ($workerStatus['failed_jobs'] ?? 0) > 0 ? '❌ شکست خورده: ' . $workerStatus['failed_jobs'] : '✅ شکست خورده: 0' }}
                        </span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if (!$workerStatus['is_running'])
                        <button onclick="startWorker()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                            🚀 شروع
                        </button>
                    @endif
                    <button onclick="restartWorker()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        🔄 راه‌اندازی مجدد
                    </button>
                    @if ($workerStatus['is_running'])
                        <button onclick="stopWorker()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                            ⏹️ توقف
                        </button>
                    @endif
                    <button onclick="checkWorker()" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors">
                        🔍 بررسی
                    </button>
                </div>
            </div>
        </div>

        <!-- Header -->
        <div class="flex flex-col sm:flex-row items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">مدیریت کانفیگ‌های هوشمند</h1>
                <p class="text-gray-600 text-sm mt-1">سیستم کرال هوشمند با API و Crawler</p>
            </div>
            <a href="{{ route('configs.create') }}" class="mt-4 sm:mt-0 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                ✨ کانفیگ جدید
            </a>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-6 mb-6">
            @foreach ([
                ['value' => $systemStats['total_configs'] ?? $systemStats['configs']['total'] ?? 0, 'label' => 'کانفیگ‌ها', 'icon' => '🧠', 'color' => 'blue'],
                ['value' => $systemStats['running_configs'] ?? $systemStats['configs']['running'] ?? 0, 'label' => 'در حال اجرا', 'icon' => '🔄', 'color' => 'green'],
                ['value' => $configs->where('source_type', 'api')->count(), 'label' => 'API', 'icon' => '🌐', 'color' => 'purple'],
                ['value' => $configs->where('source_type', 'crawler')->count(), 'label' => 'Crawler', 'icon' => '🕷️', 'color' => 'orange'],
                ['value' => $systemStats['total_books'] ?? $systemStats['books']['actual_in_db'] ?? 0, 'label' => 'کل کتاب‌ها', 'icon' => '📚', 'color' => 'indigo']
            ] as $stat)
                <div class="bg-white p-6 rounded-lg shadow-lg transition-all duration-300 hover:shadow-xl">
                    <div class="flex items-center">
                        <span class="text-3xl font-bold text-{{ $stat['color'] }}-600">{{ number_format($stat['value']) }}</span>
                        <span class="ml-auto text-2xl">{{ $stat['icon'] }}</span>
                    </div>
                    <div class="text-sm text-gray-600 mt-2">{{ $stat['label'] }}</div>
                </div>
            @endforeach
        </div>

        <!-- Configs Table -->
        <div class="bg-white rounded-lg shadow-lg overflow-hidden">
            <table class="w-full text-right" id="configsTable">
                <thead class="bg-gray-100">
                <tr>
                    <th class="p-4 font-semibold text-gray-700">کانفیگ و منبع</th>
                    <th class="p-4 font-semibold text-gray-700">آمار هوشمند</th>
                    <th class="p-4 font-semibold text-gray-700">وضعیت اجرا</th>
                    <th class="p-4 font-semibold text-gray-700 text-center">عملیات</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                @forelse($configs as $config)
                    @php
                        $completeStats = $config->getCompleteStats();
                        $sourceStats = \App\Models\BookSource::where('source_name', $config->source_name)->count() ?? 0;
                        $nextRunEstimate = ($config->max_pages ?? 1000) * ($config->delay_seconds ?? 3);
                        $estimateText = $nextRunEstimate > 3600 ? '≈' . round($nextRunEstimate / 3600, 1) . ' ساعت' : ($nextRunEstimate > 60 ? '≈' . round($nextRunEstimate / 60) . ' دقیقه' : '≈' . $nextRunEstimate . ' ثانیه');
                    @endphp
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="p-4">
                            <div class="font-semibold text-gray-800">{{ $config->name }}</div>
                            <div class="text-sm text-gray-600 mt-1">
                                📊 منبع: <span class="text-blue-600">{{ $config->source_name }}</span>
                                @if ($config->source_type === 'api')
                                    <span class="ml-2 px-2 py-1 text-xs bg-blue-100 text-blue-700 rounded">🌐 API</span>
                                @else
                                    <span class="ml-2 px-2 py-1 text-xs bg-orange-100 text-orange-700 rounded">🕷️ Crawler</span>
                                @endif
                            </div>
                            <div class="text-xs text-gray-500 mt-1">🌐 {{ parse_url($config->base_url, PHP_URL_HOST) }}</div>
                            <div class="text-xs text-gray-400 mt-1">
                                ⏱️ {{ $config->delay_seconds }}s تاخیر | 📄 حداکثر {{ number_format($config->max_pages) }} ID
                                @if ($config->source_type === 'crawler')
                                    | 🔍 {{ $config->page_pattern ?: '/book/{id}' }}
                                @endif
                            </div>
                            <div class="flex flex-wrap gap-2 mt-2">
                                @if ($config->auto_resume)
                                    <span class="px-2 py-1 text-xs bg-blue-100 text-blue-700 rounded">⚡ ادامه خودکار</span>
                                @endif
                                @if ($config->fill_missing_fields)
                                    <span class="px-2 py-1 text-xs bg-green-100 text-green-700 rounded">🔧 تکمیل خودکار</span>
                                @endif
                                @if ($config->update_descriptions)
                                    <span class="px-2 py-1 text-xs bg-purple-100 text-purple-700 rounded">📝 بهبود توضیحات</span>
                                @endif
                                @if ($config->is_running)
                                    <span class="px-2 py-1 text-xs bg-yellow-100 text-yellow-800 rounded">🔄 در حال اجرا</span>
                                @endif
                            </div>
                        </td>
                        <td class="p-4">
                            <div class="space-y-2 text-sm">
                                <div class="grid grid-cols-2 gap-4 text-xs">
                                    <div><span class="text-gray-600">📈 کل پردازش:</span> <span class="font-bold text-blue-600">{{ number_format($completeStats['total_processed']) }}</span></div>
                                    <div><span class="text-gray-600">✅ جدید:</span> <span class="font-bold text-green-600">{{ number_format($completeStats['total_success']) }}</span></div>
                                    @if ($completeStats['total_enhanced'] > 0)
                                        <div><span class="text-gray-600">🔧 بهبود:</span> <span class="font-bold text-purple-600">{{ number_format($completeStats['total_enhanced']) }}</span></div>
                                    @endif
                                    <div><span class="text-gray-600">❌ خطا:</span> <span class="font-bold text-red-600">{{ number_format($completeStats['total_failed']) }}</span></div>
                                </div>
                                <div class="pt-2 border-t border-gray-200">
                                    <div class="grid grid-cols-2 gap-4 text-xs">
                                        <div><span class="text-gray-600">🆔 آخرین ID:</span> <span class="font-bold text-purple-600">{{ number_format($config->last_source_id ?? 0) }}</span></div>
                                        <div><span class="text-gray-600">📍 بعدی:</span> <span class="font-bold text-indigo-600">{{ number_format($config->getSmartStartPage()) }}</span></div>
                                    </div>
                                </div>
                                @if ($completeStats['total_processed'] > 0)
                                    <div class="pt-2 border-t border-gray-200">
                                        <div class="text-xs text-gray-600 mb-1">نرخ تأثیر: {{ $completeStats['real_success_rate'] }}% @if ($completeStats['enhancement_rate'] > 0)({{ $completeStats['enhancement_rate'] }}% بهبود)@endif</div>
                                        <div class="w-full bg-gray-200 rounded-full h-1.5 relative">
                                            <div class="bg-green-600 h-1.5 rounded-full absolute" style="width: {{ round(($completeStats['total_success'] / $completeStats['total_processed']) * 100, 1) }}%"></div>
                                            <div class="bg-purple-600 h-1.5 rounded-full absolute" style="left: {{ round(($completeStats['total_success'] / $completeStats['total_processed']) * 100, 1) }}%; width: {{ $completeStats['enhancement_rate'] }}%"></div>
                                        </div>
                                        <div class="text-xs text-gray-500 mt-1">{{ number_format($completeStats['real_success_count']) }} کتاب تأثیرگذار از {{ number_format($completeStats['total_processed']) }}</div>
                                        @if ($completeStats['total_enhanced'] > 0)
                                            <div class="text-xs text-purple-600 mt-1">🔧 {{ number_format($completeStats['total_enhanced']) }} کتاب بهبود یافت</div>
                                        @endif
                                    </div>
                                @else
                                    <div class="pt-2 border-t border-gray-200 text-xs text-gray-500">🆕 آماده برای اولین اجرا</div>
                                @endif
                                @if ($sourceStats > 0)
                                    <div class="pt-2 border-t border-gray-200 text-xs text-gray-600">
                                        🌐 در منبع: <span class="font-medium text-indigo-600">{{ number_format($sourceStats) }}</span> رکورد
                                    </div>
                                @endif
                            </div>
                        </td>
                        <td class="p-4">
                            @if ($config->last_run_at)
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="{{ $config->is_running ? 'text-yellow-600' : 'text-green-600' }}">{{ $config->is_running ? '🔄 در حال اجرا' : '⏹️ آماده' }}</span>
                                </div>
                                <div class="text-xs text-gray-500 mt-1">آخرین اجرا: {{ $config->last_run_at->diffForHumans() }}</div>
                                @php $latestLog = $config->executionLogs()->latest()->first(); @endphp
                                @if ($latestLog)
                                    <div class="text-xs text-gray-400 mt-1">
                                        @if ($latestLog->total_processed > 0)
                                            🎯 {{ number_format($latestLog->total_success ?? 0) }}/{{ number_format($latestLog->total_processed) }} موفق
                                            @if ($latestLog->total_enhanced > 0)
                                                <br>🔧 {{ number_format($latestLog->total_enhanced) }} بهبود
                                            @endif
                                        @else
                                            📊 بدون آمار
                                        @endif
                                        @if ($latestLog->execution_time > 0)
                                            <br>⏱️ {{ round($latestLog->execution_time) }}s
                                        @endif
                                    </div>
                                @endif
                            @else
                                <span class="text-gray-400 text-sm">🆕 آماده اولین اجرا</span>
                                <div class="text-xs text-blue-600 mt-1">شروع از ID {{ $config->getSmartStartPage() }}</div>
                                <div class="text-xs text-gray-500 mt-1">
                                    {{ $config->source_type === 'api' ? '🌐 API' : '🕷️ Crawler' }}
                                </div>
                            @endif
                            @if (!$config->is_running)
                                <div class="text-xs text-gray-400 mt-1">⏱️ تخمین اجرای بعدی: {{ $estimateText }}</div>
                            @endif
                        </td>
                        <td class="p-4">
                            <div class="flex items-center justify-center gap-3">
                                <a href="{{ route('configs.show', $config) }}" class="text-blue-600 hover:text-blue-800 text-lg" title="مشاهده جزئیات">👁️</a>
                                <a href="{{ route('configs.logs', $config) }}" class="text-green-600 hover:text-green-800 text-lg" title="لاگ‌ها و آمار">📊</a>
                                @if ($config->is_running)
                                    <button onclick="stopExecution({{ $config->id }})" class="text-red-600 hover:text-red-800 text-lg" title="متوقف کردن اجرا" id="stop-btn-{{ $config->id }}">⏹️</button>
                                @else
                                    <button onclick="startExecution({{ $config->id }})" class="text-green-600 hover:text-green-800 text-lg" title="شروع اجرای هوشمند" id="start-btn-{{ $config->id }}">🚀</button>
                                @endif
                                <a href="{{ route('configs.edit', $config) }}" class="text-yellow-600 hover:text-yellow-800 text-lg" title="ویرایش">✏️</a>
                                <button onclick="deleteConfig({{ $config->id }})" class="text-red-600 hover:text-red-800 text-lg" title="حذف">🗑️</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center py-12 text-gray-500">
                            <div class="text-6xl mb-4">🧠</div>
                            <h3 class="text-lg font-semibold text-gray-900 mb-2">هیچ کانفیگ هوشمندی یافت نشد!</h3>
                            <p class="text-gray-500 mb-6">اولین کانفیگ هوشمند خود را ایجاد کنید</p>
                            <a href="{{ route('configs.create') }}" class="inline-flex items-center px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                ✨ ایجاد کانفیگ هوشمند
                            </a>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <!-- Bottom Info Panel -->
        <div class="mt-6 bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-6">
            <h3 class="text-blue-800 font-semibold mb-3">🧠 ویژگی‌های سیستم کرال هوشمند</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm text-blue-700">
                @foreach ([
                    ['title' => '⚡ تشخیص خودکار نقطه شروع', 'items' => ['از ID 1 برای منابع جدید', 'از آخرین ID + 1 برای منابع قبلی', 'رعایت start_page مشخص شده']],
                    ['title' => '🔧 مدیریت هوشمند تکراری‌ها', 'items' => ['تشخیص با MD5 محتوا', 'تکمیل فیلدهای خالی', 'بهبود توضیحات ناقص', 'ادغام ISBN و نویسندگان']],
                    ['title' => '🌐 پشتیبانی از API و Crawler', 'items' => ['API: دریافت JSON ساختارمند', 'Crawler: استخراج از HTML', 'سلکتورهای CSS قدرتمند', 'مدیریت headers و تنظیمات']]
                ] as $feature)
                    <div class="space-y-2">
                        <div class="font-semibold">{{ $feature['title'] }}</div>
                        <ul class="text-xs text-blue-600 space-y-1">
                            @foreach ($feature['items'] as $item)
                                <li>• {{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
            @php
                $totalBooksEnhanced = \App\Models\ExecutionLog::sum('total_enhanced') ?? 0;
                $totalSuccessfulRuns = \App\Models\ExecutionLog::whereIn('status', ['completed', 'stopped'])->count();
                $totalBooksCreated = \App\Models\ExecutionLog::sum('total_success') ?? 0;
                $totalBooksProcessed = \App\Models\ExecutionLog::sum('total_processed') ?? 0;
                $totalImpactfulBooks = $totalBooksCreated + $totalBooksEnhanced;
                $overallImpactRate = $totalBooksProcessed > 0 ? round(($totalImpactfulBooks / $totalBooksProcessed) * 100, 1) : 0;
                $apiConfigs = $configs->where('source_type', 'api')->count();
                $crawlerConfigs = $configs->where('source_type', 'crawler')->count();
            @endphp
            @if ($totalImpactfulBooks > 0 || $totalBooksCreated > 0 || $totalSuccessfulRuns > 0)
                <div class="mt-4 pt-4 border-t border-blue-200 text-center text-xs text-blue-600 space-x-4 space-x-reverse">
                    @if ($totalBooksCreated > 0)
                        <span>🎯 <strong>{{ number_format($totalBooksCreated) }}</strong> کتاب جدید</span>
                    @endif
                    @if ($totalBooksEnhanced > 0)
                        <span>🔧 <strong>{{ number_format($totalBooksEnhanced) }}</strong> کتاب بهبود یافته</span>
                    @endif
                    @if ($totalImpactfulBooks > 0)
                        <span>📈 <strong>{{ $overallImpactRate }}%</strong> نرخ تأثیر</span>
                    @endif
                    @if ($totalSuccessfulRuns > 0)
                        <span>✅ <strong>{{ number_format($totalSuccessfulRuns) }}</strong> اجرای موفق</span>
                    @endif
                    @if ($apiConfigs > 0)
                        <span>🌐 <strong>{{ $apiConfigs }}</strong> API</span>
                    @endif
                    @if ($crawlerConfigs > 0)
                        <span>🕷️ <strong>{{ $crawlerConfigs }}</strong> Crawler</span>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <script>
        // Generic API handler
        async function apiRequest(url, method = 'POST', confirmMessage = null) {
            if (confirmMessage && !confirm(confirmMessage)) return;
            try {
                const response = await fetch(url, {
                    method,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json'
                    }
                });
                const data = await response.json();
                showAlert(data.message, data.success ? 'success' : 'error');
                if (data.success) setTimeout(() => location.reload(), 1000);
            } catch (error) {
                showAlert('خطا در ارتباط با سرور: ' + error.message, 'error');
            }
        }

        // Worker actions
        const startWorker = () => apiRequest('/admin/worker/start', 'POST', 'آیا می‌خواهید Worker را شروع کنید؟');
        const stopWorker = () => apiRequest('/admin/worker/stop', 'POST', 'آیا می‌خواهید Worker را متوقف کنید؟');
        const restartWorker = () => apiRequest('/admin/worker/restart', 'POST', 'آیا می‌خواهید Worker را راه‌اندازی مجدد کنید؟');
        const checkWorker = async () => {
            try {
                const response = await fetch('/admin/worker/status', { headers: { 'Accept': 'application/json' } });
                const data = await response.json();
                const status = data.worker_status.is_running ? 'فعال' : 'غیرفعال';
                showAlert(`وضعیت Worker: ${status}\nJobs در صف: ${data.queue_stats.pending_jobs}\nJobs شکست خورده: ${data.queue_stats.failed_jobs}`, 'info');
            } catch (error) {
                showAlert('خطا در ارتباط با سرور', 'error');
            }
        };

        // Config actions
        const startExecution = (id) => apiRequest(`/configs/${id}/start`, 'POST', '🧠 اجرای هوشمند شروع می‌شود. ادامه می‌دهید؟', `start-btn-${id}`);
        const stopExecution = (id) => apiRequest(`/configs/${id}/stop`, 'POST', 'آیا می‌خواهید اجرا را متوقف کنید؟', `stop-btn-${id}`);
        const deleteConfig = (id) => {
            if (!confirm('⚠️ حذف کانفیگ هوشمند\n\nآیا مطمئن هستید؟ تمام داده‌ها حذف خواهد شد.')) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = `/configs/${id}`;
            form.style.display = 'none';
            form.innerHTML = `
                <input type="hidden" name="_token" value="${document.querySelector('meta[name="csrf-token"]').content}">
                <input type="hidden" name="_method" value="DELETE">
            `;
            document.body.appendChild(form);
            form.submit();
        };

        // Alert with animation
        function showAlert(message, type = 'info') {
            const alertBox = document.createElement('div');
            alertBox.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg max-w-md transition-all duration-300 transform translate-x-full ${
                type === 'success' ? 'bg-green-100 border-green-400 text-green-700' :
                    type === 'error' ? 'bg-red-100 border-red-400 text-red-700' :
                        'bg-blue-100 border-blue-400 text-blue-700'
            }`;
            alertBox.innerHTML = `
                <div class="flex items-center justify-between">
                    <pre class="whitespace-pre-wrap text-sm">${message}</pre>
                    <button onclick="this.parentElement.parentElement.remove()" class="ml-2 text-lg">×</button>
                </div>
            `;
            document.body.appendChild(alertBox);
            setTimeout(() => alertBox.classList.remove('translate-x-full'), 100);
            setTimeout(() => alertBox.remove(), 5000);
        }

        // Auto-refresh if running configs exist
        setInterval(() => {
            if (document.querySelectorAll('[id^="stop-btn-"]').length > 0) location.reload();
        }, 30000);
    </script>
@endsection
