<?php

namespace App\Http\Controllers;

use App\Models\Config;
use App\Models\ExecutionLog;
use App\Services\QueueManagerService;
use App\Jobs\ProcessSinglePageJob;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ConfigController extends Controller
{
    /**
     * نمایش صفحه اصلی کانفیگ‌ها با آمار پیشرفته
     */
    public function index(): View
    {
        try {
            // دریافت کانفیگ‌ها
            $configs = Config::with(['executionLogs' => function ($query) {
                $query->latest()->limit(3);
            }])->get();

            // دریافت وضعیت Worker
            $workerStatus = $this->getWorkerStatusSafe();

            // محاسبه آمار کلی سیستم
            $systemStats = $this->calculateSystemStats();

            return view('configs.index', compact('configs', 'systemStats', 'workerStatus'));
        } catch (\Exception $e) {
            Log::error("خطا در نمایش کانفیگ‌ها", ['error' => $e->getMessage()]);

            return view('configs.index', [
                'configs' => collect([]),
                'systemStats' => $this->getEmptySystemStats(),
                'workerStatus' => $this->getEmptyWorkerStatus()
            ])->with('error', 'خطا در بارگذاری کانفیگ‌ها');
        }
    }

    /**
     * دریافت وضعیت Worker به صورت ایمن
     */
    private function getWorkerStatusSafe(): array
    {
        try {
            $workerStatus = QueueManagerService::getWorkerStatus();
            $queueStats = QueueManagerService::getQueueStats();

            return [
                'is_running' => $workerStatus['is_running'] ?? false,
                'pid' => $workerStatus['pid'] ?? null,
                'message' => $workerStatus['message'] ?? 'نامشخص',
                'pending_jobs' => $queueStats['pending_jobs'] ?? 0,
                'failed_jobs' => $queueStats['failed_jobs'] ?? 0
            ];
        } catch (\Exception $e) {
            Log::error("خطا در دریافت وضعیت Worker", ['error' => $e->getMessage()]);
            return $this->getEmptyWorkerStatus();
        }
    }

    /**
     * وضعیت خالی Worker
     */
    private function getEmptyWorkerStatus(): array
    {
        return [
            'is_running' => false,
            'pid' => null,
            'message' => 'خطا در بررسی وضعیت',
            'pending_jobs' => 0,
            'failed_jobs' => 0
        ];
    }

    /**
     * نمایش لاگ‌های کانفیگ با آمار تفصیلی
     */
    public function logs(Config $config): View
    {
        try {
            $logs = ExecutionLog::where('config_id', $config->id)
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return view('configs.logs', compact('config', 'logs'));
        } catch (\Exception $e) {
            Log::error("خطا در نمایش لاگ‌های کانفیگ", [
                'config_id' => $config->id,
                'error' => $e->getMessage()
            ]);

            return redirect()->route('configs.index')
                ->with('error', 'خطا در بارگذاری لاگ‌ها');
        }
    }

    /**
     * نمایش جزئیات کانفیگ
     */
    public function show(Config $config): View
    {
        try {
            $recentLogs = $config->executionLogs()
                ->latest()
                ->limit(5)
                ->get();

            return view('configs.show', compact('config', 'recentLogs'));
        } catch (\Exception $e) {
            Log::error("خطا در نمایش جزئیات کانفیگ", [
                'config_id' => $config->id,
                'error' => $e->getMessage()
            ]);

            return redirect()->route('configs.index')
                ->with('error', 'خطا در بارگذاری جزئیات کانفیگ');
        }
    }

    /**
     * دریافت آمار سیستم
     */
    public function getStats(): JsonResponse
    {
        try {
            $systemStats = $this->calculateSystemStats();

            return response()->json([
                'success' => true,
                'data' => $systemStats
            ]);
        } catch (\Exception $e) {
            Log::error("خطا در دریافت آمار سیستم", ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت آمار',
                'data' => $this->getEmptySystemStats()
            ], 500);
        }
    }

    /**
     * محاسبه آمار کلی سیستم - ساده‌شده برای شروع پروژه
     */
    private function calculateSystemStats(): array
    {
        try {
            // آمار کلی
            $totalConfigs = Config::count();
            $activeConfigs = Config::where('is_active', true)->count();

            // آمار اجرا
            $totalExecutions = ExecutionLog::count();
            $successfulExecutions = ExecutionLog::where('status', 'completed')->count();
            $runningExecutions = ExecutionLog::where('status', 'running')->count();

            // آمار کتاب‌ها
            $totalBooksProcessed = ExecutionLog::sum('total_processed') ?: 0;
            $totalBooksCreated = ExecutionLog::sum('total_success') ?: 0;
            $totalBooksEnhanced = ExecutionLog::sum('total_enhanced') ?: 0;
            $totalBooksFailed = ExecutionLog::sum('total_failed') ?: 0;

            // آمار امروز
            $todayStats = ExecutionLog::whereDate('created_at', today())
                ->selectRaw('
                SUM(total_processed) as today_processed,
                SUM(total_success) as today_success,
                SUM(total_enhanced) as today_enhanced,
                SUM(total_failed) as today_failed
            ')
                ->first();

            // نرخ‌های محاسبه شده
            $totalImpactfulBooks = $totalBooksCreated + $totalBooksEnhanced;
            $overallSuccessRate = $totalBooksProcessed > 0
                ? round(($totalImpactfulBooks / $totalBooksProcessed) * 100, 2)
                : 0;

            $executionSuccessRate = $totalExecutions > 0
                ? round(($successfulExecutions / $totalExecutions) * 100, 2)
                : 0;

            // آمار کتاب‌های واقعی در دیتابیس
            $actualBooksInDb = \App\Models\Book::count();

            return [
                'total_configs' => $totalConfigs,
                'active_configs' => $activeConfigs,
                'running_configs' => $runningExecutions,
                'total_books' => $actualBooksInDb,
                'configs' => [
                    'total' => $totalConfigs,
                    'active' => $activeConfigs,
                    'inactive' => $totalConfigs - $activeConfigs
                ],
                'executions' => [
                    'total' => $totalExecutions,
                    'successful' => $successfulExecutions,
                    'running' => $runningExecutions,
                    'success_rate' => $executionSuccessRate
                ],
                'books' => [
                    'total_processed' => $totalBooksProcessed,
                    'total_created' => $totalBooksCreated,
                    'total_enhanced' => $totalBooksEnhanced,
                    'total_failed' => $totalBooksFailed,
                    'total_impactful' => $totalImpactfulBooks,
                    'actual_in_db' => $actualBooksInDb,
                    'overall_impact_rate' => $overallSuccessRate
                ],
                'today' => [
                    'processed' => $todayStats->today_processed ?? 0,
                    'created' => $todayStats->today_success ?? 0,
                    'enhanced' => $todayStats->today_enhanced ?? 0,
                    'failed' => $todayStats->today_failed ?? 0,
                    'impactful' => ($todayStats->today_success ?? 0) + ($todayStats->today_enhanced ?? 0)
                ],
                'performance' => [
                    'avg_books_per_execution' => $totalExecutions > 0 ? round($totalBooksProcessed / $totalExecutions, 1) : 0,
                    'enhancement_rate' => $totalBooksProcessed > 0 ? round(($totalBooksEnhanced / $totalBooksProcessed) * 100, 2) : 0,
                    'creation_rate' => $totalBooksProcessed > 0 ? round(($totalBooksCreated / $totalBooksProcessed) * 100, 2) : 0,
                ]
            ];
        } catch (\Exception $e) {
            Log::error("خطا در محاسبه آمار سیستم", ['error' => $e->getMessage()]);
            return $this->getEmptySystemStats();
        }
    }

    /**
     * آمار خالی در صورت خطا
     */
    private function getEmptySystemStats(): array
    {
        return [
            'total_configs' => 0,
            'active_configs' => 0,
            'running_configs' => 0,
            'total_books' => 0,
            'configs' => ['total' => 0, 'active' => 0, 'inactive' => 0],
            'executions' => ['total' => 0, 'successful' => 0, 'running' => 0, 'success_rate' => 0],
            'books' => [
                'total_processed' => 0, 'total_created' => 0, 'total_enhanced' => 0,
                'total_failed' => 0, 'total_impactful' => 0, 'actual_in_db' => 0,
                'overall_impact_rate' => 0
            ],
            'today' => ['processed' => 0, 'created' => 0, 'enhanced' => 0, 'failed' => 0, 'impactful' => 0],
            'performance' => ['avg_books_per_execution' => 0, 'enhancement_rate' => 0, 'creation_rate' => 0]
        ];
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
            $sourceName = $this->extractSourceName($validated['base_url']);

            Config::create([
                ...$validated,
                'source_type' => 'api',
                'source_name' => $sourceName,
                'max_pages' => $request->input('max_pages', 1000),
                'auto_resume' => $request->boolean('auto_resume', true),
                'fill_missing_fields' => $request->boolean('fill_missing_fields', true),
                'update_descriptions' => $request->boolean('update_descriptions', true),
                'last_source_id' => 0,
                'config_data' => $configData,
                'created_by' => Auth::id(),
                'current_page' => $request->input('start_page', 1),
                'total_processed' => 0,
                'total_success' => 0,
                'total_failed' => 0,
                'is_running' => false,
                'is_active' => true
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
            if ($config->is_running) {
                return redirect()->back()
                    ->with('error', 'امکان ویرایش کانفیگ در حال اجرا وجود ندارد. ابتدا آن را متوقف کنید.');
            }

            $configData = $this->buildConfigData($request);
            $sourceName = $this->extractSourceName($validated['base_url']);

            $config->update([
                ...$validated,
                'source_name' => $sourceName,
                'max_pages' => $request->input('max_pages', $config->max_pages),
                'auto_resume' => $request->boolean('auto_resume', $config->auto_resume),
                'fill_missing_fields' => $request->boolean('fill_missing_fields', $config->fill_missing_fields),
                'update_descriptions' => $request->boolean('update_descriptions', $config->update_descriptions),
                'config_data' => $configData
            ]);

            return redirect()->route('configs.index')
                ->with('success', 'کانفیگ با موفقیت به‌روزرسانی شد!');
        } catch (\Exception $e) {
            Log::error('خطا در بروزرسانی کانفیگ', [
                'config_id' => $config->id,
                'error' => $e->getMessage()
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
            if ($config->is_running) {
                return redirect()->back()
                    ->with('error', 'امکان حذف کانفیگ در حال اجرا وجود ندارد. ابتدا آن را متوقف کنید.');
            }

            ExecutionLog::where('config_id', $config->id)->delete();
            \App\Models\ScrapingFailure::where('config_id', $config->id)->delete();
            $config->delete();

            return redirect()->route('configs.index')
                ->with('success', 'کانفیگ با موفقیت حذف شد!');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'خطا در حذف کانفیگ: ' . $e->getMessage());
        }
    }

    /**
     * اجرای بک‌گراند کانفیگ
     */
    public function executeBackground(Config $config): JsonResponse
    {
        try {
            if ($config->is_running) {
                return response()->json([
                    'success' => false,
                    'message' => 'این کانفیگ در حال اجرا است!'
                ], 422);
            }

            // شروع Worker
            QueueManagerService::ensureWorkerIsRunning();

            $maxIds = $config->max_pages ?? 1000;
            $startId = $config->getSmartStartPage();
            $endId = $startId + $maxIds - 1;

            // علامت‌گذاری به عنوان در حال اجرا
            $config->update(['is_running' => true]);

            // ایجاد execution log
            $executionLog = ExecutionLog::createNew($config);
            $executionId = $executionLog->execution_id;

            // ایجاد Jobs
            for ($sourceId = $startId; $sourceId <= $endId; $sourceId++) {
                ProcessSinglePageJob::dispatch($config->id, $sourceId, $executionId);
            }

            // Job پایان اجرا
            ProcessSinglePageJob::dispatch($config->id, -1, $executionId)
                ->delay(now()->addMinutes(5));

            Log::info("🚀 اجرای هوشمند شروع شد", [
                'config_id' => $config->id,
                'source_name' => $config->source_name,
                'start_id' => $startId,
                'end_id' => $endId,
                'execution_id' => $executionId
            ]);

            return response()->json([
                'success' => true,
                'message' => "✅ اجرای هوشمند شروع شد!\n📊 منبع: {$config->source_name}\n🔢 ID های {$startId} تا {$endId} ({$maxIds} ID)\n🆔 شناسه اجرا: {$executionId}",
                'execution_id' => $executionId,
                'total_ids' => $maxIds,
                'start_id' => $startId,
                'end_id' => $endId,
                'source_name' => $config->source_name
            ]);
        } catch (\Exception $e) {
            Log::error('خطا در اجرای بک‌گراند', [
                'config_id' => $config->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در شروع اجرا: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * متوقف کردن اجرا
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

            // متوقف کردن کانفیگ
            $config->update(['is_running' => false]);

            // متوقف کردن execution log فعال
            $activeExecution = ExecutionLog::where('config_id', $config->id)
                ->where('status', 'running')
                ->latest()
                ->first();

            if ($activeExecution) {
                $activeExecution->stop(['stopped_manually' => true]);
            }

            // حذف Jobs باقی‌مانده
            $deletedJobs = DB::table('jobs')
                ->where('payload', 'like', '%"configId":' . $config->id . '%')
                ->delete();

            return response()->json([
                'success' => true,
                'message' => "✅ اجرا متوقف شد!\n🗑️ {$deletedJobs} Job از صف حذف شد\n📊 آمار: {$config->total_success} کتاب موفق از {$config->total_processed} کل"
            ]);
        } catch (\Exception $e) {
            Log::error('خطا در متوقف کردن اجرا', [
                'config_id' => $config->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در متوقف کردن اجرا: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * جزئیات لاگ
     */
    public function logDetails(Config $config, ExecutionLog $log): View
    {
        if ($log->config_id !== $config->id) {
            abort(404, 'لاگ متعلق به این کانفیگ نیست');
        }

        return view('configs.log-details', compact('config', 'log'));
    }

    /**
     * اصلاح وضعیت لاگ
     */
    public function fixLogStatus(ExecutionLog $log): JsonResponse
    {
        try {
            $config = $log->config;

            if (!$config->is_running && $log->status === 'running') {
                $log->stop(['stopped_manually' => false, 'fixed_by_user' => true]);

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
            return response()->json([
                'success' => false,
                'message' => 'خطا در اصلاح وضعیت: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * همگام‌سازی آمار لاگ
     */
    public function syncLogStats(ExecutionLog $log): JsonResponse
    {
        try {
            $config = $log->config;
            if (!$config) {
                return response()->json(['success' => false, 'message' => 'کانفیگ یافت نشد'], 404);
            }

            $log->update([
                'total_processed' => $config->total_processed,
                'total_success' => $config->total_success,
                'total_failed' => $config->total_failed,
                'success_rate' => $config->total_processed > 0
                    ? round(($config->total_success / $config->total_processed) * 100, 2)
                    : 0,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'آمار لاگ با موفقیت همگام‌سازی شد'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در همگام‌سازی آمار: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * وضعیت Worker
     */
    public function workerStatus(): JsonResponse
    {
        try {
            $workerStatus = QueueManagerService::getWorkerStatus();
            $queueStats = QueueManagerService::getQueueStats();

            return response()->json([
                'worker_status' => $workerStatus,
                'queue_stats' => $queueStats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'worker_status' => ['is_running' => false, 'pid' => null],
                'queue_stats' => ['pending_jobs' => 0, 'failed_jobs' => 0],
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // متدهای کمکی
    private function extractSourceName(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $sourceName = preg_replace('/^www\./', '', $host ?? '');
        $sourceName = str_replace('.', '_', $sourceName);
        return $sourceName ?: 'unknown_source';
    }

    private function validateConfigData(Request $request, ?int $configId = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('configs')->ignore($configId)
            ],
            'base_url' => 'required|url|max:500',
            'timeout' => 'required|integer|min:10|max:300',
            'delay_seconds' => 'required|integer|min:1|max:3600',
            'records_per_run' => 'required|integer|min:1|max:100',
            'page_delay' => 'required|integer|min:0|max:300',
            'start_page' => 'nullable|integer|min:1|max:10000',
            'max_pages' => 'required|integer|min:1|max:10000',
            'api_endpoint' => 'nullable|string|max:500',
            'api_method' => 'required|in:GET,POST',
            'verify_ssl' => 'boolean',
            'follow_redirects' => 'boolean',
            'auto_resume' => 'boolean',
            'fill_missing_fields' => 'boolean',
            'update_descriptions' => 'boolean',
        ], [
            'name.required' => 'نام کانفیگ الزامی است.',
            'name.unique' => 'نام کانفیگ قبلاً استفاده شده است.',
            'base_url.required' => 'آدرس پایه الزامی است.',
            'base_url.url' => 'آدرس پایه معتبر نیست.',
            'max_pages.required' => 'تعداد حداکثر صفحات الزامی است.',
        ]);
    }

    private function buildConfigData(Request $request): array
    {
        return [
            'general' => [
                'verify_ssl' => $request->boolean('verify_ssl', true),
                'follow_redirects' => $request->boolean('follow_redirects', true),
            ],
            'api' => [
                'endpoint' => $request->input('api_endpoint'),
                'method' => $request->input('api_method', 'GET'),
                'params' => $this->buildApiParams($request),
                'field_mapping' => $this->buildFieldMapping($request)
            ],
        ];
    }

    private function buildApiParams(Request $request): array
    {
        $params = [];
        for ($i = 1; $i <= 5; $i++) {
            $key = $request->input("param_key_{$i}");
            $value = $request->input("param_value_{$i}");
            if (!empty($key) && !empty($value)) {
                $params[$key] = $value;
            }
        }
        return $params;
    }

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
}
