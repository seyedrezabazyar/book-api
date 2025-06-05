@extends('layouts.app')

@section('title', 'تست کانفیگ‌ها')

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
                    <h1 class="text-2xl font-bold text-gray-800">تست کانفیگ‌ها</h1>
                    <p class="text-gray-600">تست عملکرد کانفیگ‌ها با URL مشخص</p>
                </div>
            </div>
        </div>

        {{-- فرم تست --}}
        <div class="bg-white rounded-lg shadow mb-6 p-6">
            <h2 class="text-lg font-medium text-gray-900 mb-4">تست URL</h2>

            <form id="testForm" class="space-y-6">
                @csrf

                {{-- انتخاب کانفیگ --}}
                <div>
                    <label for="config_id" class="block text-sm font-medium text-gray-700 mb-2">
                        انتخاب کانفیگ <span class="text-red-500">*</span>
                    </label>
                    <select
                        id="config_id"
                        name="config_id"
                        required
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    >
                        <option value="">انتخاب کنید</option>
                        @foreach($configs as $config)
                            <option value="{{ $config->id }}">
                                {{ $config->name }} ({{ $config->data_source_type_text }})
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- آدرس تست --}}
                <div>
                    <label for="test_url" class="block text-sm font-medium text-gray-700 mb-2">
                        آدرس تست <span class="text-red-500">*</span>
                    </label>
                    <input
                        type="url"
                        id="test_url"
                        name="test_url"
                        required
                        placeholder="https://example.com/api/books یا https://example.com/book/123"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    >
                    <p class="mt-1 text-sm text-gray-500">
                        برای API: آدرس endpoint کامل | برای Crawler: آدرس صفحه کتاب
                    </p>
                </div>

                {{-- دکمه تست --}}
                <div class="flex items-center gap-4">
                    <button
                        type="submit"
                        id="testButton"
                        class="px-6 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <span class="button-text">🧪 شروع تست</span>
                        <span class="loading-text hidden">
                            <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            در حال تست...
                        </span>
                    </button>

                    <button
                        type="button"
                        id="clearButton"
                        class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500"
                        onclick="clearResults()"
                    >
                        پاک کردن نتایج
                    </button>
                </div>
            </form>
        </div>

        {{-- نمایش نتایج --}}
        <div id="testResults" class="hidden">
            {{-- نتیجه موفق --}}
            <div id="successResult" class="bg-white rounded-lg shadow mb-6 p-6 hidden">
                <div class="flex items-center mb-4">
                    <svg class="w-6 h-6 text-green-600 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <h2 class="text-lg font-medium text-green-800">✅ تست موفق</h2>
                </div>

                {{-- اطلاعات کلی --}}
                <div id="testInfo" class="mb-6"></div>

                {{-- داده‌های استخراج شده --}}
                <div class="mb-6">
                    <h3 class="text-md font-medium text-gray-900 mb-3">📋 داده‌های استخراج شده</h3>
                    <div id="extractedData" class="bg-gray-50 rounded-lg p-4"></div>
                </div>

                {{-- داده‌های خام (قابل تا/باز) --}}
                <div class="mb-6">
                    <button
                        type="button"
                        onclick="toggleRawData()"
                        class="flex items-center text-sm font-medium text-gray-700 hover:text-gray-900"
                    >
                        <svg id="rawDataIcon" class="w-4 h-4 ml-2 transform transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                        مشاهده داده‌های خام
                    </button>
                    <div id="rawData" class="hidden mt-3 bg-gray-900 text-green-400 rounded-lg p-4 text-sm font-mono overflow-auto max-h-96"></div>
                </div>
            </div>

            {{-- نتیجه خطا --}}
            <div id="errorResult" class="bg-white rounded-lg shadow mb-6 p-6 hidden">
                <div class="flex items-center mb-4">
                    <svg class="w-6 h-6 text-red-600 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <h2 class="text-lg font-medium text-red-800">❌ تست ناموفق</h2>
                </div>

                <div id="errorMessage" class="bg-red-50 border border-red-200 rounded-lg p-4"></div>
            </div>
        </div>

        {{-- راهنما --}}
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6">
            <h3 class="text-md font-medium text-blue-900 mb-3">📘 راهنمای استفاده</h3>

            <div class="space-y-3 text-sm text-blue-800">
                <div>
                    <strong>برای تست API:</strong>
                    <ul class="list-disc list-inside mr-4 mt-1">
                        <li>URL کامل endpoint را وارد کنید (مثال: https://balyan.ir/api-test/)</li>
                        <li>سیستم به صورت خودکار پارامترها و headers تعریف شده را اضافه می‌کند</li>
                        <li>اولین کتاب یافت شده نمایش داده می‌شود</li>
                    </ul>
                </div>

                <div>
                    <strong>برای تست Crawler:</strong>
                    <ul class="list-disc list-inside mr-4 mt-1">
                        <li>URL صفحه کتاب را وارد کنید (مثال: https://example.com/book/123)</li>
                        <li>سیستم طبق سلکتورهای تعریف شده اطلاعات را استخراج می‌کند</li>
                        <li>اگر سلکتور تعریف نشده، تلاش خودکار برای پیدا کردن اطلاعات</li>
                    </ul>
                </div>

                <div class="mt-4 p-3 bg-blue-100 rounded">
                    <strong>💡 نکته:</strong> این تست فقط یک نمونه را نمایش می‌دهد و در دیتابیس ذخیره نمی‌شود.
                </div>
            </div>
        </div>
    </div>

    {{-- CSRF Token در meta --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @push('scripts')
        <script>
            document.getElementById('testForm').addEventListener('submit', function(e) {
                e.preventDefault();

                const configId = document.getElementById('config_id').value;
                const testUrl = document.getElementById('test_url').value;

                if (!configId || !testUrl) {
                    alert('لطفاً کانفیگ و آدرس تست را وارد کنید.');
                    return;
                }

                runTest(configId, testUrl);
            });

            function runTest(configId, testUrl) {
                // نمایش loading
                const button = document.getElementById('testButton');
                const buttonText = button.querySelector('.button-text');
                const loadingText = button.querySelector('.loading-text');

                button.disabled = true;
                buttonText.classList.add('hidden');
                loadingText.classList.remove('hidden');

                // مخفی کردن نتایج قبلی
                document.getElementById('testResults').classList.add('hidden');
                document.getElementById('successResult').classList.add('hidden');
                document.getElementById('errorResult').classList.add('hidden');

                // دریافت CSRF token از کوکی یا meta tag
                let csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

                // اگر از meta tag نگرفت، از کوکی بگیر
                if (!csrfToken) {
                    const xsrfToken = document.cookie
                        .split('; ')
                        .find(row => row.startsWith('XSRF-TOKEN='));

                    if (xsrfToken) {
                        csrfToken = decodeURIComponent(xsrfToken.split('=')[1]);
                    }
                }

                // ارسال درخواست
                fetch('{{ route("configs.test-url") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin', // مهم: برای ارسال کوکی‌ها
                    body: JSON.stringify({
                        config_id: configId,
                        test_url: testUrl
                    })
                })
                    .then(response => {
                        console.log('Response status:', response.status);
                        console.log('Response headers:', response.headers);

                        if (!response.ok) {
                            return response.text().then(text => {
                                console.log('Error response:', text);
                                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                            });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            showSuccessResult(data.data);
                        } else {
                            showErrorResult(data.error || 'خطای نامشخص');
                        }
                    })
                    .catch(error => {
                        console.error('Test error:', error);
                        showErrorResult('خطا در ارتباط با سرور: ' + error.message);
                    })
                    .finally(() => {
                        // مخفی کردن loading
                        button.disabled = false;
                        buttonText.classList.remove('hidden');
                        loadingText.classList.add('hidden');
                    });
            }

            function showSuccessResult(data) {
                document.getElementById('testResults').classList.remove('hidden');
                document.getElementById('successResult').classList.remove('hidden');

                // نمایش اطلاعات کلی
                const testInfo = document.getElementById('testInfo');
                testInfo.innerHTML = `
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        <div><strong>کانفیگ:</strong> ${data.config_name}</div>
                        <div><strong>نوع منبع:</strong> ${data.source_type}</div>
                        <div class="md:col-span-2"><strong>URL تست:</strong> <code class="bg-gray-100 px-2 py-1 rounded">${data.test_url}</code></div>
                        ${data.total_books_found ? `<div><strong>تعداد کتاب‌های یافته:</strong> ${data.total_books_found}</div>` : ''}
                        ${data.page_title ? `<div><strong>عنوان صفحه:</strong> ${data.page_title}</div>` : ''}
                        <div><strong>وضعیت پاسخ:</strong> <span class="text-green-600">${data.response_status || 200}</span></div>
                    </div>
                `;

                // نمایش داده‌های استخراج شده
                const extractedData = document.getElementById('extractedData');
                let extractedHtml = '';

                if (Object.keys(data.extracted_data).length > 0) {
                    for (const [field, value] of Object.entries(data.extracted_data)) {
                        const fieldName = getFieldDisplayName(field);
                        extractedHtml += `
                            <div class="border-b border-gray-200 py-2">
                                <div class="font-medium text-gray-700">${fieldName}</div>
                                <div class="text-gray-900">${formatValue(value)}</div>
                            </div>
                        `;
                    }
                } else {
                    extractedHtml = '<div class="text-gray-500 text-center py-4">هیچ داده‌ای استخراج نشد</div>';
                }

                extractedData.innerHTML = extractedHtml;

                // نمایش داده‌های خام
                const rawData = document.getElementById('rawData');
                rawData.textContent = JSON.stringify(data.raw_data, null, 2);
            }

            function showErrorResult(error) {
                document.getElementById('testResults').classList.remove('hidden');
                document.getElementById('errorResult').classList.remove('hidden');

                const errorMessage = document.getElementById('errorMessage');
                errorMessage.innerHTML = `
                    <div class="text-red-800">
                        <strong>خطا:</strong> ${error}
                    </div>
                `;
            }

            function clearResults() {
                document.getElementById('testResults').classList.add('hidden');
                document.getElementById('successResult').classList.add('hidden');
                document.getElementById('errorResult').classList.add('hidden');
                document.getElementById('testForm').reset();
            }

            function toggleRawData() {
                const rawData = document.getElementById('rawData');
                const icon = document.getElementById('rawDataIcon');

                if (rawData.classList.contains('hidden')) {
                    rawData.classList.remove('hidden');
                    icon.style.transform = 'rotate(180deg)';
                } else {
                    rawData.classList.add('hidden');
                    icon.style.transform = 'rotate(0deg)';
                }
            }

            function getFieldDisplayName(field) {
                const fieldNames = {
                    'title': 'عنوان',
                    'description': 'توضیحات',
                    'author': 'نویسنده',
                    'category': 'دسته‌بندی',
                    'publisher': 'ناشر',
                    'isbn': 'شابک',
                    'publication_year': 'سال انتشار',
                    'pages_count': 'تعداد صفحات',
                    'language': 'زبان',
                    'format': 'فرمت',
                    'file_size': 'حجم فایل',
                    'image_url': 'تصویر',
                    'download_url': 'لینک دانلود',
                    'price': 'قیمت',
                    'rating': 'امتیاز',
                    'tags': 'برچسب‌ها'
                };

                return fieldNames[field] || field;
            }

            function formatValue(value) {
                if (value === null || value === undefined) {
                    return '<span class="text-gray-400">-</span>';
                }

                if (typeof value === 'string' && (value.startsWith('http://') || value.startsWith('https://'))) {
                    if (value.match(/\.(jpg|jpeg|png|gif|webp)$/i)) {
                        return `<img src="${value}" alt="تصویر" class="max-w-32 max-h-32 rounded border">`;
                    } else {
                        return `<a href="${value}" target="_blank" class="text-blue-600 hover:underline">${value}</a>`;
                    }
                }

                if (typeof value === 'number') {
                    return value.toLocaleString('fa-IR');
                }

                return value;
            }
        </script>
    @endpush
@endsection>
</div>
</div>

@push('scripts')
    <script>
        document.getElementById('testForm').addEventListener('submit', function(e) {
            e.preventDefault();

            const configId = document.getElementById('config_id').value;
            const testUrl = document.getElementById('test_url').value;

            if (!configId || !testUrl) {
                alert('لطفاً کانفیگ و آدرس تست را وارد کنید.');
                return;
            }

            runTest(configId, testUrl);
        });

        function runTest(configId, testUrl) {
            // نمایش loading
            const button = document.getElementById('testButton');
            const buttonText = button.querySelector('.button-text');
            const loadingText = button.querySelector('.loading-text');

            button.disabled = true;
            buttonText.classList.add('hidden');
            loadingText.classList.remove('hidden');

            // مخفی کردن نتایج قبلی
            document.getElementById('testResults').classList.add('hidden');
            document.getElementById('successResult').classList.add('hidden');
            document.getElementById('errorResult').classList.add('hidden');

            // ارسال درخواست
            fetch('{{ route("configs.test-url") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                },
                body: JSON.stringify({
                    config_id: configId,
                    test_url: testUrl
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showSuccessResult(data.data);
                    } else {
                        showErrorResult(data.error);
                    }
                })
                .catch(error => {
                    showErrorResult('خطا در ارتباط با سرور: ' + error.message);
                })
                .finally(() => {
                    // مخفی کردن loading
                    button.disabled = false;
                    buttonText.classList.remove('hidden');
                    loadingText.classList.add('hidden');
                });
        }

        function showSuccessResult(data) {
            document.getElementById('testResults').classList.remove('hidden');
            document.getElementById('successResult').classList.remove('hidden');

            // نمایش اطلاعات کلی
            const testInfo = document.getElementById('testInfo');
            testInfo.innerHTML = `
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                        <div><strong>کانفیگ:</strong> ${data.config_name}</div>
                        <div><strong>نوع منبع:</strong> ${data.source_type}</div>
                        <div class="md:col-span-2"><strong>URL تست:</strong> <code class="bg-gray-100 px-2 py-1 rounded">${data.test_url}</code></div>
                        ${data.total_books_found ? `<div><strong>تعداد کتاب‌های یافته:</strong> ${data.total_books_found}</div>` : ''}
                        ${data.page_title ? `<div><strong>عنوان صفحه:</strong> ${data.page_title}</div>` : ''}
                        <div><strong>وضعیت پاسخ:</strong> <span class="text-green-600">${data.response_status || 200}</span></div>
                    </div>
                `;

            // نمایش داده‌های استخراج شده
            const extractedData = document.getElementById('extractedData');
            let extractedHtml = '';

            if (Object.keys(data.extracted_data).length > 0) {
                for (const [field, value] of Object.entries(data.extracted_data)) {
                    const fieldName = getFieldDisplayName(field);
                    extractedHtml += `
                            <div class="border-b border-gray-200 py-2">
                                <div class="font-medium text-gray-700">${fieldName}</div>
                                <div class="text-gray-900">${formatValue(value)}</div>
                            </div>
                        `;
                }
            } else {
                extractedHtml = '<div class="text-gray-500 text-center py-4">هیچ داده‌ای استخراج نشد</div>';
            }

            extractedData.innerHTML = extractedHtml;

            // نمایش داده‌های خام
            const rawData = document.getElementById('rawData');
            rawData.textContent = JSON.stringify(data.raw_data, null, 2);
        }

        function showErrorResult(error) {
            document.getElementById('testResults').classList.remove('hidden');
            document.getElementById('errorResult').classList.remove('hidden');

            const errorMessage = document.getElementById('errorMessage');
            errorMessage.innerHTML = `
                    <div class="text-red-800">
                        <strong>خطا:</strong> ${error}
                    </div>
                `;
        }

        function clearResults() {
            document.getElementById('testResults').classList.add('hidden');
            document.getElementById('successResult').classList.add('hidden');
            document.getElementById('errorResult').classList.add('hidden');
            document.getElementById('testForm').reset();
        }

        function toggleRawData() {
            const rawData = document.getElementById('rawData');
            const icon = document.getElementById('rawDataIcon');

            if (rawData.classList.contains('hidden')) {
                rawData.classList.remove('hidden');
                icon.style.transform = 'rotate(180deg)';
            } else {
                rawData.classList.add('hidden');
                icon.style.transform = 'rotate(0deg)';
            }
        }

        function getFieldDisplayName(field) {
            const fieldNames = {
                'title': 'عنوان',
                'description': 'توضیحات',
                'author': 'نویسنده',
                'category': 'دسته‌بندی',
                'publisher': 'ناشر',
                'isbn': 'شابک',
                'publication_year': 'سال انتشار',
                'pages_count': 'تعداد صفحات',
                'language': 'زبان',
                'format': 'فرمت',
                'file_size': 'حجم فایل',
                'image_url': 'تصویر',
                'download_url': 'لینک دانلود',
                'price': 'قیمت',
                'rating': 'امتیاز',
                'tags': 'برچسب‌ها'
            };

            return fieldNames[field] || field;
        }

        function formatValue(value) {
            if (value === null || value === undefined) {
                return '<span class="text-gray-400">-</span>';
            }

            if (typeof value === 'string' && (value.startsWith('http://') || value.startsWith('https://'))) {
                if (value.match(/\.(jpg|jpeg|png|gif|webp)$/i)) {
                    return `<img src="${value}" alt="تصویر" class="max-w-32 max-h-32 rounded border">`;
                } else {
                    return `<a href="${value}" target="_blank" class="text-blue-600 hover:underline">${value}</a>`;
                }
            }

            if (typeof value === 'number') {
                return value.toLocaleString('fa-IR');
            }

            return value;
        }

        // افزودن CSRF token به meta
        if (!document.querySelector('meta[name="csrf-token"]')) {
            const meta = document.createElement('meta');
            meta.name = 'csrf-token';
            meta.content = '{{ csrf_token() }}';
            document.head.appendChild(meta);
        }
    </script>
@endpush
@endsection
