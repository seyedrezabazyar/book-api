@extends('layouts.app')
@section('title', 'ویرایش کانفیگ')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center gap-4">
            <a href="{{ route('configs.show', $config) }}" class="text-gray-600 hover:text-gray-800">←</a>
            <div>
                <h1 class="text-2xl font-semibold">ویرایش کانفیگ</h1>
                @php
                    // اصلاح محاسبه آخرین ID - فراخوانی مجدد برای refresh
                    $config->refresh(); // refresh کردن مدل

                    // دریافت آخرین ID از BookSource
                    try {
                        $lastIdFromSources = \App\Models\BookSource::where('source_name', $config->source_name)
                            ->whereRaw('source_id REGEXP "^[0-9]+$"')
                            ->orderByRaw('CAST(source_id AS UNSIGNED) DESC')
                            ->value('source_id');
                        $lastIdFromSources = $lastIdFromSources ? (int)$lastIdFromSources : 0;
                    } catch (\Exception $e) {
                        $lastIdFromSources = 0;
                    }

                    // محاسبه nextSmartId
                    if ($config->start_page && $config->start_page > 0) {
                        $nextSmartId = $config->start_page;
                        $hasUserDefined = true;
                    } else {
                        $nextSmartId = $lastIdFromSources > 0 ? $lastIdFromSources + 1 : 1;
                        $hasUserDefined = false;
                    }

                    $formStartPage = $config->start_page;
                    $actualFormValue = $formStartPage ?: '';
                @endphp
                <p class="text-gray-600">{{ $config->name }} - آخرین ID در book_sources: {{ $lastIdFromSources > 0 ? number_format($lastIdFromSources) : 'هیچ' }}</p>
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

        <!-- وضعیت فعلی کانفیگ - بهبود یافته -->
        <div class="bg-gradient-to-r from-green-50 to-blue-50 border border-green-200 rounded-lg p-4">
            <h3 class="text-green-800 font-medium mb-3">📊 وضعیت فعلی کانفیگ:</h3>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm mb-3">
                <div>
                    <span class="text-gray-600">منبع:</span>
                    <span class="font-medium">{{ $config->source_name }}</span>
                </div>
                <div>
                    <span class="text-gray-600">نوع:</span>
                    @if ($config->source_type === 'api')
                        <span class="font-medium text-blue-600">🌐 API</span>
                    @else
                        <span class="font-medium text-orange-600">🕷️ Crawler</span>
                    @endif
                </div>
                <div>
                    <span class="text-gray-600">آخرین ID در کانفیگ:</span>
                    <span class="font-medium text-blue-600">{{ number_format($config->last_source_id ?? 0) }}</span>
                </div>
                <div>
                    <span class="text-gray-600">آخرین ID در book_sources:</span>
                    <span class="font-medium text-purple-600">{{ number_format($lastIdFromSources) }}</span>
                </div>
                <div>
                    <span class="text-gray-600">نرخ موفقیت:</span>
                    <span class="font-medium">
                        {{ $config->total_processed > 0 ? round(($config->total_success / $config->total_processed) * 100, 1) : 0 }}%
                    </span>
                </div>
            </div>

            <!-- نمایش وضعیت منطق -->
            <div class="border-t pt-3">
                @if($hasUserDefined)
                    <div class="bg-orange-100 border border-orange-300 rounded p-3">
                        <h4 class="text-orange-800 font-medium mb-1">⚙️ حالت دستی فعال</h4>
                        <div class="text-orange-700 text-sm">
                            <div>• start_page تنظیم شده: <strong>{{ number_format($formStartPage) }}</strong></div>
                            <div>• اجرای بعدی از ID <strong>{{ number_format($nextSmartId) }}</strong> شروع خواهد شد</div>
                            @if($formStartPage <= $lastIdFromSources)
                                <div class="text-red-600 font-medium mt-1">⚠️ این ID قبلاً پردازش شده! ID های تکراری پردازش خواهند شد</div>
                            @endif
                        </div>
                    </div>
                @else
                    <div class="bg-green-100 border border-green-300 rounded p-3">
                        <h4 class="text-green-800 font-medium mb-1">🧠 حالت هوشمند فعال</h4>
                        <div class="text-green-700 text-sm">
                            <div>• start_page: <strong>خالی</strong> (null)</div>
                            <div>• اجرای بعدی از ID <strong>{{ number_format($nextSmartId) }}</strong> شروع خواهد شد</div>
                            @if($lastIdFromSources > 0)
                                <div class="text-green-600">✅ ادامه هوشمند از آخرین ID ثبت شده</div>
                            @else
                                <div class="text-blue-600">🆕 شروع جدید از ID 1</div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            <!-- Debug info اضافی -->
            <div class="mt-3 text-xs text-gray-500 border-t pt-2">
                <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                    <div>🔍 Smart Start Page: {{ $nextSmartId }}</div>
                    <div>📝 start_page در DB: {{ $config->start_page ?? 'null' }}</div>
                    <div>📊 کل رکوردها: {{ \App\Models\BookSource::where('source_name', $config->source_name)->count() }}</div>
                </div>
            </div>
        </div>

        <div class="bg-white rounded shadow p-6">
            <form method="POST" action="{{ route('configs.update', $config) }}" class="space-y-6">
                @csrf @method('PUT')

                <!-- Source Type Selection -->
                <div class="border-b border-gray-200 pb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">نوع منبع داده</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="relative">
                            <input type="radio" id="source_type_api" name="source_type" value="api"
                                   {{ old('source_type', $config->source_type) === 'api' ? 'checked' : '' }}
                                   class="peer sr-only" onchange="toggleSourceType()">
                            <label for="source_type_api" class="flex items-center p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50 peer-checked:border-blue-500 peer-checked:bg-blue-50">
                                <div class="text-center w-full">
                                    <div class="text-3xl mb-2">🌐</div>
                                    <div class="font-medium text-gray-900">API</div>
                                    <div class="text-sm text-gray-600">دریافت داده از API</div>
                                    <div class="text-xs text-gray-500 mt-1">JSON، REST API</div>
                                </div>
                            </label>
                        </div>

                        <div class="relative">
                            <input type="radio" id="source_type_crawler" name="source_type" value="crawler"
                                   {{ old('source_type', $config->source_type) === 'crawler' ? 'checked' : '' }}
                                   class="peer sr-only" onchange="toggleSourceType()">
                            <label for="source_type_crawler" class="flex items-center p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:bg-gray-50 peer-checked:border-orange-500 peer-checked:bg-orange-50">
                                <div class="text-center w-full">
                                    <div class="text-3xl mb-2">🕷️</div>
                                    <div class="font-medium text-gray-900">Crawler</div>
                                    <div class="text-sm text-gray-600">استخراج از HTML</div>
                                    <div class="text-xs text-gray-500 mt-1">CSS Selectors، XPath</div>
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Basic Info -->
                <div class="border-b border-gray-200 pb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">اطلاعات کلی</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="md:col-span-2">
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
                                نام کانفیگ <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="name" name="name" value="{{ old('name', $config->name) }}"
                                   required maxlength="255"
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('name') border-red-500 @enderror">
                            @error('name')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="md:col-span-2">
                            <label for="base_url" class="block text-sm font-medium text-gray-700 mb-2">
                                آدرس پایه <span class="text-red-500">*</span>
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

                <!-- Smart Crawling Settings - بهبود یافته -->
                <div class="border-b border-gray-200 pb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">تنظیمات کرال هوشمند 🧠</h2>

                    <!-- راهنمای جدید -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                        <h3 class="text-blue-800 font-medium mb-2">🔧 منطق start_page (اصلاح شده):</h3>
                        <ul class="text-blue-700 text-sm space-y-1">
                            <li><strong>✅ اولویت اول:</strong> اگر start_page مقدار داشته باشد → دقیقاً از همان شروع می‌شود</li>
                            <li><strong>🧠 اولویت دوم:</strong> اگر start_page خالی باشد → ادامه هوشمند از آخرین ID</li>
                            <li><strong>🔄 شروع مجدد:</strong> برای شروع از اول، عدد 1 وارد کنید</li>
                            <li><strong>📈 ادامه هوشمند:</strong> برای ادامه از آخرین ID، فیلد را خالی کنید</li>
                        </ul>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div>
                            <label for="start_page" class="block text-sm font-medium text-gray-700 mb-2">
                                صفحه شروع (اختیاری) 🎯
                            </label>
                            <input type="number" id="start_page" name="start_page"
                                   value="{{ old('start_page', $actualFormValue) }}" min="1"
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-blue-500 @error('start_page') border-red-500 @enderror"
                                   placeholder="خالی = حالت هوشمند">

                            <!-- نمایش وضعیت فعلی -->
                            <div class="text-xs mt-1" id="start-page-status">
                                @if($hasUserDefined)
                                    <span class="text-orange-600">
                                        🎯 مشخص شده: {{ number_format($formStartPage) }} (حالت دستی)
                                    </span>
                                @else
                                    <span class="text-green-600">
                                        🧠 خالی = از ID {{ number_format($nextSmartId) }} ادامه (هوشمند)
                                    </span>
                                @endif
                            </div>

                            <!-- هشدار در صورت نیاز -->
                            @if($hasUserDefined && $formStartPage <= $lastIdFromSources)
                                <p class="text-xs text-red-600 mt-1">⚠️ این ID قبلاً پردازش شده!</p>
                            @endif

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

                <!-- Quick Actions - بهبود یافته -->
                <div class="bg-gradient-to-r from-blue-50 to-green-50 rounded-lg p-4">
                    <h3 class="text-blue-800 font-medium mb-3">⚡ عملیات سریع:</h3>
                    <div class="flex flex-wrap gap-3">
                        <button type="button" onclick="enableSmartMode()"
                                class="px-3 py-2 bg-green-600 text-white rounded text-sm hover:bg-green-700 transition-colors">
                            🧠 حالت هوشمند
                        </button>
                        <button type="button" onclick="setStartFromOne()"
                                class="px-3 py-2 bg-orange-600 text-white rounded text-sm hover:bg-orange-700 transition-colors">
                            🔄 شروع از ID 1
                        </button>
                        @if($lastIdFromSources > 0)
                            <button type="button" onclick="setStartFromNext()"
                                    class="px-3 py-2 bg-blue-600 text-white rounded text-sm hover:bg-blue-700 transition-colors">
                                ➡️ شروع از ID {{ $lastIdFromSources + 1 }}
                            </button>
                        @endif
                        <button type="button" onclick="showTestCommand()"
                                class="px-3 py-2 bg-purple-600 text-white rounded text-sm hover:bg-purple-700 transition-colors">
                            🔍 دستورات تست
                        </button>
                    </div>
                </div>

                <!-- نمایش دستورات CLI -->
                <div id="cli-commands" class="bg-gray-900 text-green-400 rounded-lg p-4 font-mono text-sm hidden">
                    <h3 class="text-white font-bold mb-2">🖥️ دستورات CLI مفید:</h3>
                    <div class="space-y-2">
                        <div>
                            <span class="text-gray-400"># تست وضعیت فعلی:</span><br>
                            <span class="text-green-300">php artisan config:debug {{ $config->id }}</span>
                        </div>
                        <div>
                            <span class="text-gray-400"># تنظیم start_page روی 1:</span><br>
                            <span class="text-green-300">php artisan config:set-start-page {{ $config->id }} 1</span>
                        </div>
                        <div>
                            <span class="text-gray-400"># فعال‌سازی حالت هوشمند:</span><br>
                            <span class="text-green-300">php artisan config:set-start-page {{ $config->id }} --clear</span>
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
                                   value="{{ old('timeout', $config->timeout) }}" required min="5" max="300"
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
                <div id="api-settings" class="border-b border-gray-200 pb-6" style="display: {{ $config->source_type === 'api' ? 'block' : 'none' }}">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">🌐 تنظیمات API</h2>
                    @php $apiSettings = $config->getApiSettings(); @endphp

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="md:col-span-2">
                            <label for="api_endpoint" class="block text-sm font-medium text-gray-700 mb-2">
                                Endpoint <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="api_endpoint" name="api_endpoint"
                                   value="{{ old('api_endpoint', $apiSettings['endpoint'] ?? '') }}"
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

                <!-- Crawler Settings -->
                <div id="crawler-settings" class="border-b border-gray-200 pb-6" style="display: {{ $config->source_type === 'crawler' ? 'block' : 'none' }}">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">🕷️ تنظیمات Crawler</h2>
                    @php $crawlerSettings = $config->getCrawlerSettings(); @endphp

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="md:col-span-2">
                            <label for="page_pattern" class="block text-sm font-medium text-gray-700 mb-2">
                                الگوی URL صفحه <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="page_pattern" name="page_pattern"
                                   value="{{ old('page_pattern', $config->page_pattern ?? '/book/{id}') }}"
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-orange-500 @error('page_pattern') border-red-500 @enderror"
                                   placeholder="/book/{id} یا /library/item/{id}.html">
                            <p class="text-xs text-gray-500 mt-1">از {id} برای جایگزینی شماره ID استفاده کنید</p>
                            @error('page_pattern')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="user_agent" class="block text-sm font-medium text-gray-700 mb-2">User Agent</label>
                            <input type="text" id="user_agent" name="user_agent"
                                   value="{{ old('user_agent', $config->user_agent ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36') }}"
                                   class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-orange-500"
                                   placeholder="User Agent Browser">
                            <p class="text-xs text-gray-500 mt-1">User Agent برای درخواست‌های HTTP</p>
                        </div>

                        <div>
                            <label for="headers" class="block text-sm font-medium text-gray-700 mb-2">Headers اضافی (JSON)</label>
                            <textarea id="headers" name="headers" rows="3"
                                      class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-orange-500"
                                      placeholder='{"Accept-Language": "fa,en", "Custom-Header": "value"}'>{{ old('headers', $config->headers) }}</textarea>
                            <p class="text-xs text-gray-500 mt-1">فرمت JSON برای headers اضافی</p>
                        </div>
                    </div>

                    <!-- CSS Selectors Mapping -->
                    <div class="mt-6">
                        <h3 class="text-md font-medium text-gray-900 mb-4">نقشه‌برداری CSS Selectors</h3>
                        <div class="bg-orange-50 border border-orange-200 rounded-lg p-4 mb-4">
                            <h4 class="text-orange-800 font-medium mb-2">🎯 راهنمای CSS Selectors:</h4>
                            <div class="text-orange-700 text-sm space-y-1">
                                <div>• <code>.title</code> - انتخاب کلاس title</div>
                                <div>• <code>#book-title</code> - انتخاب ID مشخص</div>
                                <div>• <code>h1.main-title</code> - عنصر h1 با کلاس main-title</div>
                                <div>• <code>.author a</code> - لینک داخل کلاس author</div>
                                <div>• <code>[data-isbn]</code> - عنصر با attribute مشخص</div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @foreach ($bookFields as $field => $label)
                                <div>
                                    <label for="selector_{{ $field }}"
                                           class="block text-sm font-medium text-gray-700 mb-1">
                                        {{ $label }}
                                    </label>
                                    <input type="text" id="selector_{{ $field }}"
                                           name="selector_{{ $field }}"
                                           value="{{ old('selector_' . $field, $crawlerSettings['selector_mapping'][$field] ?? '') }}"
                                           class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-orange-500 text-sm font-mono"
                                           placeholder="CSS Selector برای {{ $label }}">
                                </div>
                            @endforeach
                        </div>

                        <!-- Image Selectors -->
                        <div class="mt-6">
                            <h4 class="text-md font-medium text-gray-900 mb-3">سلکتورهای تصاویر</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="image_selector_1" class="block text-sm font-medium text-gray-700 mb-1">
                                        سلکتور تصویر اصلی
                                    </label>
                                    <input type="text" id="image_selector_1" name="image_selector_1"
                                           value="{{ old('image_selector_1', $crawlerSettings['image_selectors'][0] ?? '') }}"
                                           class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-orange-500 text-sm font-mono"
                                           placeholder=".book-cover img, .main-image">
                                </div>
                                <div>
                                    <label for="image_selector_2" class="block text-sm font-medium text-gray-700 mb-1">
                                        سلکتور تصویر جایگزین
                                    </label>
                                    <input type="text" id="image_selector_2" name="image_selector_2"
                                           value="{{ old('image_selector_2', $crawlerSettings['image_selectors'][1] ?? '') }}"
                                           class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-orange-500 text-sm font-mono"
                                           placeholder=".thumbnail img, .cover-image">
                                </div>
                            </div>
                        </div>

                        <!-- Download Link Selectors -->
                        <div class="mt-6">
                            <h4 class="text-md font-medium text-gray-900 mb-3">سلکتورهای لینک‌های دانلود</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="download_selector" class="block text-sm font-medium text-gray-700 mb-1">
                                        لینک دانلود مستقیم
                                    </label>
                                    <input type="text" id="download_selector" name="download_selector"
                                           value="{{ old('download_selector', $crawlerSettings['download_selectors']['direct'] ?? '') }}"
                                           class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-orange-500 text-sm font-mono"
                                           placeholder=".download-btn, a[href*='download']">
                                </div>
                                <div>
                                    <label for="magnet_selector" class="block text-sm font-medium text-gray-700 mb-1">
                                        لینک مگنت
                                    </label>
                                    <input type="text" id="magnet_selector" name="magnet_selector"
                                           value="{{ old('magnet_selector', $crawlerSettings['download_selectors']['magnet'] ?? '') }}"
                                           class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-2 focus:ring-orange-500 text-sm font-mono"
                                           placeholder="a[href^='magnet:'], .magnet-link">
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 p-3 bg-orange-50 rounded">
                            <p class="text-sm text-orange-800">
                                <strong>نکته:</strong> Crawler از CSS Selectors استفاده می‌کند. می‌توانید چندین سلکتور را با کاما جدا کنید.
                                <br>برای تست سلکتورها، از Developer Tools مرورگر استفاده کنید.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Preview Section -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3 class="text-md font-medium text-gray-900 mb-2">🔍 پیش‌نمایش تنظیمات:</h3>
                    <div class="text-sm text-gray-700 space-y-1" id="config-preview">
                        <div>📊 <strong>منبع:</strong> <span id="preview-source">{{ $config->source_name }}</span></div>
                        <div>🔧 <strong>نوع:</strong> <span id="preview-type">
                            @if ($config->source_type === 'api')
                                    🌐 API
                                @else
                                    🕷️ Crawler
                                @endif
                        </span></div>
                        <div>🔢 <strong>شروع از ID:</strong> <span id="preview-start">
                            @if($hasUserDefined)
                                    {{ number_format($formStartPage) }} (مشخص شده)
                                @else
                                    {{ number_format($nextSmartId) }} (ادامه هوشمند)
                                @endif
                        </span></div>
                        <div>📄 <strong>تعداد کل:</strong> <span id="preview-total">{{ number_format($config->max_pages) }}</span> ID</div>
                        <div>⏱️ <strong>تخمین زمان:</strong> <span id="preview-time">-</span></div>
                        <div id="preview-url-pattern" class="text-xs text-gray-500"></div>
                        @if($lastIdFromSources > 0)
                            <div class="text-xs text-gray-500">💡 آخرین ID پردازش شده: {{ number_format($lastIdFromSources) }}</div>
                        @endif
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-end space-x-4 space-x-reverse pt-6">
                    <a href="{{ route('configs.show', $config) }}"
                       class="px-4 py-2 border border-gray-300 rounded text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                        انصراف
                    </a>
                    <button type="submit"
                            class="px-6 py-2 border border-transparent rounded shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 transition-colors">
                        💾 ذخیره تغییرات
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // متغیرهای JavaScript
        const lastIdFromSources = {{ $lastIdFromSources }};
        const defaultNextId = {{ $nextSmartId }};

        // تابع helper برای format کردن اعداد
        function number_format(number) {
            return new Intl.NumberFormat('fa-IR').format(number);
        }

        // تابع تغییر نوع منبع
        function toggleSourceType() {
            const apiType = document.getElementById('source_type_api').checked;
            const crawlerType = document.getElementById('source_type_crawler').checked;

            const apiSettings = document.getElementById('api-settings');
            const crawlerSettings = document.getElementById('crawler-settings');

            if (apiType) {
                apiSettings.style.display = 'block';
                crawlerSettings.style.display = 'none';
                // اجباری کردن endpoint برای API
                document.getElementById('api_endpoint').required = true;
                document.getElementById('page_pattern').required = false;
            } else if (crawlerType) {
                apiSettings.style.display = 'none';
                crawlerSettings.style.display = 'block';
                // اجباری کردن page_pattern برای Crawler
                document.getElementById('api_endpoint').required = false;
                document.getElementById('page_pattern').required = true;
            }

            updatePreview();
        }

        // عملیات سریع
        function enableSmartMode() {
            const startPageInput = document.getElementById('start_page');
            startPageInput.value = '';
            updateStartPageStatus();
            updatePreview();
            showNotification('✅ حالت هوشمند فعال شد!', 'success');
        }

        function setStartFromOne() {
            const startPageInput = document.getElementById('start_page');
            startPageInput.value = '1';
            updateStartPageStatus();
            updatePreview();
            showNotification('🔄 شروع از ID 1 تنظیم شد!', 'warning');
        }

        @if($lastIdFromSources > 0)
        function setStartFromNext() {
            const startPageInput = document.getElementById('start_page');
            startPageInput.value = '{{ $lastIdFromSources + 1 }}';
            updateStartPageStatus();
            updatePreview();
            showNotification('➡️ شروع از ID {{ $lastIdFromSources + 1 }} تنظیم شد!', 'info');
        }
        @endif

        function showTestCommand() {
            const cliDiv = document.getElementById('cli-commands');
            cliDiv.classList.toggle('hidden');
        }

        // بروزرسانی وضعیت start_page
        function updateStartPageStatus() {
            const startPageInput = document.getElementById('start_page');
            const statusDiv = document.getElementById('start-page-status');
            const value = startPageInput.value.trim();

            if (value === '' || value === '0') {
                statusDiv.innerHTML = `<span class="text-green-600">🧠 خالی = از ID ${number_format(defaultNextId)} ادامه (هوشمند)</span>`;
            } else {
                const intValue = parseInt(value);
                if (intValue > 0) {
                    const warningText = intValue <= lastIdFromSources ? ' ⚠️ قبلاً پردازش شده!' : '';
                    statusDiv.innerHTML = `<span class="text-orange-600">🎯 مشخص شده: ${number_format(intValue)} (حالت دستی)${warningText}</span>`;
                } else {
                    statusDiv.innerHTML = `<span class="text-red-600">❌ مقدار نامعتبر</span>`;
                }
            }
        }

        // بروزرسانی پیش‌نمایش
        function updatePreview() {
            const baseUrl = document.getElementById('base_url').value;
            const startPageInput = document.getElementById('start_page').value.trim();
            const maxPages = document.getElementById('max_pages').value || {{ $config->max_pages }};
            const delaySeconds = document.getElementById('delay_seconds').value || {{ $config->delay_seconds }};
            const apiType = document.getElementById('source_type_api').checked;

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

            // نوع منبع
            document.getElementById('preview-type').textContent = apiType ? '🌐 API' : '🕷️ Crawler';

            // شروع
            let startText;
            let startFromId;

            if (startPageInput && startPageInput !== '' && parseInt(startPageInput) > 0) {
                startFromId = parseInt(startPageInput);
                startText = `${number_format(startFromId)} (مشخص شده)`;
            } else {
                startFromId = defaultNextId;
                startText = `${number_format(defaultNextId)} (ادامه هوشمند)`;
            }

            document.getElementById('preview-start').textContent = startText;

            // تعداد کل
            document.getElementById('preview-total').textContent = number_format(maxPages);

            // تخمین زمان
            const totalSeconds = maxPages * delaySeconds;
            const hours = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            let timeText = '';
            if (hours > 0) timeText += `${hours}ساعت `;
            if (minutes > 0) timeText += `${minutes}دقیقه`;
            if (!timeText) timeText = 'کمتر از یک دقیقه';

            document.getElementById('preview-time').textContent = timeText;

            // نمایش الگوی URL
            const urlPatternDiv = document.getElementById('preview-url-pattern');
            if (apiType) {
                const endpoint = document.getElementById('api_endpoint').value;
                if (baseUrl && endpoint) {
                    urlPatternDiv.textContent = `🌐 نمونه URL: ${baseUrl}${endpoint}`.replace('{id}', '123');
                }
            } else {
                const pattern = document.getElementById('page_pattern').value || '/book/{id}';
                if (baseUrl) {
                    urlPatternDiv.textContent = `🕷️ نمونه URL: ${baseUrl}${pattern}`.replace('{id}', '123');
                }
            }
        }

        // نمایش نوتیفیکیشن
        function showNotification(message, type = 'info') {
            const colors = {
                success: 'bg-green-100 border-green-400 text-green-700',
                warning: 'bg-yellow-100 border-yellow-400 text-yellow-700',
                error: 'bg-red-100 border-red-400 text-red-700',
                info: 'bg-blue-100 border-blue-400 text-blue-700'
            };

            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 p-4 border rounded shadow-lg z-50 ${colors[type]}`;
            notification.textContent = message;

            document.body.appendChild(notification);

            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        // Event listeners
        document.getElementById('start_page').addEventListener('input', function() {
            updateStartPageStatus();
            updatePreview();
        });

        document.getElementById('base_url').addEventListener('input', updatePreview);
        document.getElementById('max_pages').addEventListener('input', updatePreview);
        document.getElementById('delay_seconds').addEventListener('input', updatePreview);
        document.getElementById('api_endpoint').addEventListener('input', updatePreview);
        document.getElementById('page_pattern').addEventListener('input', updatePreview);

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            toggleSourceType();
            updateStartPageStatus();
            updatePreview();
        });
    </script>
@endsection
