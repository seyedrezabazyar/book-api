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
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Schema;
use App\Helpers\UserAgentHelper;

class ConfigController extends Controller
{
    /**
     * نمایش لیست کانفیگ‌ها
     */
    public function index(Request $request)
    {
        $configs = Config::with(['executionLogs' => function ($query) {
            $query->latest()->limit(1);
        }])
            ->when($request->search, function ($query, $search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('base_url', 'like', "%{$search}%");
            })
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        $stats = [
            'total_configs' => Config::count(),
            'active_configs' => Config::count(),
            'running_configs' => Config::where('is_running', true)->count(),
            'total_books' => \App\Models\Book::count(),
        ];

        $workerStatus = QueueManagerService::getWorkerStatus();
        $queueStats = QueueManagerService::getQueueStats();
        $workerStatus = array_merge($workerStatus, [
            'pending_jobs' => $queueStats['pending_jobs'],
            'failed_jobs' => $queueStats['failed_jobs']
        ]);

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

            // استخراج نام منبع از URL
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
                'is_running' => false
            ]);

            Log::info('کانفیگ جدید ایجاد شد', [
                'name' => $validated['name'],
                'source_name' => $sourceName,
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
        $recentLogs = ExecutionLog::where('config_id', $config->id)
            ->latest()
            ->limit(5)
            ->get();

        $stats = [
            'total_executions' => ExecutionLog::where('config_id', $config->id)->count(),
            'successful_executions' => ExecutionLog::where('config_id', $config->id)
                ->where('status', 'completed')->count(),
            'failed_executions' => ExecutionLog::where('config_id', $config->id)
                ->where('status', 'failed')->count(),
            'total_books_processed' => $config->total_processed,
            'success_rate' => $config->total_processed > 0
                ? round(($config->total_success / $config->total_processed) * 100, 2)
                : 0,
            'last_source_id' => $config->last_source_id,
            'next_source_id' => $config->getSmartStartPage()
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
            if ($config->is_running) {
                return redirect()->back()
                    ->with('error', 'امکان حذف کانفیگ در حال اجرا وجود ندارد. ابتدا آن را متوقف کنید.');
            }

            $configName = $config->name;

            // حذف لاگ‌های مربوطه و منابع
            ExecutionLog::where('config_id', $config->id)->delete();
            \App\Models\ScrapingFailure::where('config_id', $config->id)->delete();

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
     * اجرای بک‌گراند کانفیگ (متد اصلی)
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

            // شروع Worker اگر در حال اجرا نباشد
            QueueManagerService::ensureWorkerIsRunning();

            $maxIds = $config->max_pages ?? 1000;
            $startId = $config->getSmartStartPage();
            $endId = $startId + $maxIds - 1;

            // علامت‌گذاری کانفیگ به عنوان در حال اجرا
            $config->update(['is_running' => true]);

            // ایجاد execution log
            $executionLog = ExecutionLog::createNew($config);
            $executionId = $executionLog->execution_id;

            // ایجاد Jobs برای هر source ID
            for ($sourceId = $startId; $sourceId <= $endId; $sourceId++) {
                ProcessSinglePageJob::dispatch($config->id, $sourceId, $executionId);
            }

            // Job ویژه برای تمام کردن اجرا
            ProcessSinglePageJob::dispatch($config->id, -1, $executionId)
                ->delay(now()->addMinutes(5));

            Log::info("🚀 شروع اجرای executeBackground با source ID", [
                'config_id' => $config->id,
                'source_name' => $config->source_name,
                'start_id' => $startId,
                'end_id' => $endId,
                'execution_id' => $executionId
            ]);

            return response()->json([
                'success' => true,
                'message' => "✅ اجرا در پس‌زمینه شروع شد!\n📊 منبع: {$config->source_name}\n🔢 ID های {$startId} تا {$endId} ({$maxIds} ID)\n🆔 شناسه اجرا: {$executionId}",
                'execution_id' => $executionId,
                'total_ids' => $maxIds,
                'start_id' => $startId,
                'end_id' => $endId,
                'source_name' => $config->source_name,
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
     * متوقف کردن اجرا
     */
    public function stopExecution(Config $config): JsonResponse
    {
        try {
            Log::info('🛑 درخواست توقف اجرا', [
                'config_id' => $config->id,
                'is_running' => $config->is_running
            ]);

            if (!$config->is_running) {
                return response()->json([
                    'success' => false,
                    'message' => 'این کانفیگ در حال اجرا نیست!'
                ], 422);
            }

            // متوقف کردن کانفیگ
            DB::transaction(function () use ($config) {
                $config->update(['is_running' => false]);
            });

            // متوقف کردن execution log فعال
            $activeExecution = ExecutionLog::where('config_id', $config->id)
                ->where('status', 'running')
                ->latest()
                ->first();

            if ($activeExecution) {
                try {
                    $executionTime = $activeExecution->started_at ? now()->diffInSeconds($activeExecution->started_at) : 0;

                    $activeExecution->update([
                        'status' => 'stopped',
                        'finished_at' => now(),
                        'execution_time' => $executionTime,
                        'stop_reason' => 'متوقف شده توسط کاربر',
                        'error_message' => 'متوقف شده توسط کاربر'
                    ]);

                    $activeExecution->addLogEntry('⏹️ اجرا توسط کاربر متوقف شد', [
                        'stopped_manually' => true,
                        'stopped_at' => now()->toISOString(),
                        'execution_time' => $executionTime,
                        'last_source_id' => $config->last_source_id
                    ]);

                    Log::info("⏹️ ExecutionLog متوقف شد", ['execution_id' => $activeExecution->execution_id]);
                } catch (\Exception $e) {
                    Log::error('خطا در متوقف کردن ExecutionLog', ['error' => $e->getMessage()]);
                }
            }

            // حذف Jobs مرتبط با این کانفیگ
            try {
                $deletedJobs = DB::table('jobs')
                    ->where('payload', 'like', '%"configId":' . $config->id . '%')
                    ->orWhere('payload', 'like', '%"config":' . $config->id . '%')
                    ->delete();

                Log::info("🗑️ {$deletedJobs} Job حذف شد");

                $deletedFailedJobs = DB::table('failed_jobs')
                    ->where('payload', 'like', '%"configId":' . $config->id . '%')
                    ->orWhere('payload', 'like', '%"config":' . $config->id . '%')
                    ->delete();

                if ($deletedFailedJobs > 0) {
                    Log::info("🗑️ {$deletedFailedJobs} Failed Job حذف شد");
                }
            } catch (\Exception $e) {
                Log::error('خطا در حذف Jobs', ['error' => $e->getMessage()]);
                $deletedJobs = 0;
            }

            $message = "✅ اجرا متوقف شد!\n";
            $message .= "🗑️ {$deletedJobs} Job از صف حذف شد\n";
            $message .= "📊 آمار: {$config->total_success} کتاب موفق از {$config->total_processed} کل\n";
            $message .= "🔢 آخرین source ID: {$config->last_source_id}";

            return response()->json([
                'success' => true,
                'message' => $message
            ]);
        } catch (\Exception $e) {
            Log::error('❌ خطا در متوقف کردن اجرا', [
                'config_id' => $config->id,
                'error' => $e->getMessage()
            ]);

            try {
                DB::table('configs')->where('id', $config->id)->update(['is_running' => false]);
            } catch (\Exception $ex) {
                // نادیده بگیر
            }

            return response()->json([
                'success' => false,
                'message' => 'خطا در متوقف کردن اجرا: ' . $e->getMessage()
            ], 500);
        }
    }

    // متدهای کمکی
    private function extractSourceName(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $sourceName = preg_replace('/^www\./', '', $host);
        $sourceName = str_replace('.', '_', $sourceName);
        return $sourceName ?: 'unknown_source';
    }

    private function validateConfigData(Request $request, ?int $configId = null): array
    {
        $rules = [
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

            // تنظیمات API
            'api_endpoint' => 'nullable|string|max:500',
            'api_method' => 'required|in:GET,POST',

            // تنظیمات عمومی
            'verify_ssl' => 'boolean',
            'follow_redirects' => 'boolean',
            'auto_resume' => 'boolean',
            'fill_missing_fields' => 'boolean',
            'update_descriptions' => 'boolean',
        ];

        $messages = [
            'name.required' => 'نام کانفیگ الزامی است.',
            'name.unique' => 'نام کانفیگ قبلاً استفاده شده است.',
            'base_url.required' => 'آدرس پایه الزامی است.',
            'base_url.url' => 'آدرس پایه معتبر نیست.',
            'max_pages.required' => 'تعداد حداکثر صفحات الزامی است.',
            'max_pages.min' => 'حداقل 1 صفحه باید پردازش شود.',
            'max_pages.max' => 'حداکثر 10000 صفحه قابل پردازش است.',
        ];

        return $request->validate($rules, $messages);
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
            'crawling' => [
                'auto_resume' => $request->boolean('auto_resume', true),
                'fill_missing_fields' => $request->boolean('fill_missing_fields', true),
                'update_descriptions' => $request->boolean('update_descriptions', true),
                'max_pages' => $request->input('max_pages', 1000),
                'start_page' => $request->input('start_page'),
                'page_delay' => $request->input('page_delay', 5),
            ]
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

    // سایر متدهای قبلی...
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

    public function logDetails(Config $config, ExecutionLog $log): View
    {
        if ($log->config_id !== $config->id) {
            abort(404, 'لاگ متعلق به این کانفیگ نیست');
        }

        return view('configs.log-details', compact('config', 'log'));
    }

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

            $newStats = [
                'total_processed' => $config->total_processed,
                'total_success' => $config->total_success,
                'total_failed' => $config->total_failed
            ];

            $successRate = $newStats['total_processed'] > 0
                ? round(($newStats['total_success'] / $newStats['total_processed']) * 100, 2)
                : 0;

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

            return response()->json([
                'success' => true,
                'message' => 'آمار لاگ با موفقیت همگام‌سازی شد',
                'stats' => $newStats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در همگام‌سازی آمار: ' . $e->getMessage()
            ], 500);
        }
    }
}
