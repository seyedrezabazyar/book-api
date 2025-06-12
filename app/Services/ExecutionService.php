<?php

namespace App\Services;

use App\Models\Config;
use App\Models\ExecutionLog;
use App\Jobs\ProcessSinglePageJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ExecutionService
{
    public function __construct(private QueueManagerService $queueManager) {}

    public function startExecution(Config $config): JsonResponse
    {
        try {
            if ($config->is_running) {
                return response()->json([
                    'success' => false,
                    'message' => 'این کانفیگ در حال اجرا است!'
                ], 422);
            }

            $this->queueManager->ensureWorkerIsRunning();

            $maxIds = $config->max_pages ?? 1000;
            $startId = $config->getSmartStartPage();
            $endId = $startId + $maxIds - 1;

            $config->update(['is_running' => true]);
            $executionLog = ExecutionLog::createNew($config);
            $executionId = $executionLog->execution_id;

            // ایجاد Jobs
            for ($sourceId = $startId; $sourceId <= $endId; $sourceId++) {
                ProcessSinglePageJob::dispatch($config->id, $sourceId, $executionId);
            }

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

    public function stopExecution(Config $config): JsonResponse
    {
        try {
            if (!$config->is_running) {
                return response()->json([
                    'success' => false,
                    'message' => 'این کانفیگ در حال اجرا نیست!'
                ], 422);
            }

            $config->update(['is_running' => false]);

            $activeExecution = ExecutionLog::where('config_id', $config->id)
                ->where('status', 'running')
                ->latest()
                ->first();

            if ($activeExecution) {
                $activeExecution->stop(['stopped_manually' => true]);
            }

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

    public function getWorkerStatus(): array
    {
        try {
            $workerStatus = $this->queueManager->getWorkerStatus();
            $queueStats = $this->queueManager->getQueueStats();

            return [
                'is_running' => $workerStatus['is_running'] ?? false,
                'pid' => $workerStatus['pid'] ?? null,
                'message' => $workerStatus['message'] ?? 'نامشخص',
                'pending_jobs' => $queueStats['pending_jobs'] ?? 0,
                'failed_jobs' => $queueStats['failed_jobs'] ?? 0
            ];
        } catch (\Exception $e) {
            Log::error("خطا در دریافت وضعیت Worker", ['error' => $e->getMessage()]);
            return [
                'is_running' => false,
                'pid' => null,
                'message' => 'خطا در بررسی وضعیت',
                'pending_jobs' => 0,
                'failed_jobs' => 0
            ];
        }
    }

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
}
