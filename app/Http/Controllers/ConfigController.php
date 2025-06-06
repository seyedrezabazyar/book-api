<?php

namespace App\Http\Controllers;

use App\Models\Config;
use App\Models\ExecutionLog;
use App\Services\ApiDataService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
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

        $query = Config::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('base_url', 'like', "%{$search}%");
            });
        }

        $configs = $query->orderBy('created_at', 'desc')
            ->paginate(10)
            ->appends($request->query());

        return view('configs.index', compact('configs', 'search'));
    }

    // نمایش فرم ایجاد
    public function create(): View
    {
        $bookFields = Config::getBookFields();
        return view('configs.create', compact('bookFields'));
    }

    // ذخیره کانفیگ جدید
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:configs',
            'description' => 'nullable|string|max:1000',
            'base_url' => 'required|url',
            'timeout' => 'required|integer|min:1|max:300',
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

            Log::info("✅ کانفیگ جدید ایجاد شد", [
                'config_name' => $validated['name'],
                'user_id' => Auth::id()
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
        // دریافت آخرین لاگ‌های اجرا
        $recentLogs = ExecutionLog::where('config_id', $config->id)
            ->latest()
            ->limit(5)
            ->get();

        return view('configs.show', compact('config', 'recentLogs'));
    }

    // نمایش فرم ویرایش
    public function edit(Config $config): View
    {
        $bookFields = Config::getBookFields();
        return view('configs.edit', compact('config', 'bookFields'));
    }

    // به‌روزرسانی
    public function update(Request $request, Config $config): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:configs,name,' . $config->id,
            'description' => 'nullable|string|max:1000',
            'base_url' => 'required|url',
            'timeout' => 'required|integer|min:1|max:300',
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

    // اجرای فوری - قابلیت اصلی
    public function runSync(Config $config): RedirectResponse
    {
        if (!$config->isActive()) {
            return redirect()->back()
                ->with('error', 'کانفیگ غیرفعال است.');
        }

        // تنظیم timeout بیشتر برای اجرای فوری
        set_time_limit(600); // 10 دقیقه

        try {
            Log::info("⚡ شروع اجرای فوری", [
                'config_id' => $config->id,
                'config_name' => $config->name,
                'user_id' => Auth::id()
            ]);

            $service = new ApiDataService($config);
            $stats = $service->fetchData();

            $message = "✅ اجرا تمام شد! ";
            $message .= "کل: {$stats['total']}, ";
            $message .= "موفق: {$stats['success']}, ";
            $message .= "خطا: {$stats['failed']}, ";
            $message .= "تکراری: {$stats['duplicate']}, ";
            $message .= "زمان: {$stats['execution_time']}s";

            return redirect()->back()
                ->with('success', $message);

        } catch (\Exception $e) {
            Log::error('💥 خطا در اجرای فوری', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return redirect()->back()
                ->with('error', 'خطا در اجرای کانفیگ: ' . $e->getMessage());
        }
    }

    // نمایش لاگ‌های اجرا
    public function logs(Config $config): View
    {
        $logs = ExecutionLog::where('config_id', $config->id)
            ->latest()
            ->paginate(20);

        return view('configs.logs', compact('config', 'logs'));
    }

    // نمایش جزئیات یک لاگ
    public function logDetails(Config $config, ExecutionLog $log): View
    {
        if ($log->config_id !== $config->id) {
            abort(404);
        }

        return view('configs.log-details', compact('config', 'log'));
    }

    // تست URL
    public function testUrl(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'config_id' => 'required|exists:configs,id',
            'test_url' => 'required|url'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'داده‌های ورودی نامعتبر',
                'validation_errors' => $validator->errors()
            ], 422);
        }

        try {
            $config = Config::findOrFail($request->config_id);
            $testUrl = $request->test_url;

            Log::info("🧪 تست URL شروع شد", [
                'config_id' => $config->id,
                'test_url' => $testUrl,
                'user_id' => Auth::id()
            ]);

            $service = new ApiDataService($config);
            $result = $service->testUrl($testUrl);

            Log::info("✅ تست URL موفق", [
                'config_id' => $config->id,
                'test_url' => $testUrl
            ]);

            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error("❌ خطا در تست URL", [
                'config_id' => $request->config_id,
                'test_url' => $request->test_url,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // صفحه تست
    public function testPage(): View
    {
        $configs = Config::where('status', 'active')->get();
        return view('configs.test', compact('configs'));
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

        // تنظیمات API (فقط API پشتیبانی می‌کنیم)
        $configData['api'] = [
            'endpoint' => $request->input('api_endpoint'),
            'method' => $request->input('api_method', 'GET'),
            'auth_type' => $request->input('auth_type', 'none'),
            'auth_token' => $request->input('auth_token', ''),
            'headers' => [],
            'params' => [],
            'field_mapping' => $this->buildFieldMapping($request)
        ];

        return $configData;
    }

    // ساخت نقشه‌برداری فیلدها
    private function buildFieldMapping(Request $request): array
    {
        $mapping = [];
        $bookFields = array_keys(Config::getBookFields());

        foreach ($bookFields as $field) {
            $inputName = "api_field_{$field}";
            $value = $request->input($inputName);

            if (!empty($value)) {
                $mapping[$field] = $value;
            }
        }

        return $mapping;
    }
}
