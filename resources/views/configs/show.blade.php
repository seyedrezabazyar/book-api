@extends('layouts.app')
@section('title', 'نمایش کانفیگ')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="{{ route('configs.index') }}" class="text-gray-600 hover:text-gray-800">←</a>
                <div>
                    <h1 class="text-2xl font-semibold">{{ $config->name }}</h1>
                    <p class="text-gray-600">جزئیات و آمار کانفیگ</p>
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
                    همیشه فعال
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
            </div>
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
                <div class="text-2xl font-bold text-purple-600">{{ number_format($config->current_page ?? 1) }}</div>
                <div class="text-sm text-gray-600">صفحه فعلی</div>
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
                        <div class="text-sm text-gray-600">آدرس پایه</div>
                        <div class="text-sm break-all">{{ $config->base_url }}</div>
                    </div>
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
                    <div>
                        <div class="text-sm text-gray-600">حالت کرال</div>
                        <div class="font-medium">
                            @php $crawlModes = \App\Models\Config::getCrawlModes(); @endphp
                            {{ $crawlModes[$config->crawl_mode] ?? $config->crawl_mode }}
                        </div>
                    </div>
                    @if ($config->start_page)
                        <div>
                            <div class="text-sm text-gray-600">صفحه شروع</div>
                            <div class="font-medium">{{ $config->start_page }}</div>
                        </div>
                    @endif
                </div>
            </div>

            <!-- API Settings -->
            @php $apiSettings = $config->getApiSettings(); @endphp
            <div class="bg-white rounded shadow p-6">
                <h2 class="text-lg font-medium mb-4">تنظیمات API</h2>
                <div class="space-y-3">
                    <div>
                        <div class="text-sm text-gray-600">Endpoint</div>
                        <div class="text-sm break-all">{{ $apiSettings['endpoint'] ?? '-' }}</div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <div class="text-sm text-gray-600">متد HTTP</div>
                            <div class="font-medium">{{ $apiSettings['method'] ?? 'GET' }}</div>
                        </div>
                    </div>
                    @if (!empty($apiSettings['field_mapping']))
                        <div>
                            <div class="text-sm text-gray-600">نقشه‌برداری فیلدها</div>
                            <div class="text-xs bg-gray-50 p-2 rounded mt-1">
                                {{ count($apiSettings['field_mapping']) }} فیلد تعریف شده
                            </div>
                        </div>
                    @endif
                </div>
            </div>

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
                                </div>
                                <div class="text-right">
                                    @if ($log->status === 'completed')
                                        <div class="text-xs text-green-600">✅ موفق: {{ $log->total_success }}</div>
                                        @if ($log->total_failed > 0)
                                            <div class="text-xs text-red-600">❌ خطا: {{ $log->total_failed }}</div>
                                        @endif
                                    @elseif($log->status === 'failed')
                                        <div class="text-xs text-red-600">❌ ناموفق</div>
                                    @else
                                        <div class="text-xs text-yellow-600">🔄 در حال اجرا</div>
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
                    </div>
                @endif
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
                        location.reload();
                    } else {
                        alert(data.message);
                    }
                })
                .catch(() => alert('خطا در اجرا'));
        }

        @if ($config->is_running)
            setTimeout(() => location.reload(), 10000);
        @endif
    </script>
@endsection
