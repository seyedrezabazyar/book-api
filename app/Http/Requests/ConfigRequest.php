<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * درخواست اعتبارسنجی کانفیگ
 */
class ConfigRequest extends FormRequest
{
    /**
     * تعیین اینکه آیا کاربر مجاز به این درخواست است یا نه
     */
    public function authorize(): bool
    {
        return auth()->check(); // فقط کاربران احراز هویت شده
    }

    /**
     * قوانین اعتبارسنجی برای درخواست
     */
    public function rules(): array
    {
        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
                $this->isMethod('POST')
                    ? 'unique:configs,name'
                    : Rule::unique('configs', 'name')->ignore($this->route('config'))
            ],
            'description' => 'nullable|string|max:1000',
            'data_source_type' => 'required|in:api,crawler',
            'base_url' => 'required|url|max:500',
            'timeout' => 'required|integer|min:5|max:300',
            'max_retries' => 'required|integer|min:0|max:10',
            'delay_seconds' => 'required|integer|min:1|max:3600',
            'records_per_run' => 'required|integer|min:1|max:100',
            'status' => 'required|in:active,inactive,draft',

            // تنظیمات عمومی
            'verify_ssl' => 'nullable|boolean',
            'follow_redirects' => 'nullable|boolean',
        ];

        // قوانین مخصوص API
        if ($this->input('data_source_type') === 'api') {
            $rules = array_merge($rules, [
                'api_endpoint' => 'required|string|max:255',
                'api_method' => 'required|in:GET,POST',

                // نقشه‌برداری فیلدهای API
                'api_field_title' => 'nullable|string|max:100',
                'api_field_description' => 'nullable|string|max:100',
                'api_field_author' => 'nullable|string|max:100',
                'api_field_category' => 'nullable|string|max:100',
                'api_field_publisher' => 'nullable|string|max:100',
                'api_field_isbn' => 'nullable|string|max:100',
                'api_field_publication_year' => 'nullable|string|max:100',
                'api_field_pages_count' => 'nullable|string|max:100',
                'api_field_language' => 'nullable|string|max:100',
                'api_field_format' => 'nullable|string|max:100',
                'api_field_file_size' => 'nullable|string|max:100',
                'api_field_image_url' => 'nullable|string|max:100',
            ]);
        }

        // قوانین مخصوص Crawler
        if ($this->input('data_source_type') === 'crawler') {
            $rules = array_merge($rules, [
                'url_pattern' => 'nullable|string|max:500',

                // سلکتورهای CSS
                'crawler_selector_title' => 'nullable|string|max:200',
                'crawler_selector_description' => 'nullable|string|max:200',
                'crawler_selector_author' => 'nullable|string|max:200',
                'crawler_selector_category' => 'nullable|string|max:200',
                'crawler_selector_publisher' => 'nullable|string|max:200',
                'crawler_selector_isbn' => 'nullable|string|max:200',
                'crawler_selector_publication_year' => 'nullable|string|max:200',
                'crawler_selector_pages_count' => 'nullable|string|max:200',
                'crawler_selector_language' => 'nullable|string|max:200',
                'crawler_selector_format' => 'nullable|string|max:200',
                'crawler_selector_file_size' => 'nullable|string|max:200',
                'crawler_selector_image_url' => 'nullable|string|max:200',
            ]);
        }

        return $rules;
    }

    /**
     * پیام‌های خطای سفارشی
     */
    public function messages(): array
    {
        return [
            'name.required' => 'نام کانفیگ الزامی است.',
            'name.unique' => 'نام کانفیگ قبلاً استفاده شده است.',
            'name.max' => 'نام کانفیگ نباید بیش از 255 کاراکتر باشد.',

            'data_source_type.required' => 'نوع منبع داده الزامی است.',
            'data_source_type.in' => 'نوع منبع داده باید API یا Crawler باشد.',

            'base_url.required' => 'آدرس پایه الزامی است.',
            'base_url.url' => 'آدرس پایه باید یک URL معتبر باشد.',
            'base_url.max' => 'آدرس پایه نباید بیش از 500 کاراکتر باشد.',

            'timeout.required' => 'زمان انتظار الزامی است.',
            'timeout.integer' => 'زمان انتظار باید یک عدد صحیح باشد.',
            'timeout.min' => 'زمان انتظار باید حداقل 5 ثانیه باشد.',
            'timeout.max' => 'زمان انتظار نباید بیش از 300 ثانیه باشد.',

            'max_retries.required' => 'تعداد تلاش مجدد الزامی است.',
            'max_retries.integer' => 'تعداد تلاش مجدد باید یک عدد صحیح باشد.',
            'max_retries.min' => 'تعداد تلاش مجدد نباید کمتر از 0 باشد.',
            'max_retries.max' => 'تعداد تلاش مجدد نباید بیش از 10 باشد.',

            'delay_seconds.required' => 'تاخیر بین درخواست‌ها الزامی است.',
            'delay_seconds.integer' => 'تاخیر باید یک عدد صحیح باشد.',
            'delay_seconds.min' => 'تاخیر باید حداقل 1 ثانیه باشد.',
            'delay_seconds.max' => 'تاخیر نباید بیش از 3600 ثانیه (1 ساعت) باشد.',

            'records_per_run.required' => 'تعداد رکورد در هر اجرا الزامی است.',
            'records_per_run.integer' => 'تعداد رکورد باید یک عدد صحیح باشد.',
            'records_per_run.min' => 'تعداد رکورد باید حداقل 1 باشد.',
            'records_per_run.max' => 'تعداد رکورد نباید بیش از 100 باشد.',

            'status.required' => 'وضعیت کانفیگ الزامی است.',
            'status.in' => 'وضعیت باید فعال، غیرفعال یا پیش‌نویس باشد.',

            // پیام‌های API
            'api_endpoint.required' => 'نقطه پایانی API الزامی است.',
            'api_method.required' => 'متد HTTP الزامی است.',
            'api_method.in' => 'متد HTTP باید GET یا POST باشد.',

            // پیام‌های عمومی
            'description.max' => 'توضیحات نباید بیش از 1000 کاراکتر باشد.',
            'url_pattern.max' => 'الگوی URL نباید بیش از 500 کاراکتر باشد.',
        ];
    }

    /**
     * نام‌های سفارشی برای فیلدها
     */
    public function attributes(): array
    {
        return [
            'name' => 'نام کانفیگ',
            'description' => 'توضیحات',
            'data_source_type' => 'نوع منبع داده',
            'base_url' => 'آدرس پایه',
            'timeout' => 'زمان انتظار',
            'max_retries' => 'تعداد تلاش مجدد',
            'delay_seconds' => 'تاخیر (ثانیه)',
            'records_per_run' => 'رکورد در هر اجرا',
            'status' => 'وضعیت',
            'verify_ssl' => 'تأیید SSL',
            'follow_redirects' => 'پیگیری ریدایرکت',
            'api_endpoint' => 'نقطه پایانی API',
            'api_method' => 'متد HTTP',
            'url_pattern' => 'الگوی URL',
        ];
    }

    /**
     * آماده‌سازی داده‌ها برای اعتبارسنجی
     */
    protected function prepareForValidation(): void
    {
        // تمیز کردن URL
        if ($this->has('base_url')) {
            $this->merge([
                'base_url' => rtrim($this->input('base_url'), '/')
            ]);
        }

        // تنظیم مقادیر پیش‌فرض boolean
        $this->merge([
            'verify_ssl' => $this->boolean('verify_ssl', true),
            'follow_redirects' => $this->boolean('follow_redirects', true),
        ]);
    }

    /**
     * اعتبارسنجی اضافی بعد از validation اصلی
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // بررسی سازگاری تنظیمات
            $this->validateCompatibility($validator);

            // بررسی آدرس URL
            $this->validateUrl($validator);
        });
    }

    /**
     * بررسی سازگاری تنظیمات
     */
    private function validateCompatibility($validator): void
    {
        $dataSourceType = $this->input('data_source_type');

        // بررسی API
        if ($dataSourceType === 'api') {
            if (empty($this->input('api_endpoint'))) {
                $validator->errors()->add('api_endpoint', 'نقطه پایانی API برای نوع API الزامی است.');
            }
        }

        // بررسی تنظیمات عملکرد
        $delaySeconds = (int) $this->input('delay_seconds', 0);
        $recordsPerRun = (int) $this->input('records_per_run', 0);

        if ($delaySeconds < 1 && $recordsPerRun > 10) {
            $validator->errors()->add('delay_seconds', 'برای تعداد رکورد بالا، تاخیر بیشتری توصیه می‌شود.');
        }
    }

    /**
     * بررسی معتبر بودن URL
     */
    private function validateUrl($validator): void
    {
        $baseUrl = $this->input('base_url');

        if (!empty($baseUrl)) {
            // بررسی قابل دسترسی بودن دامنه
            $parsedUrl = parse_url($baseUrl);

            if (!isset($parsedUrl['host'])) {
                $validator->errors()->add('base_url', 'آدرس URL نامعتبر است.');
                return;
            }

            // بررسی پروتکل امن
            if (isset($parsedUrl['scheme']) && $parsedUrl['scheme'] !== 'https') {
                // هشدار برای HTTP
                session()->flash('warning', 'استفاده از HTTPS برای امنیت بیشتر توصیه می‌شود.');
            }
        }
    }
}
