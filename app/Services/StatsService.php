<?php

namespace App\Services;

use App\Models\Config;
use App\Models\ExecutionLog;
use App\Models\Book;
use Illuminate\Support\Facades\Log;

class StatsService
{
    public function getSystemStats(): array
    {
        try {
            return [
                'total_configs' => Config::count(),
                'active_configs' => Config::where('is_active', true)->count(),
                'running_configs' => Config::where('is_running', true)->count(),
                'total_books' => Book::count(),
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
        try {
            $totalConfigs = Config::count();
            $activeConfigs = Config::where('is_active', true)->count();
            $runningConfigs = Config::where('is_running', true)->count();

            return [
                'total' => $totalConfigs,
                'active' => $activeConfigs,
                'inactive' => $totalConfigs - $activeConfigs,
                'running' => $runningConfigs
            ];
        } catch (\Exception $e) {
            Log::error("خطا در محاسبه آمار کانفیگ", ['error' => $e->getMessage()]);
            return ['total' => 0, 'active' => 0, 'inactive' => 0, 'running' => 0];
        }
    }

    private function getExecutionStats(): array
    {
        try {
            $totalExecutions = ExecutionLog::count();
            $successfulExecutions = ExecutionLog::where('status', 'completed')->count();
            $runningExecutions = ExecutionLog::where('status', 'running')->count();

            return [
                'total' => $totalExecutions,
                'successful' => $successfulExecutions,
                'running' => $runningExecutions,
                'success_rate' => $totalExecutions > 0 ?
                    round(($successfulExecutions / $totalExecutions) * 100, 2) : 0
            ];
        } catch (\Exception $e) {
            Log::error("خطا در محاسبه آمار اجرا", ['error' => $e->getMessage()]);
            return ['total' => 0, 'successful' => 0, 'running' => 0, 'success_rate' => 0];
        }
    }

    private function getBookStats(): array
    {
        try {
            $totalProcessed = ExecutionLog::sum('total_processed') ?: 0;
            $totalCreated = ExecutionLog::sum('total_success') ?: 0;
            $totalEnhanced = ExecutionLog::sum('total_enhanced') ?: 0;
            $totalFailed = ExecutionLog::sum('total_failed') ?: 0;
            $actualInDb = Book::count();

            $totalImpactful = $totalCreated + $totalEnhanced;
            $overallImpactRate = $totalProcessed > 0 ?
                round(($totalImpactful / $totalProcessed) * 100, 2) : 0;

            return [
                'total_processed' => $totalProcessed,
                'total_created' => $totalCreated,
                'total_enhanced' => $totalEnhanced,
                'total_failed' => $totalFailed,
                'total_impactful' => $totalImpactful,
                'actual_in_db' => $actualInDb,
                'overall_impact_rate' => $overallImpactRate
            ];
        } catch (\Exception $e) {
            Log::error("خطا در محاسبه آمار کتاب", ['error' => $e->getMessage()]);
            return [
                'total_processed' => 0, 'total_created' => 0, 'total_enhanced' => 0,
                'total_failed' => 0, 'total_impactful' => 0, 'actual_in_db' => 0,
                'overall_impact_rate' => 0
            ];
        }
    }

    private function getTodayStats(): array
    {
        try {
            $todayStats = ExecutionLog::whereDate('created_at', today())
                ->selectRaw('
                    SUM(total_processed) as today_processed,
                    SUM(total_success) as today_success,
                    SUM(total_enhanced) as today_enhanced,
                    SUM(total_failed) as today_failed
                ')
                ->first();

            return [
                'processed' => $todayStats->today_processed ?? 0,
                'created' => $todayStats->today_success ?? 0,
                'enhanced' => $todayStats->today_enhanced ?? 0,
                'failed' => $todayStats->today_failed ?? 0,
                'impactful' => ($todayStats->today_success ?? 0) + ($todayStats->today_enhanced ?? 0)
            ];
        } catch (\Exception $e) {
            Log::error("خطا در محاسبه آمار امروز", ['error' => $e->getMessage()]);
            return ['processed' => 0, 'created' => 0, 'enhanced' => 0, 'failed' => 0, 'impactful' => 0];
        }
    }

    private function getPerformanceStats(): array
    {
        try {
            $totalExecutions = ExecutionLog::count();
            $totalProcessed = ExecutionLog::sum('total_processed') ?: 0;
            $totalEnhanced = ExecutionLog::sum('total_enhanced') ?: 0;

            return [
                'avg_books_per_execution' => $totalExecutions > 0 ?
                    round($totalProcessed / $totalExecutions, 1) : 0,
                'enhancement_rate' => $totalProcessed > 0 ?
                    round(($totalEnhanced / $totalProcessed) * 100, 2) : 0,
                'creation_rate' => $totalProcessed > 0 ?
                    round((ExecutionLog::sum('total_success') / $totalProcessed) * 100, 2) : 0,
            ];
        } catch (\Exception $e) {
            Log::error("خطا در محاسبه آمار عملکرد", ['error' => $e->getMessage()]);
            return ['avg_books_per_execution' => 0, 'enhancement_rate' => 0, 'creation_rate' => 0];
        }
    }

    private function getEmptyStats(): array
    {
        return [
            'total_configs' => 0,
            'active_configs' => 0,
            'running_configs' => 0,
            'total_books' => 0,
            'configs' => ['total' => 0, 'active' => 0, 'inactive' => 0, 'running' => 0],
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
}
