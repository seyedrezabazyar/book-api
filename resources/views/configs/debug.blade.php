@extends('layouts.app')

@section('title', 'Debug کانفیگ')

@section('content')
    <div class="container mx-auto px-4 py-6">
        {{-- هدر صفحه --}}
        <div class="mb-6">
            <div class="flex items-center mb-4">
                <a
                    href="{{ route('configs.index') }}"
                    class="text-gray-600 hover:text-gray-800 ml-4"
                    title="بازگشت به لیست"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">🐛 Debug کانفیگ</h1>
                    <p class="text-gray-600">{{ $config->name }}</p>
                </div>
            </div>

            {{-- هشدار محیط development --}}
            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                <div class="flex">
                    <svg class="w-5 h-5 text-yellow-600 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 19c-.77.833.192 2.5 1.732 2.5z"></path>
                    </svg>
                    <div>
                        <strong>توجه:</strong> این صفحه فقط در محیط development قابل دسترسی است و اطلاعات تکنیکی نمایش می‌دهد.
                    </div>
                </div>
            </div>
        </div>

        {{-- دکمه‌های عمل --}}
        <div class="flex items-center gap-4 mb-6">
            <button
                onclick="runDebugApi()"
                id="debugApiBtn"
                class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50"
            >
                <span class="button-text">🔍 تست و تحلیل API</span>
                <span class="loading-text hidden">
                    <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    در حال تحلیل...
                </span>
            </button>

            <button
                onclick="clearDebugData()"
                class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500"
            >
                🗑️ پاک کردن debug
            </button>

            <a
                href="{{ route('configs.stats', $config) }}"
                class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500"
            >
                📊 مشاهده آمار
            </a>
        </div>

        {{-- وضعیت فعلی --}}
        <div class="bg-white rounded-lg shadow mb-6 p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">وضعیت فعلی</h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="text-center">
                    <div class="text-sm text-gray-500 mb-2">وضعیت کانفیگ</div>
                    <span class="inline-flex px-3 py-1 text-sm font-semibold rounded-full
                        @if($config->status === 'active') bg-green-100 text-green-800
                        @elseif($config->status === 'inactive') bg-red-100 text-red-800
                        @else bg-yellow-100 text-yellow-800 @endif
                    ">
                        {{ $config->status_text }}
                    </span>
                </div>

                <div class="text-center">
                    <div class="text-sm text-gray-500 mb-2">وضعیت اجرا</div>
                    @if($isRunning)
                        <span class="inline-flex items-center px-3 py-1 text-sm font-medium bg-yellow-100 text-yellow-800 rounded-full">
                            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-yellow-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            در حال اجرا
                        </span>
                    @elseif($error)
                        <span class="inline-flex px-3 py-1 text-sm font-medium bg-red-100 text-red-800 rounded-full">
                            ❌ خطا
                        </span>
                    @else
                        <span class="inline-flex px-3 py-1 text-sm font-medium bg-gray-100 text-gray-800 rounded-full">
                            آماده
                        </span>
                    @endif
                </div>

                <div class="text-center">
                    <div class="text-sm text-gray-500 mb-2">نوع منبع</div>
                    <span class="inline-flex px-3 py-1 text-sm font-semibold rounded-full bg-blue-100 text-blue-800">
                        {{ $config->data_source_type_text }}
                    </span>
                </div>
            </div>
        </div>

        {{-- تنظیمات کانفیگ --}}
        <div class="bg-white rounded-lg shadow mb-6 p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات کانفیگ</h2>

            @if($config->isApiSource())
                @php $apiSettings = $config->getApiSettings(); @endphp
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <div class="text-sm text-gray-500">آدرس پایه</div>
                        <div class="text-sm font-medium break-all">{{ $config->base_url }}</div>
                    </div>

                    <div>
                        <div class="text-sm text-gray-500">Endpoint</div>
                        <div class="text-sm font-medium">{{ $apiSettings['endpoint'] ?? '-' }}</div>
                    </div>

                    <div>
                        <div class="text-sm text-gray-500">متد HTTP</div>
                        <div class="text-sm font-medium">{{ $apiSettings['method'] ?? 'GET' }}</div>
                    </div>

                    <div>
                        <div class="text-sm text-gray-500">نوع احراز هویت</div>
                        <div class="text-sm font-medium">{{ $apiSettings['auth_type'] ?? 'none' }}</div>
                    </div>

                    <div class="md:col-span-2">
                        <div class="text-sm text-gray-500 mb-2">نقشه‌برداری فیلدها</div>
                        <div class="bg-gray-50 rounded p-3 text-xs">
                            @if(!empty($apiSettings['field_mapping']))
                                @foreach($apiSettings['field_mapping'] as $bookField => $apiField)
                                    <div>{{ $bookField }} ← {{ $apiField }}</div>
                                @endforeach
                            @else
                                <div class="text-gray-500">نقشه‌برداری تعریف نشده (استفاده از پیش‌فرض)</div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- نتایج Debug --}}
        <div id="debugResults" class="hidden">
            {{-- اطلاعات درخواست --}}
            <div id="requestInfo" class="bg-white rounded-lg shadow mb-6 p-6 hidden">
                <h2 class="text-lg font-medium text-gray-900 mb-4">🌐 اطلاعات درخواست</h2>
                <div id="requestDetails"></div>
            </div>

            {{-- تحلیل ساختار --}}
            <div id="structureAnalysis" class="bg-white rounded-lg shadow mb-6 p-6 hidden">
                <h2 class="text-lg font-medium text-gray-900 mb-4">🔍 تحلیل ساختار API</h2>
                <div id="structureDetails"></div>
            </div>

            {{-- نتایج استخراج --}}
            <div id="extractionResults" class="bg-white rounded-lg shadow mb-6 p-6 hidden">
                <h2 class="text-lg font-medium text-gray-900 mb-4">📋 نتایج استخراج داده</h2>
                <div id="extractionDetails"></div>
            </div>

            {{-- خطاها --}}
            <div id="errorSection" class="bg-white rounded-lg shadow mb-6 p-6 hidden">
                <h2 class="text-lg font-medium text-red-800 mb-4">❌ خطاها</h2>
                <div id="errorDetails"></div>
            </div>
        </div>

        {{-- آخرین خطای رخ داده --}}
        @if($error)
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-medium text-red-800 mb-4">آخرین خطای رخ داده</h2>

                <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                    <div class="text-sm text-red-700">
                        <strong>پیام:</strong> {{ $error['message'] }}
                    </div>
                    <div class="text-xs text-red-600 mt-2">
                        <strong>زمان:</strong> {{ $error['time'] }}
                    </div>
                    @if(isset($error['details']))
                        <div class="text-xs text-red-600 mt-1">
                            <strong>محل:</strong> {{ $error['details']['file'] ?? '' }}:{{ $error['details']['line'] ?? '' }}
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- CSRF Token در meta --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
@endsection

