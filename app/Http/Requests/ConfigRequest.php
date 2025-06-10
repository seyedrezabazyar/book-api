<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'base_url' => 'required|url|max:500',
            'timeout' => 'required|integer|min:5|max:300',
            'delay_seconds' => 'required|integer|min:1|max:3600',
            'records_per_run' => 'required|integer|min:1|max:100',
            'page_delay' => 'required|integer|min:1|max:60',
            'start_page' => 'nullable|integer|min:1',
            'max_pages' => 'required|integer|min:1|max:10000',
            'auto_resume' => 'boolean',
            'fill_missing_fields' => 'boolean',
            'update_descriptions' => 'boolean',
            'verify_ssl' => 'boolean',
            'follow_redirects' => 'boolean',
            'api_endpoint' => 'required|string|max:500',
            'api_method' => 'required|in:GET,POST',
        ];

        // Validation برای field mapping (اختیاری)
        $bookFields = [
            'title', 'description', 'author', 'publisher', 'category',
            'isbn', 'publication_year', 'pages_count', 'language',
            'format', 'file_size', 'image_url', 'sha1', 'sha256',
            'crc32', 'ed2k', 'btih', 'magnet'
        ];

        foreach ($bookFields as $field) {
            $rules["api_field_{$field}"] = 'nullable|string|max:200';
        }

        // Validation برای parameters (اختیاری)
        for ($i = 1; $i <= 5; $i++) {
            $rules["param_key_{$i}"] = 'nullable|string|max:50';
            $rules["param_value_{$i}"] = 'nullable|string|max:200';
        }

        // اگر در حال update هستیم، name باید unique باشد به جز رکورد فعلی
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $configId = $this->route('config')->id ?? null;
            $rules['name'] = "required|string|max:255|unique:configs,name,{$configId}";
        } else {
            $rules['name'] = 'required|string|max:255|unique:configs,name';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'نام کانفیگ الزامی است.',
            'name.unique' => 'این نام قبلاً استفاده شده است.',
            'base_url.required' => 'آدرس پایه API الزامی است.',
            'base_url.url' => 'آدرس پایه باید یک URL معتبر باشد.',
            'timeout.required' => 'زمان timeout الزامی است.',
            'timeout.min' => 'حداقل timeout 5 ثانیه است.',
            'timeout.max' => 'حداکثر timeout 300 ثانیه است.',
            'delay_seconds.required' => 'تاخیر درخواست الزامی است.',
            'delay_seconds.min' => 'حداقل تاخیر 1 ثانیه است.',
            'max_pages.required' => 'تعداد حداکثر صفحات الزامی است.',
            'max_pages.min' => 'حداقل 1 صفحه باید تعریف شود.',
            'max_pages.max' => 'حداکثر 10000 صفحه مجاز است.',
            'api_endpoint.required' => 'endpoint API الزامی است.',
            'api_method.in' => 'متد HTTP باید GET یا POST باشد.',
        ];
    }

    /**
     * داده‌های پردازش شده برای ذخیره
     */
    public function getProcessedData(): array
    {
        $data = $this->validated();

        // تبدیل checkbox ها به boolean
        $data['auto_resume'] = $this->boolean('auto_resume');
        $data['fill_missing_fields'] = $this->boolean('fill_missing_fields');
        $data['update_descriptions'] = $this->boolean('update_descriptions');
        $data['verify_ssl'] = $this->boolean('verify_ssl', true); // پیش‌فرض true
        $data['follow_redirects'] = $this->boolean('follow_redirects', true); // پیش‌فرض true

        return $data;
    }
}
