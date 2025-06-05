<?php

namespace App\Http\Controllers;

use App\Models\Config;
use App\Models\ScrapingFailure;
use App\Jobs\ProcessConfigJob;
use App\Services\ApiDataService;
use App\Services\CrawlerDataService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

/**
 * کنترلر بهبود شده مدیریت کانفیگ‌ها
 */
class ConfigController extends Controller
{
    // نمایش لیست کانفیگ‌ها - رفع شده
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

        // **مهم: بارگذاری مجدد همه کانفیگ‌ها برای آمار جدید**
        $configs->getCollection()->transform(function ($config) {
            return $config->fresh(); // بارگذاری مجدد از دیتابیس
        });

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

            $config = Config::create([
                ...$validated,
                'config_data' => $configData,
                'created_by' => Auth::id()
            ]);

            Log::info("✅ کانفیگ جدید ایجاد شد", [
                'config_id' => $config->id,
                'config_name' => $config->name,
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

    // شروع اسکرپر - بهبود شده
    public function start(Config $config): RedirectResponse
    {
        if (!$config->canStart()) {
            return redirect()->back()
                ->with('error', 'امکان شروع این کانفیگ وجود ندارد. (وضعیت: ' . $config->status . ')');
        }

        try {
            Log::info("🌐 شروع کانفیگ از وب اینترفیس", [
                'config_id' => $config->id,
                'config_name' => $config->name,
                'user_id' => Auth::id()
            ]);

            // پاک کردن خطاهای قبلی
            Cache::forget("config_error_{$config->id}");

            // شروع کانفیگ
            $config->start();

            // dispatch job جدید
            ProcessConfigJob::dispatch($config);

            Log::info("✅ Job اضافه شد به queue", [
                'config_id' => $config->id,
                'job_class' => ProcessConfigJob::class
            ]);

            return redirect()->back()
                ->with('success', "اسکرپر '{$config->name}' شروع شد و به صف اضافه شد.");

        } catch (\Exception $e) {
            Log::error('❌ خطا در شروع اسکرپر از وب', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

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

        try {
            $config->stop();

            Log::info("⏹️ کانفیگ متوقف شد", [
                'config_id' => $config->id,
                'config_name' => $config->name,
                'user_id' => Auth::id()
            ]);

            return redirect()->back()
                ->with('success', "اسکرپر '{$config->name}' متوقف شد.");

        } catch (\Exception $e) {
            Log::error('خطا در متوقف کردن اسکرپر', [
                'config_id' => $config->id,
                'error' => $e->getMessage()
            ]);

            return redirect()->back()
                ->with('error', 'خطا در متوقف کردن اسکرپر.');
        }
    }

    // ریست کردن (شروع از اول)
    public function reset(Config $config): RedirectResponse
    {
        if ($config->isRunning()) {
            return redirect()->back()
                ->with('error', 'ابتدا اسکرپر را متوقف کنید.');
        }

        try {
            $config->reset();

            // پاک کردن cache های مربوطه
            Cache::forget("config_stats_{$config->id}");
            Cache::forget("config_error_{$config->id}");

            Log::info("🔄 کانفیگ ریست شد", [
                'config_id' => $config->id,
                'config_name' => $config->name,
                'user_id' => Auth::id()
            ]);

            return redirect()->back()
                ->with('success', "پیشرفت کانفیگ '{$config->name}' ریست شد.");

        } catch (\Exception $e) {
            Log::error('خطا در ریست کردن کانفیگ', [
                'config_id' => $config->id,
                'error' => $e->getMessage()
            ]);

            return redirect()->back()
                ->with('error', 'خطا در ریست کردن کانفیگ.');
        }
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
        $errors = [];

        foreach ($activeConfigs as $config) {
            try {
                $config->start();
                ProcessConfigJob::dispatch($config);
                $started++;

                Log::info("🚀 کانفیگ شروع شد (start all)", [
                    'config_id' => $config->id,
                    'config_name' => $config->name
                ]);

            } catch (\Exception $e) {
                $errors[] = $config->name;
                Log::error("خطا در شروع کانفیگ {$config->name}: " . $e->getMessage());
            }
        }

        $message = "{$started} کانفیگ شروع شد.";
        if (!empty($errors)) {
            $message .= " خطا در: " . implode(', ', $errors);
        }

        return redirect()->back()
            ->with('success', $message);
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
            try {
                $config->stop();
                Log::info("⏹️ کانفیگ متوقف شد (stop all)", [
                    'config_id' => $config->id,
                    'config_name' => $config->name
                ]);
            } catch (\Exception $e) {
                Log::error("خطا در متوقف کردن کانفیگ {$config->name}: " . $e->getMessage());
            }
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
        $count = $config->failures()->where('is_resolved', false)->count();
        $config->failures()->where('is_resolved', false)->update(['is_resolved' => true]);

        return redirect()->back()
            ->with('success', "همه شکست‌ها ({$count}) به عنوان حل شده علامت‌گذاری شدند.");
    }

    // نمایش آمار
    public function stats(Config $config): View
    {
        $isRunning = $config->isRunning();
        $stats = Cache::get("config_stats_{$config->id}");
        $error = Cache::get("config_error_{$config->id}");

        return view('configs.stats', compact('config', 'isRunning', 'stats', 'error'));
    }

    // صفحه debug
    public function debug(Config $config): View
    {
        $isRunning = $config->isRunning();
        $error = Cache::get("config_error_{$config->id}");

        return view('configs.debug', compact('config', 'isRunning', 'error'));
    }

    // API debug - بهبود شده
    public function debugApi(Config $config): JsonResponse
    {
        try {
            if (!$config->isApiSource()) {
                return response()->json([
                    'success' => false,
                    'error' => 'این کانفیگ از نوع API نیست'
                ]);
            }

            $service = new ApiDataService($config);
            $debugData = $service->debugApiCall();

            return response()->json([
                'success' => true,
                'debug_data' => $debugData
            ]);

        } catch (\Exception $e) {
            Log::error("خطا در debug API", [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'details' => [
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine()
                ]
            ]);
        }
    }

    // صفحه تست
    public function testPage(): View
    {
        $configs = Config::where('status', 'active')->get();
        return view('configs.test', compact('configs'));
    }

    // تست URL - بهبود شده
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

            if ($config->isApiSource()) {
                $service = new ApiDataService($config);
                $result = $service->testUrl($testUrl);
            } else {
                $service = new CrawlerDataService($config);
                $result = $service->testUrl($testUrl);
            }

            Log::info("✅ تست URL موفق", [
                'config_id' => $config->id,
                'test_url' => $testUrl,
                'extracted_fields' => array_keys($result['extracted_data'] ?? [])
            ]);

            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error("❌ خطا در تست URL", [
                'config_id' => $request->config_id,
                'test_url' => $request->test_url,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'details' => [
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    // اجرای کانفیگ (Queue) - بهبود شده
    public function run(Config $config): RedirectResponse
    {
        if (!$config->isActive()) {
            return redirect()->back()
                ->with('error', 'کانفیگ غیرفعال است.');
        }

        // بررسی اینکه کانفیگ در حال اجرا نباشد
        if ($config->isRunning()) {
            return redirect()->back()
                ->with('warning', 'این کانفیگ در حال حاضر در حال اجرا است.');
        }

        try {
            Log::info("🚀 اجرای کانفیگ (Queue) از وب", [
                'config_id' => $config->id,
                'config_name' => $config->name,
                'user_id' => Auth::id()
            ]);

            // پاک کردن خطاهای قبلی
            Cache::forget("config_error_{$config->id}");

            ProcessConfigJob::dispatch($config);

            return redirect()->back()
                ->with('success', 'کانفیگ به صف اجرا اضافه شد.');

        } catch (\Exception $e) {
            Log::error('خطا در اجرای کانفیگ (Queue)', [
                'config_id' => $config->id,
                'error' => $e->getMessage()
            ]);

            return redirect()->back()
                ->with('error', 'خطا در اجرای کانفیگ.');
        }
    }

    // اجرای فوری (Sync) - بهبود شده
    public function runSync(Config $config): RedirectResponse
    {
        if (!$config->isActive()) {
            return redirect()->back()
                ->with('error', 'کانفیگ غیرفعال است.');
        }

        // بررسی اینکه کانفیگ در حال اجرا نباشد
        if ($config->isRunning()) {
            return redirect()->back()
                ->with('warning', 'این کانفیگ در حال حاضر در حال اجرا است.');
        }

        // تنظیم timeout بیشتر برای اجرای فوری
        set_time_limit(600); // 10 دقیقه

        try {
            Log::info("⚡ اجرای فوری کانفیگ از وب", [
                'config_id' => $config->id,
                'config_name' => $config->name,
                'user_id' => Auth::id()
            ]);

            // پاک کردن خطاهای قبلی
            Cache::forget("config_error_{$config->id}");

            // شروع کانفیگ
            $config->start();

            $startTime = microtime(true);

            if ($config->isApiSource()) {
                $service = new ApiDataService($config);
                $stats = $service->fetchData();
            } else {
                $service = new CrawlerDataService($config);
                $stats = $service->crawlData();
            }

            $executionTime = round(microtime(true) - $startTime, 2);
            $stats['execution_time'] = $executionTime;

            // متوقف کردن کانفیگ بعد از اجرای فوری
            $config->stop();

            // **مهم: به‌روزرسانی آمار در دیتابیس**
            $this->updateConfigStatsFromWeb($config, $stats);

            // ذخیره آمار در cache
            Cache::put("config_stats_{$config->id}", $stats, 3600);

            Log::info("📊 اجرای فوری تمام شد", [
                'config_id' => $config->id,
                'stats' => $stats,
                'execution_time' => $executionTime
            ]);

            $message = "اجرا تمام شد. ";
            $message .= "کل: {$stats['total']}, ";
            $message .= "موفق: {$stats['success']}, ";
            $message .= "خطا: {$stats['failed']}, ";
            $message .= "تکراری: {$stats['duplicate']}, ";
            $message .= "زمان: {$executionTime}s";

            return redirect()->back()
                ->with('success', $message);

        } catch (\Exception $e) {
            Log::error('💥 خطا در اجرای فوری', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            // متوقف کردن کانفیگ در صورت خطا
            try {
                $config->stop();
            } catch (\Exception $stopError) {
                Log::error('خطا در متوقف کردن کانفیگ بعد از خطا', [
                    'config_id' => $config->id,
                    'stop_error' => $stopError->getMessage()
                ]);
            }

            // ذخیره خطا در cache
            Cache::put("config_error_{$config->id}", [
                'message' => $e->getMessage(),
                'time' => now()->toDateTimeString(),
                'sync_run' => true
            ], 3600);

            return redirect()->back()
                ->with('error', 'خطا در اجرای کانفیگ: ' . $e->getMessage());
        }
    }

    /**
     * به‌روزرسانی آمار کانفیگ از وب‌اینترفیس
     */
    private function updateConfigStatsFromWeb(Config $config, array $stats): void
    {
        try {
            // بارگذاری مجدد کانفیگ از دیتابیس
            $config->refresh();

            $oldProcessed = $config->total_processed;
            $oldSuccess = $config->total_success;
            $oldFailed = $config->total_failed;

            $newProcessed = $oldProcessed + $stats['total'];
            $newSuccess = $oldSuccess + $stats['success'];
            $newFailed = $oldFailed + $stats['failed'];

            // به‌روزرسانی آمار در دیتابیس
            $config->update([
                'total_processed' => $newProcessed,
                'total_success' => $newSuccess,
                'total_failed' => $newFailed,
                'last_run_at' => now()
            ]);

            Log::info("💾 آمار کانفیگ به‌روزرسانی شد از وب", [
                'config_id' => $config->id,
                'old_processed' => $oldProcessed,
                'new_processed' => $newProcessed,
                'old_success' => $oldSuccess,
                'new_success' => $newSuccess,
                'old_failed' => $oldFailed,
                'new_failed' => $newFailed,
                'current_run_stats' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error("❌ خطا در به‌روزرسانی آمار از وب", [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    // پاک کردن آمار
    public function clearStats(Config $config): RedirectResponse
    {
        try {
            Cache::forget("config_stats_{$config->id}");
            Cache::forget("config_error_{$config->id}");

            Log::info("🗑️ آمار کانفیگ پاک شد", [
                'config_id' => $config->id,
                'user_id' => Auth::id()
            ]);

            return redirect()->back()
                ->with('success', 'آمار پاک شد.');

        } catch (\Exception $e) {
            Log::error('خطا در پاک کردن آمار', [
                'config_id' => $config->id,
                'error' => $e->getMessage()
            ]);

            return redirect()->back()
                ->with('error', 'خطا در پاک کردن آمار.');
        }
    }

    // ساخت داده‌های کانفیگ - بهبود شده
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

    /**
     * بررسی وضعیت کانفیگ‌ها برای آپدیت real-time
     */
    public function statusCheck(): JsonResponse
    {
        try {
            $runningConfigs = Config::where('is_running', true)->count();
            $activeConfigs = Config::where('status', 'active')->count();
            $totalProcessed = Config::sum('total_processed');
            $recentFailures = ScrapingFailure::where('created_at', '>=', now()->subMinutes(5))->count();

            return response()->json([
                'running_configs' => $runningConfigs,
                'active_configs' => $activeConfigs,
                'total_processed' => $totalProcessed,
                'recent_failures' => $recentFailures,
                'should_refresh' => $recentFailures > 0 || $runningConfigs > 0,
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در status check', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'error' => 'خطا در دریافت وضعیت'
            ], 500);
        }
    }
}