@push('scripts')
    <script>
        function runDebugApi() {
            const button = document.getElementById('debugApiBtn');
            const buttonText = button.querySelector('.button-text');
            const loadingText = button.querySelector('.loading-text');

            // نمایش loading
            button.disabled = true;
            buttonText.classList.add('hidden');
            loadingText.classList.remove('hidden');

            // مخفی کردن نتایج قبلی
            document.getElementById('debugResults').classList.add('hidden');
            ['requestInfo', 'structureAnalysis', 'extractionResults', 'errorSection'].forEach(id => {
                document.getElementById(id).classList.add('hidden');
            });

            // دریافت CSRF token
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            fetch('{{ route("configs.debug-api", $config) }}', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayDebugResults(data.debug_data);
                    } else {
                        displayError(data.error, data.details);
                    }
                })
                .catch(error => {
                    console.error('Debug API error:', error);
                    displayError('خطا در ارتباط با سرور: ' + error.message);
                })
                .finally(() => {
                    // مخفی کردن loading
                    button.disabled = false;
                    buttonText.classList.remove('hidden');
                    loadingText.classList.add('hidden');
                });
        }

        function displayDebugResults(debugData) {
            document.getElementById('debugResults').classList.remove('hidden');

            // نمایش اطلاعات درخواست
            if (debugData.request) {
                document.getElementById('requestInfo').classList.remove('hidden');
                document.getElementById('requestDetails').innerHTML = formatRequestInfo(debugData.request);
            }

            // نمایش تحلیل ساختار
            if (debugData.data_analysis) {
                document.getElementById('structureAnalysis').classList.remove('hidden');
                document.getElementById('structureDetails').innerHTML = formatStructureAnalysis(debugData.data_analysis);
            }

            // نمایش نتایج استخراج
            if (debugData.extracted_books) {
                document.getElementById('extractionResults').classList.remove('hidden');
                document.getElementById('extractionDetails').innerHTML = formatExtractionResults(debugData.extracted_books);
            }

            // نمایش خطا در صورت وجود
            if (debugData.error) {
                document.getElementById('errorSection').classList.remove('hidden');
                document.getElementById('errorDetails').innerHTML = formatErrorInfo(debugData.error);
            }
        }

        function formatRequestInfo(request) {
            return `
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div><strong>URL:</strong> <code class="bg-gray-100 px-2 py-1 rounded text-xs break-all">${request.url}</code></div>
                    <div><strong>متد:</strong> <span class="bg-blue-100 px-2 py-1 rounded text-xs">${request.method}</span></div>
                    <div><strong>Timeout:</strong> ${request.timeout} ثانیه</div>
                    <div><strong>نوع احراز هویت:</strong> ${request.auth_type}</div>
                </div>
            `;
        }

        function formatStructureAnalysis(analysis) {
            let html = `
                <div class="space-y-4">
                    <div>
                        <strong>نوع ساختار:</strong>
                        <span class="bg-green-100 px-2 py-1 rounded text-sm">${analysis.structure_type}</span>
                    </div>

                    <div>
                        <strong>کلیدهای اصلی:</strong>
                        <div class="flex flex-wrap gap-2 mt-2">
            `;

            analysis.root_keys.forEach(key => {
                html += `<span class="bg-gray-100 px-2 py-1 rounded text-xs">${key}</span>`;
            });

            html += `
                        </div>
                    </div>

                    <div>
                        <strong>مسیرهای احتمالی کتاب‌ها:</strong>
                        <div class="flex flex-wrap gap-2 mt-2">
            `;

            analysis.potential_book_paths.forEach(path => {
                html += `<span class="bg-blue-100 px-2 py-1 rounded text-xs">${path}</span>`;
            });

            html += '</div></div>';

            if (analysis.book_count) {
                html += `<div><strong>تعداد کتاب‌های یافته:</strong> <span class="text-green-600 font-bold">${analysis.book_count}</span></div>`;
            }

            if (analysis.sample_item_keys) {
                html += `
                    <div>
                        <strong>فیلدهای نمونه کتاب:</strong>
                        <div class="bg-gray-50 p-3 rounded mt-2">
                `;

                analysis.sample_item_keys.forEach(key => {
                    html += `<span class="bg-white px-2 py-1 rounded text-xs mr-2 mb-1 inline-block">${key}</span>`;
                });

                html += '</div></div>';
            }

            html += '</div>';
            return html;
        }

        function formatExtractionResults(extractedBooks) {
            let html = `
                <div class="space-y-4">
                    <div class="text-sm">
                        <strong>تعداد کتاب‌های یافته:</strong>
                        <span class="text-green-600 font-bold">${extractedBooks.count}</span>
                    </div>
            `;

            if (extractedBooks.sample_extraction) {
                html += `
                    <div>
                        <h4 class="font-medium mb-3">نتایج استخراج فیلدها:</h4>
                        <div class="bg-gray-50 rounded p-4">
                `;

                const extraction = extractedBooks.sample_extraction;

                if (extraction.extracted_fields) {
                    html += '<div class="mb-4"><strong>فیلدهای استخراج شده:</strong></div>';

                    Object.entries(extraction.extracted_fields).forEach(([field, data]) => {
                        const status = data.found ? 'text-green-600' : 'text-red-600';
                        const icon = data.found ? '✅' : '❌';

                        html += `
                            <div class="border-b border-gray-200 py-2">
                                <div class="flex items-center justify-between">
                                    <span class="font-medium">${field}</span>
                                    <span class="${status}">${icon}</span>
                                </div>
                                <div class="text-xs text-gray-600">
                                    مسیر: ${data.path} | نوع: ${data.type}
                                </div>
                                ${data.found ? `<div class="text-sm mt-1">مقدار: ${formatValue(data.raw_value)}</div>` : ''}
                            </div>
                        `;
                    });
                }

                if (extraction.errors && Object.keys(extraction.errors).length > 0) {
                    html += '<div class="mt-4"><strong class="text-red-600">خطاها:</strong></div>';

                    Object.entries(extraction.errors).forEach(([field, error]) => {
                        html += `
                            <div class="bg-red-50 border border-red-200 rounded p-3 mt-2">
                                <div class="text-red-800"><strong>${field}:</strong> ${error.error}</div>
                                <div class="text-red-600 text-xs">مسیر: ${error.path}</div>
                            </div>
                        `;
                    });
                }

                if (extraction.available_keys) {
                    html += `
                        <div class="mt-4">
                            <strong>کلیدهای موجود در داده:</strong>
                            <div class="bg-white p-3 rounded mt-2 max-h-40 overflow-y-auto">
                    `;

                    extraction.available_keys.slice(0, 20).forEach(key => {
                        html += `<span class="bg-gray-100 px-2 py-1 rounded text-xs mr-2 mb-1 inline-block">${key}</span>`;
                    });

                    if (extraction.available_keys.length > 20) {
                        html += `<span class="text-gray-500 text-xs">... و ${extraction.available_keys.length - 20} مورد دیگر</span>`;
                    }

                    html += '</div></div>';
                }

                html += '</div></div>';
            }

            if (extractedBooks.first_book) {
                html += `
                    <div>
                        <button
                            onclick="toggleFirstBook()"
                            class="flex items-center text-sm font-medium text-gray-700 hover:text-gray-900"
                        >
                            <svg id="firstBookIcon" class="w-4 h-4 ml-2 transform transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                            مشاهده داده‌های خام اولین کتاب
                        </button>
                        <div id="firstBookData" class="hidden mt-3 bg-gray-900 text-green-400 rounded-lg p-4 text-sm font-mono overflow-auto max-h-96">
                            ${JSON.stringify(extractedBooks.first_book, null, 2)}
                        </div>
                    </div>
                `;
            }

            html += '</div>';
            return html;
        }

        function formatErrorInfo(error) {
            return `
                <div class="bg-red-50 border border-red-200 rounded p-4">
                    <div class="text-red-800">
                        <strong>وضعیت HTTP:</strong> ${error.status}
                    </div>
                    <div class="text-red-700 mt-2">
                        <strong>پیام خطا:</strong>
                        <pre class="mt-2 text-sm bg-red-100 p-3 rounded overflow-auto">${error.body}</pre>
                    </div>
                </div>
            `;
        }

        function displayError(message, details = null) {
            document.getElementById('debugResults').classList.remove('hidden');
            document.getElementById('errorSection').classList.remove('hidden');

            let html = `
                <div class="bg-red-50 border border-red-200 rounded p-4">
                    <div class="text-red-800"><strong>خطا:</strong> ${message}</div>
            `;

            if (details) {
                html += `
                    <div class="text-red-600 text-sm mt-2">
                        <strong>جزئیات:</strong> ${details.file || ''}:${details.line || ''}
                    </div>
                `;
            }

            html += '</div>';
            document.getElementById('errorDetails').innerHTML = html;
        }

        function clearDebugData() {
            document.getElementById('debugResults').classList.add('hidden');
            ['requestInfo', 'structureAnalysis', 'extractionResults', 'errorSection'].forEach(id => {
                document.getElementById(id).classList.add('hidden');
            });
        }

        function toggleFirstBook() {
            const data = document.getElementById('firstBookData');
            const icon = document.getElementById('firstBookIcon');

            if (data.classList.contains('hidden')) {
                data.classList.remove('hidden');
                icon.style.transform = 'rotate(180deg)';
            } else {
                data.classList.add('hidden');
                icon.style.transform = 'rotate(0deg)';
            }
        }

        function formatValue(value) {
            if (value === null || value === undefined) {
                return '<span class="text-gray-400">null</span>';
            }

            if (typeof value === 'string' && value.length > 100) {
                return value.substring(0, 100) + '...';
            }

            if (typeof value === 'object') {
                return '[Object/Array]';
            }

            return String(value);
        }

        // به‌روزرسانی خودکار اگر کانفیگ در حال اجرا باشد
        @if($isRunning)
        setInterval(function() {
            location.reload();
        }, 15000);
        @endif
    </script>
@endpush
