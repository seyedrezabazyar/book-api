@extends('layouts.app')
@section('title', 'لاگ‌های اجرا')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center gap-4">
            <a href="{{ route('configs.index') }}" class="text-gray-600 hover:text-gray-800">←</a>
            <div>
                <h1 class="text-2xl font-semibold">لاگ‌های اجرا</h1>
                <p class="text-gray-600">{{ $config->name }}</p>
            </div>
        </div>

        {{-- در فایل resources/views/configs/logs.blade.php - بخش Quick Stats و آمار تفصیلی را جایگزین کنید --}}

        <!-- Quick Stats بهبود یافته -->
        @php
            $totalLogs = $logs->total();
            $completedLogs = \App\Models\ExecutionLog::where('config_id', $config->id)
                ->where('status', 'completed')
                ->count();
            $failedLogs = \App\Models\ExecutionLog::where('config_id', $config->id)->where('status', 'failed')->count();
            $stoppedLogs = \App\Models\ExecutionLog::where('config_id', $config->id)
                ->where('status', 'stopped')
                ->count();
            $totalSuccessfulBooks = \App\Models\ExecutionLog::where('config_id', $config->id)
                ->whereIn('status', ['completed', 'stopped'])
                ->sum('total_success');
            $totalEnhancedBooks = \App\Models\ExecutionLog::where('config_id', $config->id)
                ->whereIn('status', ['completed', 'stopped'])
                ->sum('total_enhanced');
            $totalProcessedBooks = \App\Models\ExecutionLog::where('config_id', $config->id)
                ->whereIn('status', ['completed', 'stopped'])
                ->sum('total_processed');

            // شمارش کتاب‌های واقعی در دیتابیس
            $actualBooksInDb = \App\Models\Book::where('created_at', '>=', $config->created_at)->count();
        @endphp

        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-7 gap-4">
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-blue-600">{{ number_format($totalLogs) }}</div>
                <div class="text-sm text-gray-600">کل اجراها</div>
            </div>
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-green-600">{{ number_format($completedLogs) }}</div>
                <div class="text-sm text-gray-600">تمام شده</div>
            </div>
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-orange-600">{{ number_format($stoppedLogs) }}</div>
                <div class="text-sm text-gray-600">متوقف شده</div>
            </div>
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-red-600">{{ number_format($failedLogs) }}</div>
                <div class="text-sm text-gray-600">ناموفق</div>
            </div>
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-green-600">{{ number_format($totalSuccessfulBooks) }}</div>
                <div class="text-sm text-gray-600">کتاب‌های جدید</div>
                <div class="text-xs text-gray-500">از {{ number_format($totalProcessedBooks) }} پردازش شده</div>
            </div>
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-purple-600">{{ number_format($totalEnhancedBooks) }}</div>
                <div class="text-sm text-gray-600">کتاب‌های بهبود یافته</div>
                <div class="text-xs text-gray-500">تکمیل و غنی‌سازی</div>
            </div>
            <div class="bg-white p-4 rounded shadow text-center">
                <div class="text-2xl font-bold text-indigo-600">{{ number_format($actualBooksInDb) }}</div>
                <div class="text-sm text-gray-600">کتاب در دیتابیس</div>
                <div class="text-xs text-gray-500">کل واقعی</div>
            </div>
        </div>

        <!-- Config Current Stats بهبود یافته -->
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4">
            <h3 class="text-lg font-semibold text-blue-800 mb-2">📊 آمار کلی کانفیگ</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div>
                    <span class="text-blue-700">کل پردازش شده:</span>
                    <span class="font-bold text-blue-900">{{ number_format($config->total_processed) }}</span>
                </div>
                <div>
                    <span class="text-green-700">جدید:</span>
                    <span class="font-bold text-green-900">{{ number_format($config->total_success) }}</span>
                </div>
                <div>
                    <span class="text-purple-700">بهبود یافته:</span>
                    <span class="font-bold text-purple-900">{{ number_format($totalEnhancedBooks) }}</span>
                </div>
                <div>
                    <span class="text-red-700">خطا:</span>
                    <span class="font-bold text-red-900">{{ number_format($config->total_failed) }}</span>
                </div>
            </div>

            @if ($config->total_processed > 0)
                @php
                    $realSuccessCount = $config->total_success + $totalEnhancedBooks;
                    $realSuccessRate = round(($realSuccessCount / $config->total_processed) * 100, 1);
                    $enhancementRate = round(($totalEnhancedBooks / $config->total_processed) * 100, 1);
                @endphp
                <div class="mt-3">
                    <div class="flex items-center justify-between text-sm text-blue-700 mb-1">
                        <span>نرخ تأثیر کلی: {{ $realSuccessRate }}%</span>
                        <span>نرخ بهبود: {{ $enhancementRate }}%</span>
                    </div>
                    <div class="w-full bg-blue-200 rounded-full h-2 relative">
                        <div class="bg-green-600 h-2 rounded-full absolute"
                             style="width: {{ round(($config->total_success / $config->total_processed) * 100, 1) }}%"></div>
                        <div class="bg-purple-600 h-2 rounded-full absolute"
                             style="left: {{ round(($config->total_success / $config->total_processed) * 100, 1) }}%; width: {{ $enhancementRate }}%"></div>
                    </div>
                    <div class="flex items-center justify-between text-xs text-blue-600 mt-1">
                        <span>🆕 {{ number_format($config->total_success) }} جدید</span>
                        <span>🔧 {{ number_format($totalEnhancedBooks) }} بهبود</span>
                        <span>📊 {{ number_format($realSuccessCount) }} کل تأثیرگذار</span>
                    </div>
                </div>
            @endif
        </div>

        {{-- در بخش table، ستون آمار تفصیلی را بهبود دهید --}}

        <td class="px-4 py-3">
            <div class="text-xs space-y-1">
                @if ($log->status === 'completed' || $log->status === 'stopped')
                    @if ($log->total_processed > 0)
                        <div class="grid grid-cols-2 gap-2">
                            <div>کل: <span class="font-medium">{{ number_format($log->total_processed) }}</span></div>
                            <div>✅ جدید: <span class="font-medium text-green-600">{{ number_format($log->total_success) }}</span></div>

                            @if ($log->total_enhanced > 0)
                                <div>🔧 بهبود: <span class="font-medium text-purple-600">{{ number_format($log->total_enhanced) }}</span></div>
                            @endif

                            @if ($log->total_duplicate > 0)
                                <div>🔄 تکراری: <span class="font-medium text-yellow-600">{{ number_format($log->total_duplicate) }}</span></div>
                            @endif

                            @if ($log->total_failed > 0)
                                <div>❌ خطا: <span class="font-medium text-red-600">{{ number_format($log->total_failed) }}</span></div>
                            @endif
                        </div>

                        <div class="pt-1 border-t border-gray-200">
                            @php
                                $realLogSuccess = $log->total_success + $log->total_enhanced;
                                $realLogSuccessRate = round(($realLogSuccess / $log->total_processed) * 100, 1);
                            @endphp
                            <div>نرخ تأثیر: <span class="font-medium">{{ $realLogSuccessRate }}%</span></div>
                            <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                                <div class="bg-gradient-to-r from-green-600 to-purple-600 h-1.5 rounded-full"
                                     style="width: {{ $realLogSuccessRate }}%"></div>
                            </div>
                            @if($log->total_enhanced > 0)
                                <div class="text-xs text-purple-600 mt-1">
                                    {{ number_format($log->total_enhanced) }} کتاب بهبود یافت
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="text-gray-500">هیچ رکوردی پردازش نشد</div>
                    @endif
                @elseif($log->status === 'failed')
                    <div class="text-red-600">اجرا با خطا متوقف شد</div>
                    @if ($log->error_message)
                        <div class="text-xs text-red-500">{{ Str::limit($log->error_message, 60) }}</div>
                    @endif
                @else
                    <div class="text-yellow-600">در حال پردازش...</div>
                    @if ($log->total_processed > 0)
                        @php
                            $currentRealSuccess = $log->total_success + $log->total_enhanced;
                        @endphp
                        <div class="text-xs">
                            تاکنون: {{ $currentRealSuccess }} تأثیرگذار از {{ $log->total_processed }}
                            @if($log->total_enhanced > 0)
                                <br><span class="text-purple-600">({{ $log->total_enhanced }} بهبود)</span>
                            @endif
                        </div>
                    @endif
                @endif
            </div>
        </td>

        {{-- در بخش Bottom Stats Summary --}}

        <!-- Bottom Stats Summary بهبود یافته -->
        @if ($totalLogs > 0)
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <h3 class="text-sm font-medium text-gray-800 mb-2">📈 خلاصه عملکرد پیشرفته:</h3>
                <div class="text-xs text-gray-700 space-y-1">
                    <div>• <strong>کل اجراها:</strong> {{ $totalLogs }} بار ({{ $completedLogs }} موفق، {{ $stoppedLogs }} متوقف، {{ $failedLogs }} ناموفق)</div>
                    <div>• <strong>کل رکوردهای پردازش شده:</strong> {{ number_format($totalProcessedBooks) }} رکورد</div>
                    <div>• <strong>کتاب‌های جدید ایجاد شده:</strong> {{ number_format($totalSuccessfulBooks) }} کتاب</div>
                    <div>• <strong>کتاب‌های بهبود یافته:</strong> {{ number_format($totalEnhancedBooks) }} کتاب</div>
                    <div>• <strong>کتاب‌های واقعی در دیتابیس:</strong> {{ number_format($actualBooksInDb) }} کتاب</div>
                    @if ($totalProcessedBooks > 0)
                        @php
                            $totalImpactfulBooks = $totalSuccessfulBooks + $totalEnhancedBooks;
                            $overallImpactRate = round(($totalImpactfulBooks / $totalProcessedBooks) * 100, 1);
                        @endphp
                        <div>• <strong>نرخ تأثیر کلی:</strong> {{ $overallImpactRate }}% ({{ number_format($totalImpactfulBooks) }} کتاب تأثیرگذار)</div>
                        <div>• <strong>تفکیک تأثیر:</strong>
                            {{ round(($totalSuccessfulBooks / $totalProcessedBooks) * 100, 1) }}% جدید +
                            {{ round(($totalEnhancedBooks / $totalProcessedBooks) * 100, 1) }}% بهبود
                        </div>
                    @endif
                </div>

                {{-- نمایش پیشرفت به صورت نمودار --}}
                @if($totalProcessedBooks > 0)
                    <div class="mt-3">
                        <div class="text-xs text-gray-600 mb-1">توزیع نتایج پردازش:</div>
                        <div class="w-full bg-gray-200 rounded-full h-3 relative overflow-hidden">
                            @php
                                $successPercent = ($totalSuccessfulBooks / $totalProcessedBooks) * 100;
                                $enhancedPercent = ($totalEnhancedBooks / $totalProcessedBooks) * 100;
                                $duplicatePercent = (($totalProcessedBooks - $totalSuccessfulBooks - $totalEnhancedBooks - $config->total_failed) / $totalProcessedBooks) * 100;
                                $failedPercent = ($config->total_failed / $totalProcessedBooks) * 100;
                            @endphp
                            <div class="bg-green-600 h-3 absolute" style="width: {{ $successPercent }}%; left: 0"></div>
                            <div class="bg-purple-600 h-3 absolute" style="width: {{ $enhancedPercent }}%; left: {{ $successPercent }}%"></div>
                            <div class="bg-yellow-500 h-3 absolute" style="width: {{ $duplicatePercent }}%; left: {{ $successPercent + $enhancedPercent }}%"></div>
                            <div class="bg-red-500 h-3 absolute" style="width: {{ $failedPercent }}%; left: {{ $successPercent + $enhancedPercent + $duplicatePercent }}%"></div>
                        </div>
                        <div class="flex items-center justify-between text-xs text-gray-600 mt-1">
                            <span>🆕 {{ round($successPercent, 1) }}%</span>
                            <span>🔧 {{ round($enhancedPercent, 1) }}%</span>
                            <span>🔄 {{ round($duplicatePercent, 1) }}%</span>
                            <span>❌ {{ round($failedPercent, 1) }}%</span>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>

    <script>
        function executeBackground(configId) {
            if (!confirm('اجرای بک‌گراند شروع می‌شود. ادامه می‌دهید؟')) return;

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
                        alert('✅ ' + data.message);
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        alert('❌ ' + data.message);
                    }
                })
                .catch(() => alert('❌ خطا در شروع اجرا'));
        }
    </script>
@endsection
