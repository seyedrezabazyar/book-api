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

        $stats = $config->getDisplayStats();

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

            // اگر start_page تغییر کرده، کانفیگ رو برای شروع مجدد آماده کن
            $oldStartPage = $config->start_page;
            $newStartPage = $request->input('start_page');

            $config->update([
                ...$validated,
                'source_name' => $sourceName,
                'max_pages' => $request->input('max_pages', $config->max_pages),
                'auto_resume' => $request->boolean('auto_resume', $config->auto_resume),
                'fill_missing_fields' => $request->boolean('fill_missing_fields', $config->fill_missing_fields),
                'update_descriptions' => $request->boolean('update_descriptions', $config->update_descriptions),
                'config_data' => $configData
            ]);

            // اگر start_page تغییر کرده و مقدار جدید کمتر از آخرین ID است، پیام نمایش بده
            if ($newStartPage && $newStartPage != $oldStartPage && $newStartPage <= $config->last_source_id) {
                $message = 'کانفیگ بروزرسانی شد! 🔄 اجرای بعدی از ID ' . $newStartPage . ' شروع می‌شود و رکوردهای قبلی در صورت نیاز بروزرسانی خواهند شد.';
                return redirect()->route('configs.index')->with('success', $message);
            }

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
     * نمایش لاگ‌ها
     */
    public function logs(Config $config): View
    {
        $status = request('status');
        $query = ExecutionLog::where('config_id', $config->id);

        if ($status && in_array($status, ['running', 'completed', 'failed', 'stopped'])) {
            $query->where('status', $status);
        }

        $logs = $query->latest()->paginate(20);

        return view('configs.logs', compact('config', 'logs', 'status'));
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
        $sourceName = preg_replace('/^www\./', '', $host);
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
