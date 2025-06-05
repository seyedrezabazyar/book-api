<?php

namespace App\Http\Controllers;

use App\Models\Config;
use App\Models\ScrapingFailure;
use App\Jobs\ProcessScrapingJob;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ConfigController extends Controller
{
    // نمایش لیست کانفیگ‌ها
    public function index(Request $request): View
    {
        $search = $request->query('search');
        $sourceType = $request->query('source_type');

        $query = Config::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('base_url', 'like', "%{$search}%");
            });
        }

        if ($sourceType) {
            $query->where('data_source_type', $sourceType);
        }

        $configs = $query->orderBy('created_at', 'desc')
            ->paginate(10)
            ->appends($request->query());

        return view('configs.index', compact('configs', 'search', 'sourceType'));
    }

    // نمایش فرم ایجاد
    public function create(): View
    {
        $bookFields = Config::getBookFields();
        $dataSourceTypes = Config::getDataSourceTypes();

        return view('configs.create', compact('bookFields', 'dataSourceTypes'));
    }

    // ذخیره کانفیگ جدید
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:configs',
            'description' => 'nullable|string|max:1000',
            'data_source_type' => 'required|in:api,crawler',
            'base_url' => 'required|url',
            'timeout' => 'required|integer|min:1|max:300',
            'max_retries' => 'required|integer|min:0|max:10',
            'delay_seconds' => 'required|integer|min:1|max:3600',
            'records_per_run' => 'required|integer|min:1|max:100',
            'status' => 'required|in:active,inactive,draft',
        ]);

        try {
            $configData = $this->buildConfigData($request);

            Config::create([
                ...$validated,
                'config_data' => $configData,
                'created_by' => Auth::id()
            ]);

            return redirect()->route('configs.index')
                ->with('success', 'کانفیگ با موفقیت ایجاد شد!');

        } catch (\Exception $e) {
            Log::error('خطا در ایجاد کانفیگ: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'خطا در ایجاد کانفیگ.')
                ->withInput();
        }
    }

    // نمایش جزئیات
    public function show(Config $config): View
    {
        $stats = $config->getStats();
        $recentFailures = $config->failures()
            ->where('is_resolved', false)
            ->latest('last_attempt_at')
            ->limit(5)
            ->get();

        return view('configs.show', compact('config', 'stats', 'recentFailures'));
    }

    // نمایش فرم ویرایش
    public function edit(Config $config): View
    {
        $bookFields = Config::getBookFields();
        $dataSourceTypes = Config::getDataSourceTypes();

        return view('configs.edit', compact('config', 'bookFields', 'dataSourceTypes'));
    }

    // به‌روزرسانی
    public function update(Request $request, Config $config): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:configs,name,' . $config->id,
            'description' => 'nullable|string|max:1000',
            'base_url' => 'required|url',
            'timeout' => 'required|integer|min:1|max:300',
            'max_retries' => 'required|integer|min:0|max:10',
            'delay_seconds' => 'required|integer|min:1|max:3600',
            'records_per_run' => 'required|integer|min:1|max:100',
            'status' => 'required|in:active,inactive,draft',
        ]);

        try {
            $configData = $this->buildConfigData($request);

            $config->update([
                ...$validated,
                'config_data' => $configData
            ]);

            return redirect()->route('configs.index')
                ->with('success', 'کانفیگ با موفقیت به‌روزرسانی شد!');

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی کانفیگ: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'خطا در به‌روزرسانی کانفیگ.')
                ->withInput();
        }
    }

    // حذف
    public function destroy(Config $config): RedirectResponse
    {
        if ($config->isRunning()) {
            return redirect()->back()
                ->with('error', 'نمی‌توان کانفیگ در حال اجرا را حذف کرد.');
        }

        try {
            $config->delete();

            return redirect()->route('configs.index')
                ->with('success', 'کانفیگ با موفقیت حذف شد!');

        } catch (\Exception $e) {
            Log::error('خطا در حذف کانفیگ: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'خطا در حذف کانفیگ.');
        }
    }

    // شروع اسکرپر
    public function start(Config $config): RedirectResponse
    {
        if (!$config->canStart()) {
            return redirect()->back()
                ->with('error', 'امکان شروع این کانفیگ وجود ندارد.');
        }

        try {
            $config->start();
            ProcessScrapingJob::dispatch($config);

            return redirect()->back()
                ->with('success', "اسکرپر '{$config->name}' شروع شد.");

        } catch (\Exception $e) {
            Log::error('خطا در شروع اسکرپر: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'خطا در شروع اسکرپر: ' . $e->getMessage());
        }
    }

    // متوقف کردن اسکرپر
    public function stop(Config $config): RedirectResponse
    {
        if (!$config->canStop()) {
            return redirect()->back()
                ->with('error', 'این کانفیگ در حال اجرا نیست.');
        }

        $config->stop();

        return redirect()->back()
            ->with('success', "اسکرپر '{$config->name}' متوقف شد.");
    }

    // ریست کردن (شروع از اول)
    public function reset(Config $config): RedirectResponse
    {
        if ($config->isRunning()) {
            return redirect()->back()
                ->with('error', 'ابتدا اسکرپر را متوقف کنید.');
        }

        $config->reset();

        return redirect()->back()
            ->with('success', "پیشرفت کانفیگ '{$config->name}' ریست شد.");
    }

    // شروع همه کانفیگ‌های فعال
    public function startAll(): RedirectResponse
    {
        $activeConfigs = Config::where('status', 'active')
            ->where('is_running', false)
            ->get();

        if ($activeConfigs->isEmpty()) {
            return redirect()->back()
                ->with('warning', 'هیچ کانفیگ فعال آماده‌ای یافت نشد.');
        }

        $started = 0;
        foreach ($activeConfigs as $config) {
            try {
                $config->start();
                ProcessScrapingJob::dispatch($config);
                $started++;
            } catch (\Exception $e) {
                Log::error("خطا در شروع کانفیگ {$config->name}: " . $e->getMessage());
            }
        }

        return redirect()->back()
            ->with('success', "{$started} کانفیگ شروع شد.");
    }

    // متوقف کردن همه
    public function stopAll(): RedirectResponse
    {
        $runningConfigs = Config::where('is_running', true)->get();

        if ($runningConfigs->isEmpty()) {
            return redirect()->back()
                ->with('info', 'هیچ کانفیگی در حال اجرا نیست.');
        }

        foreach ($runningConfigs as $config) {
            $config->stop();
        }

        return redirect()->back()
            ->with('success', "همه اسکرپرها ({$runningConfigs->count()}) متوقف شدند.");
    }

    // نمایش شکست‌ها
    public function failures(Config $config): View
    {
        $failures = $config->failures()
            ->latest('last_attempt_at')
            ->paginate(20);

        return view('configs.failures', compact('config', 'failures'));
    }

    // حل کردن شکست
    public function resolveFailure(Config $config, ScrapingFailure $failure): RedirectResponse
    {
        $failure->markAsResolved();

        return redirect()->back()
            ->with('success', 'شکست به عنوان حل شده علامت‌گذاری شد.');
    }

    // حل کردن همه شکست‌ها
    public function resolveAllFailures(Config $config): RedirectResponse
    {
        $config->failures()->where('is_resolved', false)->update(['is_resolved' => true]);

        return redirect()->back()
            ->with('success', 'همه شکست‌ها به عنوان حل شده علامت‌گذاری شدند.');
    }

    // ساخت داده‌های کانفیگ
    private function buildConfigData(Request $request): array
    {
        $configData = [
            'general' => [
                'user_agent' => $request->input('user_agent', 'Mozilla/5.0 (compatible; ScraperBot/1.0)'),
                'verify_ssl' => $request->boolean('verify_ssl', true),
                'follow_redirects' => $request->boolean('follow_redirects', true),
            ]
        ];

        if ($request->input('data_source_type') === 'api') {
            $configData['api'] = [
                'endpoint' => $request->input('api_endpoint'),
                'method' => $request->input('api_method', 'GET'),
                'auth_type' => $request->input('auth_type', 'none'),
                'auth_token' => $request->input('auth_token', ''),
                'headers' => [],
                'params' => [],
                'field_mapping' => $this->buildFieldMapping($request, 'api')
            ];
        }

        if ($request->input('data_source_type') === 'crawler') {
            $configData['crawler'] = [
                'selectors' => $this->buildFieldMapping($request, 'crawler'),
                'url_pattern' => $request->input('url_pattern', ''),
            ];
        }

        return $configData;
    }

    // ساخت نقشه‌برداری فیلدها
    private function buildFieldMapping(Request $request, string $sourceType): array
    {
        $mapping = [];
        $bookFields = array_keys(Config::getBookFields());

        foreach ($bookFields as $field) {
            $inputName = $sourceType === 'api' ? "api_field_{$field}" : "crawler_selector_{$field}";
            $value = $request->input($inputName);

            if (!empty($value)) {
                $mapping[$field] = $value;
            }
        }

        return $mapping;
    }
}
