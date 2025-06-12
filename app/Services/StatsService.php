<?php

namespace App\Services;

use App\Models\Config;
use App\Models\ExecutionLog;
use App\Models\Book;
use App\Models\BookSource;
use Illuminate\Support\Facades\Log;

class StatsService
{
    public function getSystemStats(): array
    {
        try {
            return [
                'configs' => $this->getConfigStats(),
                'executions' => $this->getExecutionStats(),
                'books' => $this->getBookStats(),
                'today' => $this->getTodayStats(),
                'performance' => $this->getPerformanceStats()
            ];
        } catch (\Exception $e) {
            Log::error("خطا در محاسبه آمار سیستم", ['error' => $e->getMessage()]);
            return $this->getEmptyStats();
        }
    }

    private function getConfigStats(): array
    {
        return [
            'total' => Config::count(),
            'active' => Config::where('is_active', true)->count(),
            'running' => Config::where('is_running', true)->count(),
            'total_sources' => BookSource::distinct('source_name')->count()
        ];
    }

    private function getExecutionStats(): array
    {
        $total = ExecutionLog::count();
        $successful = ExecutionLog::where('status', 'completed')->count();
        $stopped = ExecutionLog::where('status', 'stopped')->count();

        return [
            'total' => $total,
            'successful' => $successful,
            'stopped' => $stopped,
            'running' => ExecutionLog::where('status', 'running')->count(),
            'failed' => ExecutionLog::where('status', 'failed')->count(),
            'success_rate' => $total > 0 ? round((($successful + $stopped) / $total) * 100, 2) : 0
        ];
    }

    private function getBookStats(): array
    {
        $aggregated = ExecutionLog::whereIn('status', ['completed', 'stopped'])
            ->selectRaw('
                SUM(total_processed) as total_processed,
                SUM(total_success) as total_created,
                SUM(total_enhanced) as total_enhanced,
                SUM(total_failed) as total_failed,
                SUM(total_duplicate) as total_duplicate
            ')
            ->first();

        $totalProcessed = $aggregated->total_processed ?? 0;
        $totalCreated = $aggregated->total_created ?? 0;
        $totalEnhanced = $aggregated->total_enhanced ?? 0;
        $totalFailed = $aggregated->total_failed ?? 0;
        $totalDuplicate = $aggregated->total_duplicate ?? 0;

        $totalImpactful = $totalCreated + $totalEnhanced;

        return [
            'total_processed' => $totalProcessed,
            'total_created' => $totalCreated,
            'total_enhanced' => $totalEnhanced,
            'total_failed' => $totalFailed,
            'total_duplicate' => $totalDuplicate,
            'total_impactful' => $totalImpactful,
            'actual_in_db' => Book::count(),
            'impact_rate' => $totalProcessed > 0 ? round(($totalImpactful / $totalProcessed) * 100, 2) : 0,
            'creation_rate' => $totalProcessed > 0 ? round(($totalCreated / $totalProcessed) * 100, 2) : 0,
            'enhancement_rate' => $totalProcessed > 0 ? round(($totalEnhanced / $totalProcessed) * 100, 2) : 0,
            'failure_rate' => $totalProcessed > 0 ? round(($totalFailed / $totalProcessed) * 100, 2) : 0
        ];
    }

    private function getTodayStats(): array
    {
        $today = ExecutionLog::whereDate('created_at', today())
            ->selectRaw('
                SUM(total_processed) as processed,
                SUM(total_success) as created,
                SUM(total_enhanced) as enhanced,
                SUM(total_failed) as failed,
                SUM(total_duplicate) as duplicate
            ')
            ->first();

        $processed = $today->processed ?? 0;
        $created = $today->created ?? 0;
        $enhanced = $today->enhanced ?? 0;
        $impactful = $created + $enhanced;

        return [
            'processed' => $processed,
            'created' => $created,
            'enhanced' => $enhanced,
            'failed' => $today->failed ?? 0,
            'duplicate' => $today->duplicate ?? 0,
            'impactful' => $impactful,
            'impact_rate' => $processed > 0 ? round(($impactful / $processed) * 100, 2) : 0
        ];
    }

    private function getPerformanceStats(): array
    {
        $aggregated = ExecutionLog::whereIn('status', ['completed', 'stopped'])
            ->where('execution_time', '>', 0)
            ->selectRaw('
                COUNT(*) as total_executions,
                SUM(total_processed) as total_processed,
                SUM(execution_time) as total_time,
                AVG(execution_time) as avg_time
            ')
            ->first();

        $totalExecutions = $aggregated->total_executions ?? 0;
        $totalProcessed = $aggregated->total_processed ?? 0;
        $totalTime = $aggregated->total_time ?? 1;
        $avgTime = $aggregated->avg_time ?? 0;

        return [
            'avg_books_per_execution' => $totalExecutions > 0 ? round($totalProcessed / $totalExecutions, 1) : 0,
            'avg_execution_time' => round($avgTime, 1),
            'avg_records_per_second' => $totalTime > 0 ? round($totalProcessed / $totalTime, 2) : 0,
            'total_execution_hours' => round($totalTime / 3600, 1)
        ];
    }

    private function getEmptyStats(): array
    {
        return [
            'configs' => ['total' => 0, 'active' => 0, 'running' => 0, 'total_sources' => 0],
            'executions' => ['total' => 0, 'successful' => 0, 'stopped' => 0, 'running' => 0, 'failed' => 0, 'success_rate' => 0],
            'books' => [
                'total_processed' => 0, 'total_created' => 0, 'total_enhanced' => 0,
                'total_failed' => 0, 'total_duplicate' => 0, 'total_impactful' => 0,
                'actual_in_db' => 0, 'impact_rate' => 0, 'creation_rate' => 0,
                'enhancement_rate' => 0, 'failure_rate' => 0
            ],
            'today' => ['processed' => 0, 'created' => 0, 'enhanced' => 0, 'failed' => 0, 'duplicate' => 0, 'impactful' => 0, 'impact_rate' => 0],
            'performance' => ['avg_books_per_execution' => 0, 'avg_execution_time' => 0, 'avg_records_per_second' => 0, 'total_execution_hours' => 0]
        ];
    }
}
