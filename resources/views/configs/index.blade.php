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
                        📊 Jobs در صف: {{ $workerStatus['pending_jobs'] ?? 0 }} |
                        @if (($workerStatus['failed_jobs'] ?? 0) > 0)
                            ❌ شکست خورده: {{ $workerStatus['failed_jobs'] }}
                        @else
                            ✅ شکست خورده: 0
                        @endif
                    </span>
                </div>
            </div>
            <div class="flex gap-2">
                @if (!($workerStatus['is_running'] ?? false))
                    <button onclick="startWorker()"
                            class="px-3 py-1 bg-green-600 text-white rounded text-sm hover:bg-green-700">
                        🚀 شروع
                    </button>
                @endif
                <button onclick="restartWorker()"
                        class="px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">
                    🔄 راه‌اندازی مجدد
                </button>
                @if ($workerStatus['is_running'] ?? false)
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
            <h1 class="text-2xl font-semibold">مدیریت کانفیگ‌های هوشمند</h1>
            <p class="text-gray-600">سیستم کرال هوشمند با تشخیص خودکار نقطه شروع و مدیریت تکراری‌ها</p>
        </div>
        <div class="flex items-center gap-4">
            <a href="{{ route('configs.create') }}" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                ✨ کانفیگ هوشمند جدید
            </a>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
        <div class="bg-white p-6 rounded shadow">
            <div class="flex items-center">
                <div class="text-3xl font-bold text-blue-600">{{ $stats['total_configs'] }}</div>
                <div class="ml-auto text-2xl">🧠</div>
            </div>
            <div class="text-sm text-gray-600 mt-1">کانفیگ‌های هوشمند</div>
        </div>

        <div class="bg-white p-6 rounded shadow">
            <div class="flex items-center">
                <div class="text-3xl font-bold text-green-600">{{ $stats['running_configs'] }}</div>
                <div class="ml-auto text-2xl">🔄</div>
            </div>
            <div class="text-sm text-gray-600 mt-1">در حال اجرا</div>
        </div>

        <div class="bg-white p-6 rounded shadow">
            <div class="flex items-center">
                <div class="text-3xl font-bold text-purple-600">{{ $stats['total_books'] }}</div>
                <div class="ml-auto text-2xl">📚</div>
            </div>
            <div class="text-sm text-gray-600 mt-1">کل کتاب‌ها</div>
        </div>

        <div class="bg-white p-6 rounded shadow">
            <div class="flex items-center">
                @php
                    // تغییر از source_type به source_name
                    $totalSourceTypes = \App\Models\BookSource::distinct('source_name')->count();
                @endphp
                <div class="text-3xl font-bold text-orange-600">{{ $totalSourceTypes }}</div>
                <div class="ml-auto text-2xl">🌐</div>
            </div>
            <div class="text-sm text-gray-600 mt-1">منابع مختلف</div>
        </div>
    </div>

    <!-- Configs Table -->
    <div class="bg-white rounded shadow overflow-hidden">
        <table class="w-full" id="configsTable">
            <thead class="bg-gray-50">
            <tr>
                <th class="text-right p-4 font-medium">کانفیگ و منبع</th>
                <th class="text-right p-4 font-medium">آمار هوشمند</th>
                <th class="text-right p-4 font-medium">وضعیت اجرا</th>
                <th class="text-center p-4 font-medium">عملیات</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
            @forelse($configs as $config)
                <tr class="hover:bg-gray-50">
                    <!-- نام و جزئیات -->
                    <td class="p-4">
                        <div class="font-medium">{{ $config->name }}</div>
                        <div class="text-sm text-gray-600 mt-1">
                            📊 منبع: <span class="font-medium text-blue-600">{{ $config->source_name }}</span>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
                            🌐 {{ parse_url($config->base_url, PHP_URL_HOST) }}
                        </div>
                        <div class="text-xs text-gray-400 mt-1">
                            ⏱️ {{ $config->delay_seconds }}s تاخیر |
                            📄 حداکثر {{ number_format($config->max_pages) }} ID
                        </div>

                        <!-- نمایش ویژگی‌های هوشمند -->
                        <div class="flex items-center gap-2 mt-2">
                            @if ($config->auto_resume)
                                <span class="px-2 py-1 text-xs bg-blue-100 text-blue-700 rounded" title="ادامه خودکار">⚡ ادامه خودکار</span>
                            @endif
                            @if ($config->fill_missing_fields)
                                <span class="px-2 py-1 text-xs bg-green-100 text-green-700 rounded" title="تکمیل فیلدهای خالی">🔧 تکمیل خودکار</span>
                            @endif
                            @if ($config->update_descriptions)
                                <span class="px-2 py-1 text-xs bg-purple-100 text-purple-700 rounded" title="بهبود توضیحات">📝 بهبود توضیحات</span>
                            @endif
                        </div>

                        @if ($config->is_running)
                            <span class="inline-flex items-center px-2 py-1 text-xs bg-yellow-100 text-yellow-800 rounded-full w-fit mt-2">
                                    🔄 در حال اجرا
                                </span>
                        @endif
                    </td>

                    <!-- آمار هوشمند بهبود یافته -->
                    <td class="p-4">
                        <div class="text-sm space-y-2">
                            <!-- آمار اصلی -->
                            <div class="grid grid-cols-2 gap-4 text-xs">
                                <div>
                                    <span class="text-gray-600">📈 کل پردازش:</span>
                                    <span class="font-bold text-blue-600">{{ number_format($config->total_processed) }}</span>
                                </div>
                                <div>
                                    <span class="text-gray-600">✅ جدید:</span>
                                    <span class="font-bold text-green-600">{{ number_format($config->total_success) }}</span>
                                </div>

                                {{-- آمار بهبود یافته --}}
                                @php
                                    $totalEnhanced = \App\Models\ExecutionLog::where('config_id', $config->id)
                                        ->sum('total_enhanced');
                                @endphp
                                @if($totalEnhanced > 0)
                                    <div>
                                        <span class="text-gray-600">🔧 بهبود:</span>
                                        <span class="font-bold text-purple-600">{{ number_format($totalEnhanced) }}</span>
                                    </div>
                                @endif

                                <div>
                                    <span class="text-gray-600">❌ خطا:</span>
                                    <span class="font-bold text-red-600">{{ number_format($config->total_failed) }}</span>
                                </div>
                                <div>
                                    <span class="text-gray-600">🔄 تکراری:</span>
                                    @php
                                        $duplicateCount = \App\Models\ExecutionLog::where('config_id', $config->id)
                                            ->sum('total_duplicate');
                                    @endphp
                                    <span class="font-bold text-orange-600">{{ number_format($duplicateCount) }}</span>
                                </div>
                            </div>

                            <!-- آمار source ID -->
                            <div class="pt-2 border-t border-gray-200">
                                <div class="grid grid-cols-2 gap-4 text-xs">
                                    <div>
                                        <span class="text-gray-600">🆔 آخرین ID:</span>
                                        <span class="font-bold text-purple-600">{{ number_format($config->last_source_id) }}</span>
                                    </div>
                                    <div>
                                        <span class="text-gray-600">📍 بعدی:</span>
                                        <span class="font-bold text-indigo-600">{{ number_format($config->getSmartStartPage()) }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- نرخ موفقیت واقعی (شامل بهبود یافته‌ها) -->
                            @if ($config->total_processed > 0)
                                @php
                                    $realSuccessCount = $config->total_success + $totalEnhanced;
                                    $realSuccessRate = round(($realSuccessCount / $config->total_processed) * 100, 1);
                                    $enhancementRate = $totalEnhanced > 0 ? round(($totalEnhanced / $config->total_processed) * 100, 1) : 0;
                                @endphp
                                <div class="pt-2 border-t border-gray-200">
                                    <div class="text-xs text-gray-600 mb-1">
                                        نرخ تأثیر: {{ $realSuccessRate }}%
                                        @if($enhancementRate > 0)
                                            ({{ $enhancementRate }}% بهبود)
                                        @endif
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-1.5">
                                        <div class="bg-gradient-to-r from-green-600 to-purple-600 h-1.5 rounded-full"
                                             style="width: {{ $realSuccessRate }}%"></div>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        {{ number_format($realSuccessCount) }} کتاب تأثیرگذار از {{ number_format($config->total_processed) }}
                                    </div>
                                </div>
                            @endif

                            <!-- آمار منبع -->
                            @php
                                try {
                                    $sourceStats = \App\Models\BookSource::where('source_name', $config->source_name)->count();
                                } catch (\Exception $e) {
                                    $sourceStats = 0;
                                }
                            @endphp
                            @if ($sourceStats > 0)
                                <div class="pt-2 border-t border-gray-200">
                                    <div class="text-xs text-gray-600">
                                        🌐 در منبع: <span class="font-medium text-indigo-600">{{ number_format($sourceStats) }}</span> رکورد
                                    </div>
                                </div>
                            @endif
                        </div>
                    </td>

                    {{-- در همان فایل، بخش Bottom Info Panel را بهبود دهید --}}

                    <!-- Bottom Info Panel بهبود یافته -->
                    <div class="mt-6 bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4">
                        <h3 class="text-blue-800 font-medium mb-2">🧠 ویژگی‌های سیستم کرال هوشمند پیشرفته:</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 text-sm text-blue-700">
                            <div class="space-y-1">
                                <div class="font-medium">⚡ تشخیص خودکار نقطه شروع:</div>
                                <ul class="text-xs space-y-1 text-blue-600">
                                    <li>• اگر قبلاً از منبع کتاب نگرفته: از ID 1</li>
                                    <li>• اگر قبلاً گرفته: از آخرین ID + 1</li>
                                    <li>• اگر start_page مشخص شده: از همان ID</li>
                                </ul>
                            </div>
                            <div class="space-y-1">
                                <div class="font-medium">🔧 بروزرسانی هوشمند کتاب‌ها:</div>
                                <ul class="text-xs space-y-1 text-blue-600">
                                    <li>• تکمیل فیلدهای خالی (سال، صفحات، زبان)</li>
                                    <li>• بهبود توضیحات ناقص</li>
                                    <li>• ادغام ISBN و نویسندگان جدید</li>
                                    <li>• بروزرسانی هش‌ها و تصاویر</li>
                                </ul>
                            </div>
                            <div class="space-y-1">
                                <div class="font-medium">📊 ردیابی دقیق تغییرات:</div>
                                <ul class="text-xs space-y-1 text-blue-600">
                                    <li>• تشخیص کتاب‌های جدید vs بهبود یافته</li>
                                    <li>• آمار تفصیلی هر نوع تغییر</li>
                                    <li>• محاسبه نرخ تأثیر واقعی</li>
                                    <li>• لاگ کامل تمام بهبودها</li>
                                </ul>
                            </div>
                        </div>

                        {{-- نمایش آمار کلی سیستم --}}
                        @php
                            $systemStats = [
                                'total_books_enhanced' => \App\Models\ExecutionLog::sum('total_enhanced'),
                                'total_successful_runs' => \App\Models\ExecutionLog::where('status', 'completed')->count(),
                                'total_books_created' => \App\Models\ExecutionLog::sum('total_success'),
                            ];
                        @endphp

                        @if($systemStats['total_books_enhanced'] > 0)
                            <div class="mt-3 pt-3 border-t border-blue-200">
                                <div class="text-xs text-blue-600 space-x-4 space-x-reverse text-center">
                                    <span>🎯 <strong>{{ number_format($systemStats['total_books_created']) }}</strong> کتاب جدید ایجاد شده</span>
                                    <span>🔧 <strong>{{ number_format($systemStats['total_books_enhanced']) }}</strong> کتاب بهبود یافته</span>
                                    <span>✅ <strong>{{ number_format($systemStats['total_successful_runs']) }}</strong> اجرای موفق</span>
                                </div>
                            </div>
                        @endif
                    </div>

                    <!-- وضعیت اجرا -->
                    <td class="p-4">
                        @if ($config->last_run_at)
                            <div class="flex items-center gap-2">
                                @if ($config->is_running)
                                    <span class="text-yellow-600">🔄 در حال اجرا</span>
                                @else
                                    <span class="text-green-600">⏹️ آماده</span>
                                @endif
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                آخرین اجرا: {{ $config->last_run_at->diffForHumans() }}
                            </div>

                            @php
                                $latestLog = $config->executionLogs()->latest()->first();
                            @endphp
                            @if ($latestLog)
                                <div class="text-xs text-gray-400 mt-1">
                                    @if ($latestLog->total_processed > 0)
                                        🎯 {{ number_format($latestLog->total_success) }}/{{ number_format($latestLog->total_processed) }} موفق
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
                            <div class="text-xs text-blue-600 mt-1">
                                شروع از ID {{ $config->getSmartStartPage() }}
                            </div>
                        @endif

                        <!-- تخمین زمان اجرای بعدی -->
                        @if (!$config->is_running)
                            @php
                                $nextRunEstimate = $config->max_pages * $config->delay_seconds;
                                $estimateText = '';
                                if ($nextRunEstimate > 3600) {
                                    $estimateText = '≈' . round($nextRunEstimate / 3600, 1) . 'ساعت';
                                } elseif ($nextRunEstimate > 60) {
                                    $estimateText = '≈' . round($nextRunEstimate / 60) . 'دقیقه';
                                } else {
                                    $estimateText = '≈' . $nextRunEstimate . 'ثانیه';
                                }
                            @endphp
                            <div class="text-xs text-gray-400 mt-1">
                                ⏱️ تخمین اجرای بعدی: {{ $estimateText }}
                            </div>
                        @endif
                    </td>

                    <!-- عملیات -->
                    <td class="p-4">
                        <div class="flex items-center justify-center gap-2">
                            <!-- مشاهده جزئیات -->
                            <a href="{{ route('configs.show', $config) }}"
                               class="text-blue-600 hover:text-blue-800 text-lg" title="مشاهده جزئیات">
                                👁️
                            </a>

                            <!-- مشاهده لاگ‌ها -->
                            <a href="{{ route('configs.logs', $config) }}"
                               class="text-green-600 hover:text-green-800 text-lg" title="لاگ‌ها و آمار">
                                📊
                            </a>

                            <!-- اجرا/توقف -->
                            @if ($config->is_running)
                                <button onclick="stopExecution({{ $config->id }})"
                                        class="text-red-600 hover:text-red-800 text-lg"
                                        title="متوقف کردن اجرا"
                                        id="stop-btn-{{ $config->id }}">
                                    ⏹️
                                </button>
                            @else
                                <button onclick="startExecution({{ $config->id }})"
                                        class="text-green-600 hover:text-green-800 text-lg"
                                        title="شروع اجرای هوشمند"
                                        id="start-btn-{{ $config->id }}">
                                    🚀
                                </button>
                            @endif

                            <!-- ویرایش -->
                            <a href="{{ route('configs.edit', $config) }}"
                               class="text-yellow-600 hover:text-yellow-800 text-lg" title="ویرایش">
                                ✏️
                            </a>

                            <!-- حذف -->
                            <button onclick="deleteConfig({{ $config->id }})"
                                    class="text-red-600 hover:text-red-800 text-lg" title="حذف">
                                🗑️
                            </button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center py-12 text-gray-500">
                        <div class="text-6xl mb-4">🧠</div>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">هیچ کانفیگ هوشمندی یافت نشد!</h3>
                        <p class="text-gray-500 mb-6">اولین کانفیگ هوشمند خود را ایجاد کنید</p>
                        <a href="{{ route('configs.create') }}"
                           class="inline-flex items-center px-6 py-3 bg-blue-600 text-white rounded hover:bg-blue-700">
                            ✨ ایجاد کانفیگ هوشمند
                        </a>
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    @if ($configs instanceof \Illuminate\Pagination\LengthAwarePaginator && $configs->hasPages())
        <div class="mt-6">
            {{ $configs->links() }}
        </div>
    @endif

    <!-- Bottom Info Panel -->
    <div class="mt-6 bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4">
        <h3 class="text-blue-800 font-medium mb-2">🧠 ویژگی‌های سیستم کرال هوشمند:</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 text-sm text-blue-700">
            <div class="space-y-1">
                <div class="font-medium">⚡ تشخیص خودکار نقطه شروع:</div>
                <ul class="text-xs space-y-1 text-blue-600">
                    <li>• اگر قبلاً از منبع کتاب نگرفته: از ID 1</li>
                    <li>• اگر قبلاً گرفته: از آخرین ID + 1</li>
                    <li>• اگر start_page مشخص شده: از همان ID</li>
                </ul>
            </div>
            <div class="space-y-1">
                <div class="font-medium">🔧 مدیریت هوشمند تکراری‌ها:</div>
                <ul class="text-xs space-y-1 text-blue-600">
                    <li>• تشخیص بر اساس MD5 محتوا</li>
                    <li>• تکمیل فیلدهای خالی</li>
                    <li>• بهبود توضیحات ناقص</li>
                    <li>• حفظ کیفیت اطلاعات</li>
                </ul>
            </div>
            <div class="space-y-1">
                <div class="font-medium">📊 ردیابی دقیق منابع:</div>
                <ul class="text-xs space-y-1 text-blue-600">
                    <li>• ثبت دقیق source_id</li>
                    <li>• مدیریت ID های مفقود</li>
                    <li>• گزارش‌گیری تفصیلی</li>
                    <li>• بازیابی ID های ناموفق</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        /**
         * توابع مدیریت اجرا
         */
        function stopExecution(configId) {
            const stopBtn = document.getElementById(`stop-btn-${configId}`);

            if (!confirm('آیا مطمئن هستید که می‌خواهید اجرا را متوقف کنید؟')) {
                return;
            }

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
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert(data.message, 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showAlert(data.message || 'خطا در متوقف کردن اجرا', 'error');
                        stopBtn.disabled = false;
                        stopBtn.innerHTML = '⏹️';
                        stopBtn.title = 'متوقف کردن اجرا';
                    }
                })
                .catch(error => {
                    console.error('خطا در درخواست توقف:', error);
                    showAlert('خطا در ارتباط با سرور: ' + error.message, 'error');
                    stopBtn.disabled = false;
                    stopBtn.innerHTML = '⏹️';
                    stopBtn.title = 'متوقف کردن اجرا';
                });
        }

        function startExecution(configId) {
            const startBtn = document.getElementById(`start-btn-${configId}`);

            if (!confirm('🧠 اجرای هوشمند شروع می‌شود. سیستم خودکار بهترین نقطه شروع را تشخیص می‌دهد. ادامه می‌دهید؟')) {
                return;
            }

            startBtn.disabled = true;
            startBtn.innerHTML = '⏳';

            fetch(`/configs/${configId}/start`, {
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

        function deleteConfig(configId) {
            if (!confirm('⚠️ حذف کانفیگ هوشمند\n\nآیا مطمئن هستید؟ تمام داده‌ها و آمار مرتبط حذف خواهد شد.')) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = `/configs/${configId}`;
            form.style.display = 'none';

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = '_token';
            csrfInput.value = document.querySelector('meta[name="csrf-token"]').content;
            form.appendChild(csrfInput);

            const methodInput = document.createElement('input');
            methodInput.type = 'hidden';
            methodInput.name = '_method';
            methodInput.value = 'DELETE';
            form.appendChild(methodInput);

            document.body.appendChild(form);
            form.submit();
        }

        /**
         * توابع مدیریت Worker
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
            if (!confirm('آیا مطمئن هستید که می‌خواهید Worker را متوقف کنید؟')) return;

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
                    showAlert('خطا در ارتباط با سرور', 'error');
                });
        }

        function restartWorker() {
            if (!confirm('راه‌اندازی مجدد Worker؟')) return;

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
                    showAlert('خطا در ارتباط با سرور', 'error');
                });
        }

        function checkWorker() {
            fetch('/admin/worker/status', {
                method: 'GET',
                headers: { 'Accept': 'application/json' }
            })
                .then(response => response.json())
                .then(data => {
                    const status = data.worker_status.is_running ? 'فعال' : 'غیرفعال';
                    const message = `وضعیت Worker: ${status}\nJobs در صف: ${data.queue_stats.pending_jobs}\nJobs شکست خورده: ${data.queue_stats.failed_jobs}`;
                    showAlert(message, 'info');
                })
                .catch(error => {
                    showAlert('خطا در ارتباط با سرور', 'error');
                });
        }

        /**
         * نمایش پیام
         */
        function showAlert(message, type = 'info') {
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

            setTimeout(() => {
                if (alertBox.parentElement) {
                    alertBox.remove();
                }
            }, 5000);
        }

        // رفرش خودکار برای نمایش وضعیت به‌روز
        setInterval(() => {
            const runningConfigs = document.querySelectorAll('[id^="stop-btn-"]');
            if (runningConfigs.length > 0) {
                location.reload();
            }
        }, 30000);
    </script>
@endsection
