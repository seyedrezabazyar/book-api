@extends('layouts.app')
@section('title', 'ویرایش کانفیگ')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center gap-4">
            <a href="{{ route('configs.show', $config) }}" class="text-gray-600 hover:text-gray-800">←</a>
            <div>
                <h1 class="text-2xl font-semibold">ویرایش کانفیگ</h1>
                <p class="text-gray-600">{{ $config->name }} - آخرین ID پردازش شده: {{ $config->last_source_id }}</p>
            </div>
        </div>

        @if ($errors->any())
            <div class="bg-red-100 border border-red-400 text-red-700 p-4 rounded">
                <div class="font-medium mb-2">خطاهای زیر را بررسی کنید:</div>
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- وضعیت فعلی کانفیگ -->
        <div class="bg-gradient-to-r from-green-50 to-blue-50 border border-green-200 rounded-lg p-4">
            <h3 class="text-green-800 font-medium mb-2">📊 وضعیت فعلی کانفیگ:</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div>
                    <span class="text-gray-600">منبع:</span>
                    <span class="font-medium">{{ $config->source_name }}</span>
                </div>
                <div>
                    <span class="text-gray-600">آخرین ID:</span>
                    <span class="font-medium text-blue-600">{{ $config->last_source_id }}</span>
                </div>
                <div>
                    <span class="text-gray-600">کل موفقیت:</span>
                    <span class="font-medium text-green-600">{{ number_format($config->total_success) }}</span>
                </div>
                <div>
                    <span class="text-gray-600">نرخ موفقیت:</span>
                    <span class="font-medium">
                        {{ $config->total_processed > 0 ? round(($config->total_success / $config->total_processed) * 100, 1) : 0 }}%
                    </span>
                </div>
            </div>
        </div>

        <div class="bg-white rounded shadow p-6">
            <form method="POST" action="{{ route('configs.update', $config) }}" class="space-y-6">
                @csrf @method('PUT')

                <!-- Basic Info -->
                <div class="border-b border-gray-200 pb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">اطلاعات کلی</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="md:col-span-2">
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
                                نام کانفیگ <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="name" name="name" value="{{ old('name', $config->name) }}"
                                   required
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('name') border-red-500 @enderror">
                            @error('name')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="md:col-span-2">
                            <label for="base_url" class="block text-sm font-medium text-gray-700 mb-2">
                                آدرس پایه API <span class="text-red-500">*</span>
                            </label>
                            <input type="url" id="base_url" name="base_url"
                                   value="{{ old('base_url', $config->base_url) }}" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('base_url') border-red-500 @enderror">
                            <p class="text-xs text-gray-500 mt-1">منبع فعلی: <strong>{{ $config->source_name }}</strong></p>
                            @error('base_url')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>

                <!-- Smart Crawling Settings -->
                <div class="border-b border-gray-200 pb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات کرال هوشمند 🧠</h2>

                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                        <h3 class="text-yellow-800 font-medium mb-2">⚠️ نکات مهم:</h3>
                        <ul class="text-yellow-700 text-sm space-y-1">
                            <li>• آخرین ID پردازش شده: <strong>{{ $config->last_source_id }}</strong></li>
                            <li>• اگر "صفحه شروع" را خالی بگذارید، از ID {{ $config->last_source_id + 1 }} ادامه می‌یابد</li>
                            <li>• اگر عدد مشخصی وارد کنید، از همان ID شروع می‌شود</li>
                            <li>• توجه: تغییر این تنظیمات بر روی اجرای بعدی تأثیر می‌گذارد</li>
                        </ul>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div>
                            <label for="start_page" class="block text-sm font-medium text-gray-700 mb-2">
                                صفحه شروع (اختیاری)
                            </label>
                            <input type="number" id="start_page" name="start_page"
                                   value="{{ old('start_page', $config->start_page) }}" min="1"
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('start_page') border-red-500 @enderror">
                            <p class="text-xs text-gray-500 mt-1">
                                خالی = از ID {{ $config->last_source_id + 1 }} ادامه
                            </p>
                            @error('start_page')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="max_pages" class="block text-sm font-medium text-gray-700 mb-2">
                                تعداد حداکثر صفحات <span class="text-red-500">*</span>
                            </label>
                            <input type="number" id="max_pages" name="max_pages"
                                   value="{{ old('max_pages', $config->max_pages) }}"
                                   required min="1" max="10000"
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('max_pages') border-red-500 @enderror">
                            <p class="text-xs text-gray-500 mt-1">تعداد ID های منبع که باید پردازش شوند</p>
                            @error('max_pages')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="space-y-3">
                            <div class="flex items-center">
                                <input type="checkbox" id="auto_resume" name="auto_resume" value="1"
                                       {{ old('auto_resume', $config->auto_resume) ? 'checked' : '' }}
                                       class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                <label for="auto_resume" class="mr-2 block text-sm text-gray-900">
                                    ⚡ ادامه خودکار
                                </label>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="fill_missing_fields" name="fill_missing_fields" value="1"
                                       {{ old('fill_missing_fields', $config->fill_missing_fields) ? 'checked' : '' }}
                                       class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                <label for="fill_missing_fields" class="mr-2 block text-sm text-gray-900">
                                    🔧 تکمیل فیلدهای خالی
                                </label>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="update_descriptions" name="update_descriptions" value="1"
                                       {{ old('update_descriptions', $config->update_descriptions) ? 'checked' : '' }}
                                       class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                <label for="update_descriptions" class="mr-2 block text-sm text-gray-900">
                                    📝 بهبود توضیحات
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Execution Settings -->
                <div class="border-b border-gray-200 pb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات اجرا</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div>
                            <label for="timeout" class="block text-sm font-medium text-gray-700 mb-2">
                                Timeout (ثانیه) <span class="text-red-500">*</span>
                            </label>
                            <input type="number" id="timeout" name="timeout"
                                   value="{{ old('timeout', $config->timeout) }}" required min="1" max="300"
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('timeout') border-red-500 @enderror">
                            @error('timeout')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="delay_seconds" class="block text-sm font-medium text-gray-700 mb-2">
                                تاخیر درخواست (ثانیه) <span class="text-red-500">*</span>
                            </label>
                            <input type="number" id="delay_seconds" name="delay_seconds"
                                   value="{{ old('delay_seconds', $config->delay_seconds) }}" required min="1"
                                   max="3600"
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('delay_seconds') border-red-500 @enderror">
                            <p class="text-xs text-gray-500 mt-1">تاخیر بین هر درخواست</p>
                            @error('delay_seconds')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="page_delay" class="block text-sm font-medium text-gray-700 mb-2">
                                تاخیر صفحه (ثانیه) <span class="text-red-500">*</span>
                            </label>
                            <input type="number" id="page_delay" name="page_delay"
                                   value="{{ old('page_delay', $config->page_delay) }}" required min="1" max="60"
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('page_delay') border-red-500 @enderror">
                            <p class="text-xs text-gray-500 mt-1">تاخیر بین هر ID</p>
                            @error('page_delay')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="records_per_run" class="block text-sm font-medium text-gray-700 mb-2">
                                رکورد در هر اجرا <span class="text-red-500">*</span>
                            </label>
                            <input type="number" id="records_per_run" name="records_per_run"
                                   value="{{ old('records_per_run', $config->records_per_run) }}" required min="1"
                                   max="100"
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('records_per_run') border-red-500 @enderror">
                            @error('records_per_run')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        @php $generalSettings = $config->getGeneralSettings(); @endphp

                        <div class="md:col-span-2 lg:col-span-4 space-y-3">
                            <div class="flex items-center">
                                <input type="checkbox" id="verify_ssl" name="verify_ssl" value="1"
                                       {{ old('verify_ssl', $generalSettings['verify_ssl'] ?? true) ? 'checked' : '' }}
                                       class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                <label for="verify_ssl" class="mr-2 block text-sm text-gray-900">تأیید گواهی SSL</label>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="follow_redirects" name="follow_redirects" value="1"
                                       {{ old('follow_redirects', $generalSettings['follow_redirects'] ?? true) ? 'checked' : '' }}
                                       class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                <label for="follow_redirects" class="mr-2 block text-sm text-gray-900">پیگیری
                                    ریدایرکت‌ها</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- API Settings -->
                <div class="border-b border-gray-200 pb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات API</h2>
                    @php $apiSettings = $config->getApiSettings(); @endphp

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="md:col-span-2">
                            <label for="api_endpoint" class="block text-sm font-medium text-gray-700 mb-2">
                                Endpoint <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="api_endpoint" name="api_endpoint"
                                   value="{{ old('api_endpoint', $apiSettings['endpoint'] ?? '') }}" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('api_endpoint') border-red-500 @enderror"
                                   placeholder="/api/book/{id} یا /api/books?id={id}">
                            <p class="text-xs text-gray-500 mt-1">از {id} برای جایگزینی ID استفاده کنید</p>
                            @error('api_endpoint')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="api_method" class="block text-sm font-medium text-gray-700 mb-2">متد HTTP</label>
                            <select id="api_method" name="api_method"
                                    class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('api_method') border-red-500 @enderror">
                                <option value="GET"
                                    {{ old('api_method', $apiSettings['method'] ?? 'GET') === 'GET' ? 'selected' : '' }}>
                                    GET</option>
                                <option value="POST"
                                    {{ old('api_method', $apiSettings['method'] ?? 'GET') === 'POST' ? 'selected' : '' }}>
                                    POST</option>
                            </select>
                            @error('api_method')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <!-- Field Mapping -->
                    <div class="mt-6">
                        <h3 class="text-md font-medium text-gray-900 mb-4">نقشه‌برداری فیلدهای API</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @foreach ($bookFields as $field => $label)
                                <div>
                                    <label for="api_field_{{ $field }}"
                                           class="block text-sm font-medium text-gray-700 mb-1">
                                        {{ $label }}
                                    </label>
                                    <input type="text" id="api_field_{{ $field }}"
                                           name="api_field_{{ $field }}"
                                           value="{{ old('api_field_' . $field, $apiSettings['field_mapping'][$field] ?? '') }}"
                                           class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 text-sm"
                                           placeholder="نام فیلد در پاسخ API">
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-4 p-3 bg-blue-50 rounded">
                            <p class="text-sm text-blue-800">
                                <strong>راهنما:</strong> برای فیلدهای nested از نقطه استفاده کنید (مثال: data.book.title یا
                                authors.0.name)
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Preview Section -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="text-md font-medium text-gray-900 mb-2">🔍 پیش‌نمایش تنظیمات جدید:</h3>
                    <div class="text-sm text-gray-700 space-y-1" id="config-preview">
                        <div>📊 <strong>منبع:</strong> <span id="preview-source">{{ $config->source_name }}</span></div>
                        <div>🔢 <strong>شروع از ID:</strong> <span id="preview-start">{{ $config->last_source_id + 1 }} (ادامه)</span></div>
                        <div>📄 <strong>تعداد کل:</strong> <span id="preview-total">{{ $config->max_pages }}</span> ID</div>
                        <div>⏱️ <strong>تخمین زمان:</strong> <span id="preview-time">-</span></div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-end space-x-4 space-x-reverse pt-6">
                    <a href="{{ route('configs.show', $config) }}"
                       class="px-4 py-2 border border-gray-300 rounded text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                        انصراف
                    </a>
                    <button type="submit"
                            class="px-6 py-2 border border-transparent rounded shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                        💾 ذخیره تغییرات
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // به‌روزرسانی پیش‌نمایش
        function updatePreview() {
            const baseUrl = document.getElementById('base_url').value;
            const startPage = document.getElementById('start_page').value;
            const maxPages = document.getElementById('max_pages').value || {{ $config->max_pages }};
            const delaySeconds = document.getElementById('delay_seconds').value || {{ $config->delay_seconds }};
            const currentLastId = {{ $config->last_source_id }};

            // نام منبع
            if (baseUrl) {
                try {
                    const url = new URL(baseUrl);
                    const sourceName = url.hostname.replace('www.', '');
                    document.getElementById('preview-source').textContent = sourceName;
                } catch (e) {
                    document.getElementById('preview-source').textContent = '{{ $config->source_name }}';
                }
            }

            // شروع
            const nextStart = startPage || (currentLastId + 1);
            document.getElementById('preview-start').textContent = startPage ?
                startPage :
                `${nextStart} (ادامه)`;

            // تعداد کل
            document.getElementById('preview-total').textContent = maxPages;

            // تخمین زمان
            const totalSeconds = maxPages * delaySeconds;
            const hours = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            let timeText = '';
            if (hours > 0) timeText += `${hours}ساعت `;
            if (minutes > 0) timeText += `${minutes}دقیقه`;
            if (!timeText) timeText = 'کمتر از یک دقیقه';

            document.getElementById('preview-time').textContent = timeText;
        }

        // Event listeners
        document.getElementById('base_url').addEventListener('input', updatePreview);
        document.getElementById('start_page').addEventListener('input', updatePreview);
        document.getElementById('max_pages').addEventListener('input', updatePreview);
        document.getElementById('delay_seconds').addEventListener('input', updatePreview);

        // اولین بار
        updatePreview();
    </script>
@endsection
