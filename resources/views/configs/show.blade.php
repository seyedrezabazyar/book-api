@extends('layouts.app')
@section('title', 'نمایش کانفیگ')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="{{ route('configs.index') }}" class="text-gray-600 hover:text-gray-800">←</a>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-2xl font-semibold">{{ $config->name }}</h1>
                        <span class="px-2 py-1 text-xs rounded font-medium {{ $config->source_type === 'api' ? 'bg-blue-100 text-blue-800' : 'bg-orange-100 text-orange-800' }}">
                            {{ $config->source_type === 'api' ? '🌐 API' : '🕷️ Crawler' }}
                        </span>
                    </div>
                    <p class="text-gray-600">جزئیات و آمار کانفیگ - {{ $config->source_name }}</p>
                </div>
            </div>

            <div class="flex gap-3">
                @if (!$config->is_running)
                    <button onclick="executeBackground({{ $config->id }})"
                            class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
                        🚀 اجرای بک‌گراند
                    </button>
                    <form method="POST" action="{{ route('configs.run-sync', $config) }}" class="inline">
                        @csrf
                        <button type="submit" class="bg-orange-600 text-white px-4 py-2 rounded hover:bg-orange-700"
                                onclick="return confirm('اجرای فوری شروع می‌شود. ادامه می‌دهید؟')">
                            ⚡ اجرای فوری
                        </button>
                    </form>
                @endif

                <a href="{{ route('configs.edit', $config) }}"
                   class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">ویرایش</a>
                <a href="{{ route('configs.logs', $config) }}"
                   class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700">لاگ‌ها</a>
            </div>
        </div>

        <!-- Status -->
        <div class="bg-white rounded shadow p-6">
            <div class="flex items-center gap-4">
                <span class="px-3 py-1 text-sm rounded font-medium bg-green-100 text-green-800">
                    {{ $config->is_active ? '✅ فعال' : '❌ غیرفعال' }}
                </span>

                @if ($config->is_running)
                    <span class="inline-flex items-center px-3 py-1 text-sm bg-blue-100 text-blue-800 rounded">
                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                    stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor"
                                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>
                        در حال اجرا
                    </span>
                @endif

                <span class="px-3 py-1 text-sm rounded font-medium {{ $config->source_type === 'api' ? 'bg-blue-100 text-blue-800' : 'bg-orange-100 text-orange-800' }}">
                    {{ $config->source_type === 'api' ? '🌐 API Mode' : '🕷️ Crawler Mode' }}
                </span>
            </div>
        </div>

        <!-- Smart Start Page Info -->
        @php
            $smartStartPage = $config->getSmartStartPage();
            $lastIdFromSources = $config->getLastSourceIdFromBookSources();
            $hasUserDefined = $config->hasUserDefinedStartPage();
        @endphp
        <div class="bg-gradient-to-r from-indigo-50 to-blue-50 border border-indigo-200 rounded-lg p-4">
            <h3 class="text-indigo-800 font-medium mb-2">🧠 اطلاعات کرال هوشمند:</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm text-indigo-700">
                <div>
                    <span class="text-indigo-600">آخرین ID ثبت شده:</span>
                    <span class="font-bold">{{ $lastIdFromSources > 0 ? number_format($lastIdFromSources) : 'هیچ' }}</span>
                </div>
                <div>
                    <span class="text-indigo-600">Smart Start Page:</span>
                    <span class="font-bold">{{ number_format($smartStartPage) }}</span>
                </div>
                <div>
                    <span class="text-indigo-600">start_page تنظیمی:</span>
                    <span class="font-bold">{{ $config->start_page ? number_format($config->start_page) : 'خالی (هوشمند)' }}</span>
                </div>
                <div>
                    <span class="text-indigo-600">حالت اجرا:</span>
                    <span class="font-bold">{{ $hasUserDefined ? '⚙️ دستی' : '🧠 هوشمند' }}</span>
                </div>
            </div>
            @if ($hasUserDefined && $config->start_page <= $lastIdFromSources)
                <div class="mt-2 text-xs text-red-600">⚠️ start_page تنظیم شده قبلاً پردازش شده است</div>
            @endif
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-4 gap-4">
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-blue-600">{{ number_format($config->total_processed ?? 0) }}</div>
                <div class="text-sm text-gray-600">کل پردازش شده</div>
            </div>
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-green-600">{{ number_format($config->total_success ?? 0) }}</div>
                <div class="text-sm text-gray-600">موفق</div>
            </div>
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-red-600">{{ number_format($config->total_failed ?? 0) }}</div>
                <div class="text-sm text-gray-600">خطا</div>
            </div>
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-purple-600">{{ number_format($config->current_page ?? $smartStartPage) }}</div>
                <div class="text-sm text-gray-600">صفحه بعدی</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Info -->
            <div class="bg-white rounded shadow p-6">
                <h2 class="text-lg font-medium mb-4">اطلاعات کلی</h2>
                <div class="space-y-3">
                    <div>
                        <div class="text-sm text-gray-600">نام کانفیگ</div>
                        <div class="font-medium">{{ $config->name }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-600">نوع منبع</div>
                        <div class="font-medium">
                            @if ($config->source_type === 'api')
                                🌐 API (REST/JSON)
                            @else
                                🕷️ Crawler (HTML Scraping)
                            @endif
                        </div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-600">آدرس پایه</div>
                        <div class="text-sm break-all">{{ $config->base_url }}</div>
                    </div>
                    @if ($config->source_type === 'crawler')
                        <div>
                            <div class="text-sm text-gray-600">الگوی صفحه</div>
                            <div class="text-sm font-mono">{{ $config->page_pattern ?: '/book/{id}' }}</div>
                        </div>
                        @if ($config->user_agent)
                            <div>
                                <div class="text-sm text-gray-600">User Agent</div>
                                <div class="text-xs break-all">{{ Str::limit($config->user_agent, 60) }}</div>
                            </div>
                        @endif
                    @endif
                    @if ($config->last_run_at)
                        <div>
                            <div class="text-sm text-gray-600">آخرین اجرا</div>
                            <div class="text-sm">{{ $config->last_run_at->format('Y/m/d H:i:s') }}</div>
                            <div class="text-xs text-gray-400">{{ $config->last_run_at->diffForHumans() }}</div>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Settings -->
            <div class="bg-white rounded shadow p-6">
                <h2 class="text-lg font-medium mb-4">تنظیمات اجرا</h2>
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <div class="text-sm text-gray-600">Timeout</div>
                            <div class="font-medium">{{ $config->timeout }}s</div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-600">تاخیر درخواست</div>
                            <div class="font-medium">{{ $config->delay_seconds }}s</div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <div class="text-sm text-gray-600">رکورد در هر اجرا</div>
                            <div class="font-medium">{{ $config->records_per_run }}</div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-600">تاخیر صفحه</div>
                            <div class="font-medium">{{ $config->page_delay }}s</div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <div class="text-sm text-gray-600">حداکثر صفحات</div>
                            <div class="font-medium">{{ number_format($config->max_pages) }}</div>
                        </div>
                        @if ($config->start_page)
                            <div>
                                <div class="text-sm text-gray-600">صفحه شروع</div>
                                <div class="font-medium">{{ number_format($config->start_page) }}</div>
                            </div>
                        @endif
                    </div>

                    <!-- تنظیمات هوشمند -->
                    <div class="pt-3 border-t">
                        <div class="text-sm text-gray-600 mb-2">تنظیمات هوشمند</div>
                        <div class="flex flex-wrap gap-2">
                            @if ($config->auto_resume)
                                <span class="px-2 py-1 text-xs bg-blue-100 text-blue-700 rounded">⚡ ادامه خودکار</span>
                            @endif
                            @if ($config->fill_missing_fields)
                                <span class="px-2 py-1 text-xs bg-green-100 text-green-700 rounded">🔧 تکمیل خودکار</span>
                            @endif
                            @if ($config->update_descriptions)
                                <span class="px-2 py-1 text-xs bg-purple-100 text-purple-700 rounded">📝 بهبود توضیحات</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            @if ($config->source_type === 'api')
                <!-- API Settings -->
                @php $apiSettings = $config->getApiSettings(); @endphp
                <div class="bg-white rounded shadow p-6">
                    <h2 class="text-lg font-medium mb-4">🌐 تنظیمات API</h2>
                    <div class="space-y-3">
                        <div>
                            <div class="text-sm text-gray-600">Endpoint</div>
                            <div class="text-sm break-all font-mono bg-gray-50 p-2 rounded">{{ $apiSettings['endpoint'] ?? 'تعریف نشده' }}</div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <div class="text-sm text-gray-600">متد HTTP</div>
                                <div class="font-medium">{{ $apiSettings['method'] ?? 'GET' }}</div>
                            </div>
                            <div>
                                <div class="text-sm text-gray-600">نقشه‌برداری فیلدها</div>
                                <div class="text-sm">
                                    {{ !empty($apiSettings['field_mapping']) ? count($apiSettings['field_mapping']) . ' فیلد' : 'پیش‌فرض' }}
                                </div>
                            </div>
                        </div>
                        @if (!empty($apiSettings['params']))
                            <div>
                                <div class="text-sm text-gray-600">پارامترهای اضافی</div>
                                <div class="text-xs bg-gray-50 p-2 rounded mt-1">
                                    @foreach ($apiSettings['params'] as $key => $value)
                                        <div>{{ $key }}: {{ $value }}</div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <!-- نمایش مثال URL -->
                        <div>
                            <div class="text-sm text-gray-600">مثال URL کامل</div>
                            <div class="text-xs bg-blue-50 p-2 rounded mt-1 font-mono break-all">
                                {{ $config->buildApiUrl(123) }}
                            </div>
                        </div>
                    </div>
                </div>
            @else
                <!-- Crawler Settings -->
                @php $crawlerSettings = $config->getCrawlerSettings(); @endphp
                <div class="bg-white rounded shadow p-6">
                    <h2 class="text-lg font-medium mb-4">🕷️ تنظیمات Crawler</h2>
                    <div class="space-y-3">
                        <div>
                            <div class="text-sm text-gray-600">الگوی صفحه</div>
                            <div class="text-sm break-all font-mono bg-gray-50 p-2 rounded">{{ $config->page_pattern ?: '/book/{id}' }}</div>
                        </div>
                        @if ($config->user_agent)
                            <div>
                                <div class="text-sm text-gray-600">User Agent</div>
                                <div class="text-xs bg-gray-50 p-2 rounded break-all">{{ $config->user_agent }}</div>
                            </div>
                        @endif
                        <div>
                            <div class="text-sm text-gray-600">CSS Selectors</div>
                            <div class="text-sm">
                                {{ !empty($crawlerSettings['selector_mapping']) ? count($crawlerSettings['selector_mapping']) . ' selector تعریف شده' : 'پیش‌فرض' }}
                            </div>
                            @if (!empty($crawlerSettings['selector_mapping']))
                                <div class="text-xs bg-gray-50 p-2 rounded mt-1 max-h-32 overflow-y-auto">
                                    @foreach ($crawlerSettings['selector_mapping'] as $field => $selector)
                                        @if ($selector)
                                            <div class="flex justify-between">
                                                <span class="text-gray-600">{{ $field }}:</span>
                                                <span class="font-mono">{{ $selector }}</span>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <!-- نمایش مثال URL -->
                        <div>
                            <div class="text-sm text-gray-600">مثال URL کامل</div>
                            <div class="text-xs bg-orange-50 p-2 rounded mt-1 font-mono break-all">
                                {{ $config->buildCrawlerUrl(123) }}
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <!-- Recent Logs -->
            <div class="bg-white rounded shadow p-6">
                <h2 class="text-lg font-medium mb-4">آخرین اجراها</h2>
                @if ($recentLogs->count() > 0)
                    <div class="space-y-3">
                        @foreach ($recentLogs as $log)
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded">
                                <div>
                                    <div class="text-sm font-medium">{{ $log->execution_id }}</div>
                                    <div class="text-xs text-gray-500">{{ $log->started_at->diffForHumans() }}</div>
                                    @if ($log->execution_time > 0)
                                        <div class="text-xs text-gray-400">⏱️ {{ round($log->execution_time) }}s</div>
                                    @endif
                                </div>
                                <div class="text-right">
                                    @if ($log->status === 'completed')
                                        <div class="text-xs text-green-600">✅ موفق: {{ number_format($log->total_success) }}</div>
                                        @if ($log->total_enhanced > 0)
                                            <div class="text-xs text-purple-600">🔧 بهبود: {{ number_format($log->total_enhanced) }}</div>
                                        @endif
                                        @if ($log->total_failed > 0)
                                            <div class="text-xs text-red-600">❌ خطا: {{ number_format($log->total_failed) }}</div>
                                        @endif
                                        @if ($log->total_processed > 0)
                                            @php
                                                $realSuccess = $log->total_success + $log->total_enhanced;
                                                $realSuccessRate = round(($realSuccess / $log->total_processed) * 100, 1);
                                            @endphp
                                            <div class="text-xs text-gray-500">📊 تأثیر: {{ $realSuccessRate }}%</div>
                                        @endif
                                    @elseif($log->status === 'failed')
                                        <div class="text-xs text-red-600">❌ ناموفق</div>
                                        @if ($log->error_message)
                                            <div class="text-xs text-gray-500" title="{{ $log->error_message }}">
                                                {{ Str::limit($log->error_message, 30) }}
                                            </div>
                                        @endif
                                    @elseif($log->status === 'stopped')
                                        <div class="text-xs text-orange-600">⏹️ متوقف شده</div>
                                        @if ($log->total_processed > 0)
                                            <div class="text-xs text-gray-500">📊 پردازش: {{ number_format($log->total_processed) }}</div>
                                        @endif
                                    @else
                                        <div class="text-xs text-yellow-600">🔄 در حال اجرا</div>
                                        @if ($log->total_processed > 0)
                                            <div class="text-xs text-gray-500">📊 تاکنون: {{ number_format($log->total_processed) }}</div>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-4">
                        <a href="{{ route('configs.logs', $config) }}" class="text-blue-600 hover:text-blue-800 text-sm">
                            مشاهده همه لاگ‌ها →
                        </a>
                    </div>
                @else
                    <div class="text-center py-4 text-gray-500">
                        <div class="text-2xl mb-2">📊</div>
                        <div class="text-sm">هنوز اجرایی انجام نشده</div>
                        <div class="text-xs mt-1">اولین اجرای {{ $config->source_type === 'api' ? 'API' : 'Crawler' }} خود را شروع کنید</div>
                    </div>
                @endif
            </div>
        </div>

        <!-- URL Preview and Test -->
        <div class="bg-gray-50 rounded-lg p-4">
            <h3 class="text-md font-medium text-gray-900 mb-2">🔍 پیش‌نمایش URL و تست:</h3>
            <div class="space-y-3">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-gray-600">نوع منبع:</span>
                        <span class="font-medium">{{ $config->source_type === 'api' ? '🌐 API (JSON)' : '🕷️ Crawler (HTML)' }}</span>
                    </div>
                    <div>
                        <span class="text-gray-600">اجرای بعدی از:</span>
                        <span class="font-medium text-blue-600">ID {{ number_format($smartStartPage) }}</span>
                    </div>
                    <div>
                        <span class="text-gray-600">تخمین زمان اجرا:</span>
                        @php
                            $estimatedTime = ($config->max_pages ?? 1000) * ($config->delay_seconds ?? 3);
                            $hours = floor($estimatedTime / 3600);
                            $minutes = floor(($estimatedTime % 3600) / 60);
                            $timeText = '';
                            if ($hours > 0) $timeText .= "{$hours}ساعت ";
                            if ($minutes > 0) $timeText .= "{$minutes}دقیقه";
                            if (!$timeText) $timeText = 'کمتر از یک دقیقه';
                        @endphp
                        <span class="font-medium text-purple-600">{{ $timeText }}</span>
                    </div>
                    <div>
                        <span class="text-gray-600">URL نمونه:</span>
                        <span class="font-mono text-xs break-all">
                            @if ($config->source_type === 'api')
                                {{ $config->buildApiUrl($smartStartPage) }}
                            @else
                                {{ $config->buildCrawlerUrl($smartStartPage) }}
                            @endif
                        </span>
                    </div>
                </div>

                <!-- تست سریع -->
                <div class="pt-3 border-t border-gray-200">
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-600">تست سریع:</span>
                        <button onclick="testSingleUrl({{ $smartStartPage }})"
                                class="px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">
                            🧪 تست ID {{ $smartStartPage }}
                        </button>
                        <button onclick="testSingleUrl(1)"
                                class="px-3 py-1 bg-green-600 text-white rounded text-sm hover:bg-green-700">
                            🧪 تست ID 1
                        </button>
                        <input type="number" id="test-id" placeholder="ID دلخواه" min="1"
                               class="px-2 py-1 border rounded text-sm w-24">
                        <button onclick="testSingleUrl(document.getElementById('test-id').value)"
                                class="px-3 py-1 bg-purple-600 text-white rounded text-sm hover:bg-purple-700">
                            🧪 تست
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Info -->
        <div class="bg-gray-50 rounded p-4">
            <div class="text-xs text-gray-600 grid grid-cols-2 md:grid-cols-4 gap-4">
                <div><span class="font-medium">ایجاد:</span> {{ $config->created_at->format('Y/m/d') }}</div>
                <div><span class="font-medium">آپدیت:</span> {{ $config->updated_at->format('Y/m/d') }}</div>
                <div><span class="font-medium">ایجادکننده:</span> {{ $config->createdBy->name ?? 'نامشخص' }}</div>
                <div><span class="font-medium">ID:</span> {{ $config->id }}</div>
            </div>
        </div>
    </div>

    <script>
        function executeBackground(configId) {
            if (!confirm('اجرا در پس‌زمینه شروع می‌شود. ادامه می‌دهید؟')) return;

            fetch(`/configs/${configId}/execute-background`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json'
                }
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('✅ ' + data.message);
                        location.reload();
                    } else {
                        alert('❌ ' + data.message);
                    }
                })
                .catch(() => alert('❌ خطا در اجرا'));
        }

        function testSingleUrl(sourceId) {
            if (!sourceId || sourceId < 1) {
                alert('⚠️ لطفاً یک ID معتبر وارد کنید');
                return;
            }

            const configType = '{{ $config->source_type }}';
            let testUrl;

            if (configType === 'api') {
                testUrl = '{{ $config->buildApiUrl(0) }}'.replace('0', sourceId);
            } else {
                testUrl = '{{ $config->buildCrawlerUrl(0) }}'.replace('0', sourceId);
            }

            // نمایش URL در یک پنجره جدید
            const message = `🔗 URL تست:\n${testUrl}\n\nآیا می‌خواهید این URL را در مرورگر باز کنید؟`;

            if (confirm(message)) {
                window.open(testUrl, '_blank');
            }
        }

        @if ($config->is_running)
        // رفرش خودکار اگر در حال اجرا باشد
        setTimeout(() => location.reload(), 15000);
        @endif
    </script>
@endsection
