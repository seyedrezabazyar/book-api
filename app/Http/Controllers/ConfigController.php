<?php

namespace App\Http\Controllers;

use App\Models\Config;
use App\Models\ExecutionLog;
use App\Services\ApiDataService;
use App\Services\QueueManagerService;
use App\Jobs\ProcessApiDataJob;
use App\Jobs\ProcessSinglePageJob;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File; // این خط اضافه شد
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Schema;

class ConfigController extends Controller
{
    /**
     * نمایش لیست کانفیگ‌ها با قابلیت جستجو و فیلتر
     */
    public function index(Request $request)
    {
        // دریافت کانفیگ‌ها با eager loading
        $configs = Config::with(['executionLogs' => function($query) {
            $query->latest()->limit(1);
        }])
            ->when($request->search, function($query, $search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('api_url', 'like', "%{$search}%");
            })
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        // محاسبه آمار کلی
        $stats = [
            'total_configs' => Config::count(),
            'active_configs' => Config::where('status', 'active')->count(),
            'running_configs' => Config::where('is_running', true)->count(),
            'total_books' => \App\Models\Book::count(),
        ];

        // بررسی وضعیت Worker
        $workerStatus = $this->getWorkerStatus();

        return view('configs.index', compact('configs', 'stats', 'workerStatus'));
    }

    /**
     * نمایش فرم ایجاد کانفیگ جدید
     */
    public function create(): View
    {
        $bookFields = Config::getBookFields();
        return view('configs.create', compact('bookFields'));
    }

    /**
     * ذخیره کانفیگ جدید
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateConfigData($request);

        try {
            $configData = $this->buildConfigData($request);

            Config::create([
                ...$validated,
                'config_data' => $configData,
                'created_by' => Auth::id(),
                'current_page' => $request->input('start_page', 1),
                'total_processed' => 0,
                'total_success' => 0,
                'total_failed' => 0,
                'is_running' => false
            ]);

            Log::info('کانفیگ جدید ایجاد شد', [
                'name' => $validated['name'],
                'user_id' => Auth::id()
            ]);

            return redirect()->route('configs.index')
                ->with('success', 'کانفیگ با موفقیت ایجاد شد!');

        } catch (\Exception $e) {
            Log::error('خطا در ایجاد کانفیگ', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return redirect()->back()
                ->with('error', 'خطا در ایجاد کانفیگ: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * نمایش جزئیات کانفیگ
     */
    public function show(Config $config): View
    {
        // آخرین 5 لاگ اجرا
        $recentLogs = ExecutionLog::where('config_id', $config->id)
            ->latest()
            ->limit(5)
            ->get();

        // آمار کلی
        $stats = [
            'total_executions' => ExecutionLog::where('config_id', $config->id)->count(),
            'successful_executions' => ExecutionLog::where('config_id', $config->id)
                ->where('status', 'completed')->count(),
            'failed_executions' => ExecutionLog::where('config_id', $config->id)
                ->where('status', 'failed')->count(),
            'total_books_processed' => $config->total_processed,
            'success_rate' => $config->total_processed > 0
                ? round(($config->total_success / $config->total_processed) * 100, 2)
                : 0
        ];

        return view('configs.show', compact('config', 'recentLogs', 'stats'));
    }

    /**
     * نمایش فرم ویرایش کانفیگ
     */
    public function edit(Config $config): View
    {
        $bookFields = Config::getBookFields();
        return view('configs.edit', compact('config', 'bookFields'));
    }

