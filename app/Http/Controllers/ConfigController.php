<?php

namespace App\Http\Controllers;

use App\Models\Config;
use App\Http\Requests\ConfigRequest;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

/**
 * کنترلر مدیریت کانفیگ‌ها
 */
class ConfigController extends Controller
{
    // حذف سازنده - middleware در روت‌ها اعمال می‌شود

    /**
     * نمایش لیست کانفیگ‌ها
     */
    public function index(Request $request): View
    {
        try {
            $search = $request->query('search');

            $configs = Config::search($search)
                ->orderBy('created_at', 'desc')
                ->paginate(10)
                ->appends($request->query());

            return view('configs.index', compact('configs', 'search'));

        } catch (\Exception $e) {
            Log::error('خطا در نمایش لیست کانفیگ‌ها: ' . $e->getMessage());

            return view('configs.index', [
                'configs' => Config::paginate(10),
                'search' => null
            ])->with('error', 'خطا در بارگذاری لیست کانفیگ‌ها');
        }
    }

    /**
     * نمایش فرم ایجاد کانفیگ جدید
     */
    public function create(): View
    {
        return view('configs.create');
    }

    /**
     * ذخیره کانفیگ جدید
     */
    public function store(Request $request): RedirectResponse
    {
        $validator = $this->getValidator($request);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $configData = $this->buildConfigData($request);

            Config::create([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'config_data' => $configData,
                'status' => $request->input('status', Config::STATUS_DRAFT),
                'created_by' => Auth::id()
            ]);

            return redirect()->route('configs.index')
                ->with('success', 'کانفیگ با موفقیت ایجاد شد!');

        } catch (\Exception $e) {
            Log::error('خطا در ایجاد کانفیگ: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'خطا در ایجاد کانفیگ. لطفاً دوباره تلاش کنید.')
                ->withInput();
        }
    }

    /**
     * نمایش جزئیات کانفیگ
     */
    public function show(Config $config): View
    {
        return view('configs.show', compact('config'));
    }

    /**
     * نمایش فرم ویرایش کانفیگ
     */
    public function edit(Config $config): View
    {
        return view('configs.edit', compact('config'));
    }

    /**
     * به‌روزرسانی کانفیگ
     */
    public function update(Request $request, Config $config): RedirectResponse
    {
        $validator = $this->getValidator($request, $config->id);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $configData = $this->buildConfigData($request);

            $config->update([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'config_data' => $configData,
                'status' => $request->input('status')
            ]);

            return redirect()->route('configs.index')
                ->with('success', 'کانفیگ با موفقیت به‌روزرسانی شد!');

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی کانفیگ: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'خطا در به‌روزرسانی کانفیگ. لطفاً دوباره تلاش کنید.')
                ->withInput();
        }
    }

    /**
     * حذف کانفیگ
     */
    public function destroy(Config $config): RedirectResponse
    {
        try {
            $config->delete();

            return redirect()->route('configs.index')
                ->with('success', 'کانفیگ با موفقیت حذف شد!');

        } catch (\Exception $e) {
            Log::error('خطا در حذف کانفیگ: ' . $e->getMessage());

            return redirect()->route('configs.index')
                ->with('error', 'خطا در حذف کانفیگ. لطفاً دوباره تلاش کنید.');
        }
    }

    /**
     * تغییر وضعیت کانفیگ (فعال/غیرفعال)
     */
    public function toggleStatus(Config $config): RedirectResponse
    {
        try {
            $newStatus = $config->status === Config::STATUS_ACTIVE
                ? Config::STATUS_INACTIVE
                : Config::STATUS_ACTIVE;

            $config->update(['status' => $newStatus]);

            $statusText = $newStatus === Config::STATUS_ACTIVE ? 'فعال' : 'غیرفعال';

            return redirect()->back()
                ->with('success', "وضعیت کانفیگ به '{$statusText}' تغییر کرد.");

        } catch (\Exception $e) {
            Log::error('خطا در تغییر وضعیت کانفیگ: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'خطا در تغییر وضعیت کانفیگ.');
        }
    }

    /**
     * اعتبارسنجی داده‌های ورودی
     */
    private function getValidator(Request $request, ?int $excludeId = null)
    {
        $rules = [
            'name' => 'required|string|max:255|unique:configs,name' . ($excludeId ? ",$excludeId" : ''),
            'description' => 'nullable|string|max:1000',
            'status' => 'required|in:active,inactive,draft',

            // قوانین فیلدهای کانفیگ
            'base_url' => 'required|url',
            'timeout' => 'required|integer|min:1|max:300',
            'max_retries' => 'required|integer|min:0|max:10',
            'delay' => 'required|integer|min:0|max:10000',
            'user_agent' => 'nullable|string|max:500'
        ];

        $messages = [
            'name.required' => 'نام کانفیگ الزامی است.',
            'name.unique' => 'نام کانفیگ قبلاً استفاده شده است.',
            'name.max' => 'نام کانفیگ نباید بیشتر از 255 کاراکتر باشد.',

            'description.max' => 'توضیحات نباید بیشتر از 1000 کاراکتر باشد.',

            'status.required' => 'انتخاب وضعیت الزامی است.',
            'status.in' => 'وضعیت انتخاب شده معتبر نیست.',

            'base_url.required' => 'آدرس پایه الزامی است.',
            'base_url.url' => 'آدرس پایه معتبر نیست.',

            'timeout.required' => 'مقدار timeout الزامی است.',
            'timeout.integer' => 'مقدار timeout باید عدد باشد.',
            'timeout.min' => 'مقدار timeout باید حداقل 1 ثانیه باشد.',
            'timeout.max' => 'مقدار timeout نباید بیشتر از 300 ثانیه باشد.',

            'max_retries.required' => 'تعداد تلاش مجدد الزامی است.',
            'max_retries.integer' => 'تعداد تلاش مجدد باید عدد باشد.',
            'max_retries.min' => 'تعداد تلاش مجدد باید حداقل 0 باشد.',
            'max_retries.max' => 'تعداد تلاش مجدد نباید بیشتر از 10 باشد.',

            'delay.required' => 'مقدار تاخیر الزامی است.',
            'delay.integer' => 'مقدار تاخیر باید عدد باشد.',
            'delay.min' => 'مقدار تاخیر باید حداقل 0 میلی‌ثانیه باشد.',
            'delay.max' => 'مقدار تاخیر نباید بیشتر از 10000 میلی‌ثانیه باشد.',

            'user_agent.max' => 'User Agent نباید بیشتر از 500 کاراکتر باشد.'
        ];

        return Validator::make($request->all(), $rules, $messages);
    }

    /**
     * ساخت داده‌های کانفیگ از درخواست
     */
    private function buildConfigData(Request $request): array
    {
        return [
            'base_url' => $request->input('base_url'),
            'timeout' => (int) $request->input('timeout'),
            'max_retries' => (int) $request->input('max_retries'),
            'delay' => (int) $request->input('delay'),
            'settings' => [
                'verify_ssl' => $request->boolean('verify_ssl'),
                'follow_redirects' => $request->boolean('follow_redirects'),
                'user_agent' => $request->input('user_agent', 'Mozilla/5.0 (compatible; SimpleBot/1.0)')
            ]
        ];
    }
}
