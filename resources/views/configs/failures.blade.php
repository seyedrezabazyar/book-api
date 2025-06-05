<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>شکست‌های اسکرپ - {{ $config->name }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body class="bg-gray-50">
<div class="container mx-auto px-4 py-6">
    <!-- هدر -->
    <div class="mb-6">
        <div class="flex items-center mb-4">
            <a href="{{ route('configs.index') }}" class="text-gray-600 hover:text-gray-800 ml-4">
                ← بازگشت
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-800">شکست‌های اسکرپ</h1>
                <p class="text-gray-600">{{ $config->name }}</p>
            </div>
        </div>

        <!-- دکمه‌ها -->
        <div class="flex gap-3">
            <form method="POST" action="{{ route('configs.resolve-all-failures', $config) }}" class="inline">
                @csrf
                <button type="submit"
                        class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700"
                        onclick="return confirm('حل کردن همه شکست‌ها؟')">
                    ✅ حل همه
                </button>
            </form>

            <a href="{{ route('configs.show', $config) }}"
               class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                📊 آمار کانفیگ
            </a>
        </div>
    </div>

    <!-- آمار سریع -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white p-4 rounded-lg shadow">
            <div class="text-sm text-gray-500">کل شکست‌ها</div>
            <div class="text-2xl font-bold text-red-600">{{ $failures->total() }}</div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow">
            <div class="text-sm text-gray-500">حل نشده</div>
            <div class="text-2xl font-bold text-orange-600">
                {{ $config->failures()->unresolved()->count() }}
            </div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow">
            <div class="text-sm text-gray-500">حل شده</div>
            <div class="text-2xl font-bold text-green-600">
                {{ $config->failures()->where('is_resolved', true)->count() }}
            </div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow">
            <div class="text-sm text-gray-500">آخرین شکست</div>
            <div class="text-sm font-medium">
                {{ $config->failures()->latest()->first()?->created_at?->diffForHumans() ?? 'هیچ' }}
            </div>
        </div>
    </div>

    <!-- لیست شکست‌ها -->
    <div class="bg-white rounded-lg shadow">
        @if($failures->count() > 0)
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">URL</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">خطا</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">تلاش</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">زمان</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">وضعیت</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">عمل</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                    @foreach($failures as $failure)
                        <tr class="hover:bg-gray-50 {{ $failure->is_resolved ? 'opacity-50' : '' }}">
                            <!-- URL -->
                            <td class="px-6 py-4">
                                <div class="text-sm">
                                    <a href="{{ $failure->url }}" target="_blank"
                                       class="text-blue-600 hover:underline break-all">
                                        {{ Str::limit($failure->url, 60) }}
                                    </a>
                                    @if($failure->http_status)
                                        <div class="text-xs text-gray-500">
                                            HTTP {{ $failure->http_status }}
                                        </div>
                                    @endif
                                </div>
                            </td>

                            <!-- پیام خطا -->
                            <td class="px-6 py-4">
                                <div class="text-sm text-red-700">
                                    {{ Str::limit($failure->error_message, 80) }}
                                </div>
                                @if($failure->error_details)
                                    <button onclick="toggleDetails({{ $failure->id }})"
                                            class="text-xs text-blue-600 hover:underline">
                                        جزئیات بیشتر
                                    </button>
                                    <div id="details-{{ $failure->id }}" class="hidden mt-2 p-2 bg-gray-100 rounded text-xs">
                                        <pre>{{ json_encode($failure->error_details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </div>
                                @endif
                            </td>

                            <!-- تعداد تلاش -->
                            <td class="px-6 py-4">
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                            @if($failure->retry_count == 0) bg-gray-100 text-gray-800
                                            @elseif($failure->retry_count < 3) bg-yellow-100 text-yellow-800
                                            @else bg-red-100 text-red-800 @endif">
                                            {{ $failure->retry_count }}
                                        </span>
                            </td>

                            <!-- زمان -->
                            <td class="px-6 py-4">
                                <div class="text-sm text-gray-900">
                                    {{ $failure->last_attempt_at->format('m/d H:i') }}
                                </div>
                                <div class="text-xs text-gray-500">
                                    {{ $failure->last_attempt_at->diffForHumans() }}
                                </div>
                            </td>

                            <!-- وضعیت -->
                            <td class="px-6 py-4">
                                @if($failure->is_resolved)
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                                ✅ حل شده
                                            </span>
                                @else
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                                ❌ حل نشده
                                            </span>
                                @endif
                            </td>

                            <!-- عملیات -->
                            <td class="px-6 py-4">
                                @if(!$failure->is_resolved)
                                    <form method="POST" action="{{ route('configs.resolve-failure', [$config, $failure]) }}" class="inline">
                                        @csrf
                                        <button type="submit"
                                                class="text-green-600 hover:text-green-900 text-sm"
                                                title="حل کردن">
                                            ✅
                                        </button>
                                    </form>
                                @endif

                                <!-- کپی URL -->
                                <button onclick="copyUrl('{{ $failure->url }}')"
                                        class="text-blue-600 hover:text-blue-900 text-sm mr-2"
                                        title="کپی URL">
                                    📋
                                </button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <!-- صفحه‌بندی -->
            @if($failures->hasPages())
                <div class="px-4 py-3 border-t">
                    {{ $failures->links() }}
                </div>
            @endif
        @else
            <!-- پیام عدم وجود شکست -->
            <div class="text-center py-12">
                <div class="text-6xl mb-4">🎉</div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">هیچ شکستی ثبت نشده!</h3>
                <p class="text-gray-500">این کانفیگ بدون مشکل کار می‌کند.</p>
            </div>
        @endif
    </div>
</div>

<script>
    function toggleDetails(id) {
        const element = document.getElementById(`details-${id}`);
        element.classList.toggle('hidden');
    }

    function copyUrl(url) {
        navigator.clipboard.writeText(url).then(() => {
            alert('URL کپی شد!');
        });
    }
</script>
</body>
</html>