    /**
     * بروزرسانی کانفیگ
     */
    public function update(Request $request, Config $config): RedirectResponse
    {
        $validated = $this->validateConfigData($request, $config->id);

        try {
            // بررسی اینکه کانفیگ در حال اجرا نباشد
            if ($config->is_running) {
                return redirect()->back()
                    ->with('error', 'امکان ویرایش کانفیگ در حال اجرا وجود ندارد. ابتدا آن را متوقف کنید.');
            }

            $configData = $this->buildConfigData($request);

            $config->update([
                ...$validated,
                'config_data' => $configData
            ]);

            Log::info('کانفیگ بروزرسانی شد', [
                'config_id' => $config->id,
                'name' => $validated['name'],
                'user_id' => Auth::id()
            ]);

            return redirect()->route('configs.index')
                ->with('success', 'کانفیگ با موفقیت به‌روزرسانی شد!');

        } catch (\Exception $e) {
            Log::error('خطا در بروزرسانی کانفیگ', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return redirect()->back()
                ->with('error', 'خطا در بروزرسانی کانفیگ: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * حذف کانفیگ
     */
    public function destroy(Config $config): RedirectResponse
    {
        try {
            // بررسی اینکه کانفیگ در حال اجرا نباشد
            if ($config->is_running) {
                return redirect()->back()
                    ->with('error', 'امکان حذف کانفیگ در حال اجرا وجود ندارد. ابتدا آن را متوقف کنید.');
            }

            $configName = $config->name;

            // حذف لاگ‌های مربوطه
            ExecutionLog::where('config_id', $config->id)->delete();

            // حذف کانفیگ
            $config->delete();

            Log::info('کانفیگ حذف شد', [
                'config_name' => $configName,
                'user_id' => Auth::id()
            ]);

            return redirect()->route('configs.index')
                ->with('success', 'کانفیگ با موفقیت حذف شد!');

        } catch (\Exception $e) {
            Log::error('خطا در حذف کانفیگ', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return redirect()->back()
                ->with('error', 'خطا در حذف کانفیگ: ' . $e->getMessage());
        }
    }

    /**
     * اجرای بک‌گراند کانفیگ (روش بهینه) - نام قدیمی حفظ شده
     */
    public function runAsync(Config $config): RedirectResponse
    {
        if (!$config->isActive()) {
            return redirect()->back()
                ->with('error', 'کانفیگ غیرفعال است و قابل اجرا نیست.');
        }

        if ($config->is_running) {
            return redirect()->back()
                ->with('warning', 'کانفیگ در حال اجرا است. لطفاً صبر کنید یا آن را متوقف کنید.');
        }

        try {
            Log::info("شروع اجرای Async", [
                'config_id' => $config->id,
                'config_name' => $config->name,
                'user_id' => Auth::id()
            ]);

            $service = new ApiDataService($config);
            $maxPages = $this->calculateMaxPages($config);
            $result = $service->fetchDataAsync($maxPages);

            return redirect()->back()->with('success',
                "✅ اجرا در پس‌زمینه شروع شد!
                📄 تعداد {$result['pages_queued']} صفحه در صف قرار گرفت.
                🆔 شناسه اجرا: {$result['execution_id']}"
            );

        } catch (\Exception $e) {
            Log::error('خطا در اجرای Async', [
                'config_id' => $config->id,
                'config_name' => $config->name,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return redirect()->back()
                ->with('error', 'خطا در شروع اجرای بک‌گراند: ' . $e->getMessage());
        }
    }

    /**
     * اجرای فوری کانفیگ (روش قبلی)
     */
    public function runSync(Config $config): RedirectResponse
    {
        if (!$config->isActive()) {
            return redirect()->back()
                ->with('error', 'کانفیگ غیرفعال است و قابل اجرا نیست.');
        }

        if ($config->is_running) {
            return redirect()->back()
                ->with('warning', 'کانفیگ در حال اجرا است. لطفاً صبر کنید یا آن را متوقف کنید.');
        }

        // تنظیم timeout برای اجرای فوری
        set_time_limit(600); // 10 دقیقه
        ini_set('memory_limit', '512M');

        try {
            Log::info("شروع اجرای فوری", [
                'config_id' => $config->id,
                'config_name' => $config->name,
                'user_id' => Auth::id()
            ]);

            $service = new ApiDataService($config);
            $stats = $service->fetchData();

            $message = $this->formatExecutionResults($stats);

            Log::info("اجرای فوری تمام شد", [
                'config_id' => $config->id,
                'stats' => $stats,
                'user_id' => Auth::id()
            ]);

            return redirect()->back()->with('success', $message);

        } catch (\Exception $e) {
            Log::error('خطا در اجرای فوری', [
                'config_id' => $config->id,
                'config_name' => $config->name,
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return redirect()->back()
                ->with('error', 'خطا در اجرای فوری: ' . $e->getMessage());
        }
    }

    /**
     * اجرای بک‌گراند - با شروع خودکار Worker (متد جدید)
     */
    public function executeBackground(Config $config): JsonResponse
    {
        try {
            // بررسی اینکه کانفیگ فعال باشد
            if (!$config->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'کانفیگ غیرفعال است و قابل اجرا نیست!'
                ], 422);
            }

            // بررسی اینکه کانفیگ در حال اجرا نباشد
            if ($config->is_running) {
                return response()->json([
                    'success' => false,
                    'message' => 'این کانفیگ در حال اجرا است!'
                ], 422);
            }

            // شروع Worker اگر در حال اجرا نباشد
            QueueManagerService::ensureWorkerIsRunning();

            $apiSettings = $config->getApiSettings();
            $crawlingSettings = $config->getCrawlingSettings();

            $maxPages = min($crawlingSettings['max_pages'] ?? 50, 1000);
            $startPage = max($crawlingSettings['start_page'] ?? 1, 1);

            // علامت‌گذاری کانفیگ به عنوان در حال اجرا
            $config->update([
                'is_running' => true,
                'current_page' => $startPage
            ]);

            // ایجاد execution log
            $executionLog = ExecutionLog::createNew($config);
            $executionId = $executionLog->execution_id;

            // ایجاد Jobs برای هر صفحه
            for ($page = $startPage; $page <= $maxPages; $page++) {
                ProcessSinglePageJob::dispatch($config, $page, $executionId);
            }

            // Job ویژه برای تمام کردن اجرا
            ProcessSinglePageJob::dispatch($config, -1, $executionId)
                ->delay(now()->addMinutes(5));

            Log::info("شروع اجرای executeBackground", [
                'config_id' => $config->id,
                'start_page' => $startPage,
                'max_pages' => $maxPages,
                'execution_id' => $executionId
            ]);

            return response()->json([
                'success' => true,
                'message' => "✅ اجرا در پس‌زمینه شروع شد!\n📄 تعداد {$maxPages} صفحه در صف قرار گرفت.\n🆔 شناسه اجرا: {$executionId}",
                'execution_id' => $executionId,
                'total_pages' => $maxPages,
                'worker_status' => QueueManagerService::getWorkerStatus()
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در اجرای بک‌گراند', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در شروع اجرا: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * متوقف کردن اجرا - نسخه اصلاح شده
     */
    public function stopExecution(Config $config): JsonResponse
    {
        try {
            if (!$config->is_running) {
                return response()->json([
                    'success' => false,
                    'message' => 'این کانفیگ در حال اجرا نیست!'
                ], 422);
            }

            Log::info('🛑 شروع فرآیند توقف', [
                'config_id' => $config->id,
                'config_name' => $config->name,
                'current_stats' => [
                    'total_processed' => $config->total_processed,
                    'total_success' => $config->total_success,
                    'total_failed' => $config->total_failed
                ]
            ]);

            // 1. دریافت execution log فعال
            $activeExecution = ExecutionLog::where('config_id', $config->id)
                ->where('status', 'running')
                ->latest()
                ->first();

            // 2. فوری: کانفیگ را غیرفعال کن
            $config->update(['is_running' => false]);

            // 3. حذف Jobs مرتبط
            $deletedJobs = $this->deleteRelatedJobs($config, $activeExecution);

            // 4. متوقف کردن execution log با آمار صحیح
            if ($activeExecution) {
                // رفرش کانفیگ برای گرفتن آخرین آمار
                $config->refresh();

                // آمار واقعی در زمان توقف
                $currentStats = [
                    'total_processed_at_stop' => $config->total_processed,
                    'total_success_at_stop' => $config->total_success,
                    'total_failed_at_stop' => $config->total_failed,
                    'stopped_manually' => true,
                    'stopped_at' => now()->toISOString()
                ];

                Log::info("⏹️ متوقف کردن ExecutionLog با آمار واقعی", [
                    'execution_id' => $activeExecution->execution_id,
                    'config_stats' => $currentStats
                ]);

                $activeExecution->stop($currentStats);
            }

            // 5. کشتن Worker
            $this->forceKillWorker();

            // 6. بررسی نهایی
            $remainingJobs = DB::table('jobs')->count();
            $actualBooksCount = \App\Models\Book::where('created_at', '>=', $config->created_at)->count();

            Log::info('✅ فرآیند توقف تمام شد', [
                'config_id' => $config->id,
                'total_deleted_jobs' => $deletedJobs,
                'remaining_jobs' => $remainingJobs,
                'final_config_stats' => [
                    'total_processed' => $config->total_processed,
                    'total_success' => $config->total_success,
                    'total_failed' => $config->total_failed
                ],
                'actual_books_in_db' => $actualBooksCount
            ]);

            $message = "✅ اجرا متوقف شد!\n";
            $message .= "🗑️ {$deletedJobs} Job از صف حذف شد\n";
            $message .= "📊 آمار نهایی: {$config->total_success} کتاب موفق از {$config->total_processed} کل\n";
            $message .= "📚 کتاب‌های واقعی در دیتابیس: {$actualBooksCount}";

            return response()->json([
                'success' => true,
                'message' => $message,
                'stats' => [
                    'deleted_jobs' => $deletedJobs,
                    'remaining_jobs' => $remainingJobs,
                    'final_config_stats' => [
                        'total_processed' => $config->total_processed,
                        'total_success' => $config->total_success,
                        'total_failed' => $config->total_failed
                    ],
                    'actual_books_count' => $actualBooksCount
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('❌ خطا در متوقف کردن اجرا', [
                'config_id' => $config->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در متوقف کردن اجرا: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * حذف Jobs مرتبط با کانفیگ
     */
    private function deleteRelatedJobs(Config $config, ?ExecutionLog $activeExecution = null): int
    {
        $deletedJobs = 0;

        // الگوهای مختلف برای یافتن Jobs
        $patterns = [
            '%"config_id":' . $config->id . '%',
            '%"config":{"id":' . $config->id . '%',
            '%ProcessSinglePageJob%"config_id":' . $config->id . '%',
            '%ProcessApiDataJob%"config_id":' . $config->id . '%'
        ];

        foreach ($patterns as $pattern) {
            $deleted = DB::table('jobs')->where('payload', 'like', $pattern)->delete();
            $deletedJobs += $deleted;
            if ($deleted > 0) {
                Log::info("🗑️ حذف {$deleted} Job با الگو: {$pattern}");
            }
        }

        // حذف بر اساس execution_id
        if ($activeExecution) {
            $executionId = $activeExecution->execution_id;
            $deletedByExecId = DB::table('jobs')
                ->where('payload', 'like', '%"execution_id":"' . $executionId . '"%')
                ->delete();

            $deletedJobs += $deletedByExecId;
            if ($deletedByExecId > 0) {
                Log::info("🗑️ حذف {$deletedByExecId} Job با execution_id: {$executionId}");
            }
        }

        return $deletedJobs;
    }

    /**
     * کشتن اجباری Worker
     */
    private function forceKillWorker(): void
    {
        try {
            Log::info('🔪 شروع کشتن اجباری Worker...');

            // حذف فایل PID
            $pidFile = storage_path('framework/queue_worker.pid');
            if (File::exists($pidFile)) {
                $pid = (int) File::get($pidFile);

                // تلاش برای کشتن process
                if ($pid > 0) {
                    if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'Linux') {
                        exec("kill -TERM {$pid} 2>/dev/null");
                        sleep(2);
                        exec("kill -KILL {$pid} 2>/dev/null");
                    }
                    Log::info("🔪 Worker با PID {$pid} کشته شد");
                }

                File::delete($pidFile);
            }

            // کشتن همه فرآیندهای queue:work
            if (PHP_OS_FAMILY === 'Darwin' || PHP_OS_FAMILY === 'Linux') {
                exec("pkill -f 'queue:work' 2>/dev/null");
                exec("pkill -f 'ProcessSinglePageJob' 2>/dev/null");
                exec("pkill -f 'ProcessApiDataJob' 2>/dev/null");
                Log::info('🔪 همه فرآیندهای queue کشته شدند');
            }

        } catch (\Exception $e) {
            Log::error('❌ خطا در کشتن Worker', ['error' => $e->getMessage()]);
        }
    }

    /**
     * وضعیت Worker
     */
    public function workerStatus(): JsonResponse
    {
        try {
            return response()->json([
                'worker_status' => QueueManagerService::getWorkerStatus(),
                'queue_stats' => [
                    'pending_jobs' => DB::table('jobs')->count(),
                    'failed_jobs' => DB::table('failed_jobs')->count()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'worker_status' => ['is_running' => false, 'pid' => null],
                'queue_stats' => ['pending_jobs' => 0, 'failed_jobs' => 0],
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * مدیریت Worker
     */
    public function manageWorker(Request $request): JsonResponse
    {
        try {
            $action = $request->input('action'); // start, stop, restart

            switch ($action) {
                case 'start':
                    $result = QueueManagerService::startWorker();
                    break;
                case 'stop':
                    $result = QueueManagerService::stopWorker();
                    break;
                case 'restart':
                    $result = QueueManagerService::restartWorker();
                    break;
                default:
                    return response()->json(['success' => false, 'message' => 'عمل نامعتبر'], 400);
            }

            return response()->json([
                'success' => $result,
                'message' => $result ? "✅ Worker {$action} شد" : "❌ خطا در {$action} Worker",
                'worker_status' => QueueManagerService::getWorkerStatus()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "❌ خطا در {$action} Worker: " . $e->getMessage()
            ], 500);
        }
    }

    /**
     * نمایش لاگ‌های اجرا
     */
    public function logs(Config $config): View
    {
        $status = request('status');
        $query = ExecutionLog::where('config_id', $config->id);

        if ($status && in_array($status, ['running', 'completed', 'failed'])) {
            $query->where('status', $status);
        }

        $logs = $query->latest()->paginate(20);

        return view('configs.logs', compact('config', 'logs', 'status'));
    }

    /**
     * نمایش جزئیات یک لاگ اجرا
     */
    public function logDetails(Config $config, ExecutionLog $log): View
    {
        if ($log->config_id !== $config->id) {
            abort(404, 'لاگ متعلق به این کانفیگ نیست');
        }

        return view('configs.log-details', compact('config', 'log'));
    }

    /**
     * اعتبارسنجی داده‌های کانفیگ
     */
    private function validateConfigData(Request $request, ?int $configId = null): array
    {
        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('configs')->ignore($configId)
            ],
            'description' => 'nullable|string|max:1000',
            'base_url' => 'required|url|max:500',
            'timeout' => 'required|integer|min:10|max:300',
            'delay_seconds' => 'required|integer|min:1|max:3600',
            'records_per_run' => 'required|integer|min:1|max:100',
            'page_delay' => 'required|integer|min:0|max:300',
            'crawl_mode' => 'required|in:continue,restart,update',
            'start_page' => 'nullable|integer|min:1|max:10000',
            'status' => 'required|in:active,inactive,draft',

            // تنظیمات API
            'api_endpoint' => 'nullable|string|max:500',
            'api_method' => 'required|in:GET,POST',
            'auth_type' => 'required|in:none,bearer,api_key',
            'auth_token' => 'nullable|string|max:500',

            // تنظیمات عمومی
            'user_agent' => 'nullable|string|max:500',
            'verify_ssl' => 'boolean',
            'follow_redirects' => 'boolean',
        ];

        $messages = [
            'name.required' => 'نام کانفیگ الزامی است.',
            'name.unique' => 'نام کانفیگ قبلاً استفاده شده است.',
            'base_url.required' => 'آدرس پایه الزامی است.',
            'base_url.url' => 'آدرس پایه معتبر نیست.',
            'timeout.min' => 'حداقل timeout باید 10 ثانیه باشد.',
            'timeout.max' => 'حداکثر timeout می‌تواند 300 ثانیه باشد.',
            'delay_seconds.min' => 'حداقل تاخیر 1 ثانیه است.',
            'delay_seconds.max' => 'حداکثر تاخیر 3600 ثانیه است.',
            'records_per_run.min' => 'حداقل 1 رکورد باید پردازش شود.',
            'records_per_run.max' => 'حداکثر 100 رکورد قابل پردازش است.',
            'start_page.min' => 'شماره صفحه باید بزرگتر از 0 باشد.',
        ];

        return $request->validate($rules, $messages);
    }

    /**
     * ساخت داده‌های کانفیگ
     */
    private function buildConfigData(Request $request): array
    {
        return [
            'general' => [
                'user_agent' => $request->input('user_agent', 'Mozilla/5.0 (compatible; BookScraper/1.0)'),
                'verify_ssl' => $request->boolean('verify_ssl', true),
                'follow_redirects' => $request->boolean('follow_redirects', true),
            ],
            'api' => [
                'endpoint' => $request->input('api_endpoint'),
                'method' => $request->input('api_method', 'GET'),
                'auth_type' => $request->input('auth_type', 'none'),
                'auth_token' => $request->input('auth_token', ''),
                'params' => $this->buildApiParams($request),
                'field_mapping' => $this->buildFieldMapping($request)
            ],
            'crawling' => [
                'mode' => $request->input('crawl_mode', 'continue'),
                'start_page' => $request->input('start_page', 1),
                'max_pages' => $request->input('max_pages', 1000),
                'page_delay' => $request->input('page_delay', 5),
            ]
        ];
    }

    /**
     * ساخت پارامترهای API
     */
    private function buildApiParams(Request $request): array
    {
        $params = [];

        // پارامترهای اضافی که کاربر وارد کرده
        for ($i = 1; $i <= 5; $i++) {
            $key = $request->input("param_key_{$i}");
            $value = $request->input("param_value_{$i}");

            if (!empty($key) && !empty($value)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * ساخت نقشه‌برداری فیلدها
     */
    private function buildFieldMapping(Request $request): array
    {
        $mapping = [];

        foreach (array_keys(Config::getBookFields()) as $field) {
            $value = $request->input("api_field_{$field}");
            if (!empty($value)) {
                $mapping[$field] = $value;
            }
        }

        return $mapping;
    }

    /**
     * محاسبه تعداد صفحات مناسب برای اجرای بک‌گراند
     */
    private function calculateMaxPages(Config $config): int
    {
        // بر اساس سرعت کانفیگ تعداد صفحات را تعیین می‌کنیم
        if ($config->delay_seconds >= 30) {
            return 10; // کند
        } elseif ($config->delay_seconds >= 10) {
            return 20; // متوسط
        } else {
            return 50; // سریع
        }
    }

    /**
     * فرمت کردن نتایج اجرا برای نمایش
     */
    private function formatExecutionResults(array $stats): string
    {
        $total = $stats['total'] ?? 0;
        $success = $stats['success'] ?? 0;
        $failed = $stats['failed'] ?? 0;
        $duplicate = $stats['duplicate'] ?? 0;
        $executionTime = $stats['execution_time'] ?? 0;

        return "✅ اجرا تمام شد!
        📊 کل: " . number_format($total) . "
        ✅ موفق: " . number_format($success) . "
        ❌ خطا: " . number_format($failed) . "
        🔄 تکراری: " . number_format($duplicate) . "
        ⏱️ زمان: {$executionTime} ثانیه";
    }

    /**
     * اصلاح وضعیت execution log
     */
    public function fixLogStatus(ExecutionLog $log): JsonResponse
    {
        try {
            $config = $log->config;

            if (!$config) {
                return response()->json([
                    'success' => false,
                    'message' => 'کانفیگ مرتبط با این لاگ یافت نشد'
                ], 404);
            }

            // اگر کانفیگ در حال اجرا نیست، لاگ را متوقف کن
            if (!$config->is_running && $log->status === 'running') {
                $executionTime = $log->started_at ? now()->diffInSeconds($log->started_at) : 0;

                $log->update([
                    'status' => 'stopped',
                    'total_processed' => $config->total_processed,
                    'total_success' => $config->total_success,
                    'total_failed' => $config->total_failed,
                    'execution_time' => $executionTime,
                    'finished_at' => now(),
                    'last_activity_at' => now(),
                    'stop_reason' => 'اصلاح شده توسط کاربر',
                    'error_message' => 'وضعیت از running به stopped اصلاح شد'
                ]);

                $log->addLogEntry('وضعیت لاگ اصلاح شد', [
                    'fixed_by' => 'user',
                    'old_status' => 'running',
                    'new_status' => 'stopped',
                    'synced_stats' => [
                        'total_processed' => $config->total_processed,
                        'total_success' => $config->total_success,
                        'total_failed' => $config->total_failed
                    ]
                ]);

                Log::info("✅ وضعیت لاگ {$log->id} اصلاح شد", [
                    'execution_id' => $log->execution_id,
                    'old_status' => 'running',
                    'new_status' => 'stopped'
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'وضعیت لاگ با موفقیت اصلاح شد'
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'این لاگ نیاز به اصلاح ندارد'
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در اصلاح وضعیت لاگ', [
                'log_id' => $log->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در اصلاح وضعیت: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * همگام‌سازی آمار execution log با کانفیگ
     */
    public function syncLogStats(ExecutionLog $log): JsonResponse
    {
        try {
            $config = $log->config;

            if (!$config) {
                return response()->json([
                    'success' => false,
                    'message' => 'کانفیگ مرتبط با این لاگ یافت نشد'
                ], 404);
            }

            $oldStats = [
                'total_processed' => $log->total_processed,
                'total_success' => $log->total_success,
                'total_failed' => $log->total_failed
            ];

            $newStats = [
                'total_processed' => $config->total_processed,
                'total_success' => $config->total_success,
                'total_failed' => $config->total_failed
            ];

            // محاسبه نرخ موفقیت
            $successRate = $newStats['total_processed'] > 0
                ? round(($newStats['total_success'] / $newStats['total_processed']) * 100, 2)
                : 0;

            // محاسبه زمان اجرا صحیح
            $executionTime = 0;
            if ($log->started_at && $log->finished_at) {
                $executionTime = $log->finished_at->diffInSeconds($log->started_at);
            } elseif ($log->started_at) {
                $executionTime = now()->diffInSeconds($log->started_at);
            }

            $log->update([
                'total_processed' => $newStats['total_processed'],
                'total_success' => $newStats['total_success'],
                'total_failed' => $newStats['total_failed'],
                'success_rate' => $successRate,
                'execution_time' => $executionTime > 0 ? $executionTime : null,
            ]);

            $log->addLogEntry('آمار لاگ همگام‌سازی شد', [
                'synced_by' => 'user',
                'old_stats' => $oldStats,
                'new_stats' => $newStats,
                'success_rate' => $successRate,
                'execution_time' => $executionTime
            ]);

            Log::info("📊 آمار لاگ {$log->id} همگام‌سازی شد", [
                'execution_id' => $log->execution_id,
                'old_stats' => $oldStats,
                'new_stats' => $newStats
            ]);

            return response()->json([
                'success' => true,
                'message' => 'آمار لاگ با موفقیت همگام‌سازی شد',
                'stats' => $newStats
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در همگام‌سازی آمار لاگ', [
                'log_id' => $log->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در همگام‌سازی آمار: ' . $e->getMessage()
            ], 500);
        }
    }
}
