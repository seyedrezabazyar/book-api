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
                'total_configs' => Config::count(),
                'active_configs' => Config::where('is_active', true)->count(),
                'running_configs' => Config::where('is_running', true)->count(),
                'total_books' => Book::count(),
                'total_sources' => $this->getTotalSources(),
                'configs' => $this->getConfigStats(),
                'executions' => $this->getExecutionStats(),
                'books' => $this->getBookStats(),
                'today' => $this->getTodayStats(),
                'performance' => $this->getPerformanceStats(),
                'enhancement' => $this->getEnhancementStats()
            ];
        } catch (\Exception $e) {
            Log::error("خطا در محاسبه آمار سیستم", ['error' => $e->getMessage()]);
            return $this->getEmptyStats();
        }
    }

    private function getTotalSources(): int
    {
        try {
            return BookSource::distinct('source_name')->count();
        } catch (\Exception $e) {
            return 0;
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
            $stoppedExecutions = ExecutionLog::where('status', 'stopped')->count();
            $runningExecutions = ExecutionLog::where('status', 'running')->count();
            $failedExecutions = ExecutionLog::where('status', 'failed')->count();

            return [
                'total' => $totalExecutions,
                'successful' => $successfulExecutions,
                'stopped' => $stoppedExecutions,
                'running' => $runningExecutions,
                'failed' => $failedExecutions,
                'success_rate' => $totalExecutions > 0 ?
                    round((($successfulExecutions + $stoppedExecutions) / $totalExecutions) * 100, 2) : 0
            ];
        } catch (\Exception $e) {
            Log::error("خطا در محاسبه آمار اجرا", ['error' => $e->getMessage()]);
            return [
                'total' => 0, 'successful' => 0, 'stopped' => 0,
                'running' => 0, 'failed' => 0, 'success_rate' => 0
            ];
        }
    }

    private function getBookStats(): array
    {
        try {
            $totalProcessed = ExecutionLog::whereIn('status', ['completed', 'stopped'])
                ->sum('total_processed') ?: 0;
            $totalCreated = ExecutionLog::whereIn('status', ['completed', 'stopped'])
                ->sum('total_success') ?: 0;
            $totalEnhanced = ExecutionLog::whereIn('status', ['completed', 'stopped'])
                ->sum('total_enhanced') ?: 0;
            $totalFailed = ExecutionLog::whereIn('status', ['completed', 'stopped'])
                ->sum('total_failed') ?: 0;
            $totalDuplicate = ExecutionLog::whereIn('status', ['completed', 'stopped'])
                ->sum('total_duplicate') ?: 0;

            $actualInDb = Book::count();

            $totalImpactful = $totalCreated + $totalEnhanced;
            $overallImpactRate = $totalProcessed > 0 ?
                round(($totalImpactful / $totalProcessed) * 100, 2) : 0;

            $enhancementRate = $totalProcessed > 0 ?
                round(($totalEnhanced / $totalProcessed) * 100, 2) : 0;

            $creationRate = $totalProcessed > 0 ?
                round(($totalCreated / $totalProcessed) * 100, 2) : 0;

            return [
                'total_processed' => $totalProcessed,
                'total_created' => $totalCreated,
                'total_enhanced' => $totalEnhanced,
                'total_failed' => $totalFailed,
                'total_duplicate' => $totalDuplicate,
                'total_impactful' => $totalImpactful,
                'actual_in_db' => $actualInDb,
                'overall_impact_rate' => $overallImpactRate,
                'enhancement_rate' => $enhancementRate,
                'creation_rate' => $creationRate,
                'duplicate_rate' => $totalProcessed > 0 ?
                    round(($totalDuplicate / $totalProcessed) * 100, 2) : 0,
                'failure_rate' => $totalProcessed > 0 ?
                    round(($totalFailed / $totalProcessed) * 100, 2) : 0
            ];
        } catch (\Exception $e) {
            Log::error("خطا در محاسبه آمار کتاب", ['error' => $e->getMessage()]);
            return [
                'total_processed' => 0, 'total_created' => 0, 'total_enhanced' => 0,
                'total_failed' => 0, 'total_duplicate' => 0, 'total_impactful' => 0,
                'actual_in_db' => 0, 'overall_impact_rate' => 0, 'enhancement_rate' => 0,
                'creation_rate' => 0, 'duplicate_rate' => 0, 'failure_rate' => 0
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
                    SUM(total_failed) as today_failed,
                    SUM(total_duplicate) as today_duplicate
                ')
                ->first();

            $todayImpactful = ($todayStats->today_success ?? 0) + ($todayStats->today_enhanced ?? 0);

            return [
                'processed' => $todayStats->today_processed ?? 0,
                'created' => $todayStats->today_success ?? 0,
                'enhanced' => $todayStats->today_enhanced ?? 0,
                'failed' => $todayStats->today_failed ?? 0,
                'duplicate' => $todayStats->today_duplicate ?? 0,
                'impactful' => $todayImpactful,
                'impact_rate' => ($todayStats->today_processed ?? 0) > 0 ?
                    round(($todayImpactful / $todayStats->today_processed) * 100, 2) : 0
            ];
        } catch (\Exception $e) {
            Log::error("خطا در محاسبه آمار امروز", ['error' => $e->getMessage()]);
            return [
                'processed' => 0, 'created' => 0, 'enhanced' => 0,
                'failed' => 0, 'duplicate' => 0, 'impactful' => 0, 'impact_rate' => 0
            ];
        }
    }

    /**
     * آمار بهبود و غنی‌سازی
     */
    private function getEnhancementStats(): array
    {
        try {
            $enhancementStats = ExecutionLog::whereIn('status', ['completed', 'stopped'])
                ->where('total_enhanced', '>', 0)
                ->selectRaw('
                    COUNT(*) as executions_with_enhancements,
                    SUM(total_enhanced) as total_enhanced,
                    AVG(total_enhanced) as avg_enhanced_per_run,
                    MAX(total_enhanced) as max_enhanced_per_run
                ')
                ->first();

            $totalExecutions = ExecutionLog::whereIn('status', ['completed', 'stopped'])->count();
            $enhancementPercentage = $totalExecutions > 0 ?
                round(($enhancementStats->executions_with_enhancements / $totalExecutions) * 100, 2) : 0;

            return [
                'total_enhanced' => $enhancementStats->total_enhanced ?? 0,
                'executions_with_enhancements' => $enhancementStats->executions_with_enhancements ?? 0,
                'enhancement_percentage' => $enhancementPercentage,
                'avg_enhanced_per_run' => round($enhancementStats->avg_enhanced_per_run ?? 0, 1),
                'max_enhanced_per_run' => $enhancementStats->max_enhanced_per_run ?? 0
            ];
        } catch (\Exception $e) {
            Log::error("خطا در محاسبه آمار بهبود", ['error' => $e->getMessage()]);
            return [
                'total_enhanced' => 0, 'executions_with_enhancements' => 0,
                'enhancement_percentage' => 0, 'avg_enhanced_per_run' => 0, 'max_enhanced_per_run' => 0
            ];
        }
    }

    private function getPerformanceStats(): array
    {
        try {
            $totalExecutions = ExecutionLog::whereIn('status', ['completed', 'stopped'])->count();
            $totalProcessed = ExecutionLog::whereIn('status', ['completed', 'stopped'])
                ->sum('total_processed') ?: 0;
            $totalEnhanced = ExecutionLog::whereIn('status', ['completed', 'stopped'])
                ->sum('total_enhanced') ?: 0;
            $totalCreated = ExecutionLog::whereIn('status', ['completed', 'stopped'])
                ->sum('total_success') ?: 0;

            // محاسبه میانگین زمان اجرا
            $avgExecutionTime = ExecutionLog::whereIn('status', ['completed', 'stopped'])
                ->where('execution_time', '>', 0)
                ->avg('execution_time') ?: 0;

            // محاسبه سرعت پردازش
            $totalExecutionTime = ExecutionLog::whereIn('status', ['completed', 'stopped'])
                ->sum('execution_time') ?: 1; // جلوگیری از تقسیم بر صفر
            $avgRecordsPerSecond = $totalExecutionTime > 0 ?
                round($totalProcessed / $totalExecutionTime, 2) : 0;

            return [
                'avg_books_per_execution' => $totalExecutions > 0 ?
                    round($totalProcessed / $totalExecutions, 1) : 0,
                'enhancement_rate' => $totalProcessed > 0 ?
                    round(($totalEnhanced / $totalProcessed) * 100, 2) : 0,
                'creation_rate' => $totalProcessed > 0 ?
                    round(($totalCreated / $totalProcessed) * 100, 2) : 0,
                'avg_execution_time_seconds' => round($avgExecutionTime, 1),
                'avg_records_per_second' => $avgRecordsPerSecond,
                'total_execution_hours' => round($totalExecutionTime / 3600, 1)
            ];
        } catch (\Exception $e) {
            Log::error("خطا در محاسبه آمار عملکرد", ['error' => $e->getMessage()]);
            return [
                'avg_books_per_execution' => 0, 'enhancement_rate' => 0, 'creation_rate' => 0,
                'avg_execution_time_seconds' => 0, 'avg_records_per_second' => 0, 'total_execution_hours' => 0
            ];
        }
    }

    /**
     * آمار تفصیلی منابع
     */
    public function getSourcesStats(): array
    {
        try {
            $sourceStats = BookSource::select('source_name')
                ->selectRaw('COUNT(*) as total_records')
                ->selectRaw('COUNT(DISTINCT book_id) as unique_books')
                ->selectRaw('MIN(CAST(source_id AS UNSIGNED)) as first_id')
                ->selectRaw('MAX(CAST(source_id AS UNSIGNED)) as last_id')
                ->groupBy('source_name')
                ->orderBy('total_records', 'desc')
                ->get();

            return $sourceStats->map(function ($source) {
                return [
                    'source_name' => $source->source_name,
                    'total_records' => $source->total_records,
                    'unique_books' => $source->unique_books,
                    'id_range' => $source->first_id && $source->last_id ?
                        "{$source->first_id}-{$source->last_id}" : 'N/A',
                    'coverage' => $source->first_id && $source->last_id ?
                        round(($source->total_records / ($source->last_id - $source->first_id + 1)) * 100, 1) : 0
                ];
            })->toArray();

        } catch (\Exception $e) {
            Log::error("خطا در محاسبه آمار منابع", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * آمار هش‌ها
     */
    public function getHashStats(): array
    {
        try {
            $totalBooks = Book::count();
            $booksWithHashes = Book::whereHas('hashes')->count();

            $hashStats = \Illuminate\Support\Facades\DB::table('book_hashes')
                ->selectRaw('
                    COUNT(CASE WHEN md5 IS NOT NULL THEN 1 END) as md5_count,
                    COUNT(CASE WHEN sha1 IS NOT NULL THEN 1 END) as sha1_count,
                    COUNT(CASE WHEN sha256 IS NOT NULL THEN 1 END) as sha256_count,
                    COUNT(CASE WHEN crc32 IS NOT NULL THEN 1 END) as crc32_count,
                    COUNT(CASE WHEN ed2k_hash IS NOT NULL THEN 1 END) as ed2k_count,
                    COUNT(CASE WHEN btih IS NOT NULL THEN 1 END) as btih_count,
                    COUNT(CASE WHEN magnet_link IS NOT NULL THEN 1 END) as magnet_count
                ')
                ->first();

            return [
                'total_books' => $totalBooks,
                'books_with_hashes' => $booksWithHashes,
                'hash_coverage' => $totalBooks > 0 ? round(($booksWithHashes / $totalBooks) * 100, 2) : 0,
                'hash_types' => [
                    'md5' => $hashStats->md5_count ?? 0,
                    'sha1' => $hashStats->sha1_count ?? 0,
                    'sha256' => $hashStats->sha256_count ?? 0,
                    'crc32' => $hashStats->crc32_count ?? 0,
                    'ed2k' => $hashStats->ed2k_count ?? 0,
                    'btih' => $hashStats->btih_count ?? 0,
                    'magnet' => $hashStats->magnet_count ?? 0,
                ]
            ];
        } catch (\Exception $e) {
            Log::error("خطا در محاسبه آمار هش‌ها", ['error' => $e->getMessage()]);
            return [
                'total_books' => 0, 'books_with_hashes' => 0, 'hash_coverage' => 0,
                'hash_types' => [
                    'md5' => 0, 'sha1' => 0, 'sha256' => 0, 'crc32' => 0,
                    'ed2k' => 0, 'btih' => 0, 'magnet' => 0
                ]
            ];
        }
    }

    private function getEmptyStats(): array
    {
        return [
            'total_configs' => 0,
            'active_configs' => 0,
            'running_configs' => 0,
            'total_books' => 0,
            'total_sources' => 0,
            'configs' => ['total' => 0, 'active' => 0, 'inactive' => 0, 'running' => 0],
            'executions' => [
                'total' => 0, 'successful' => 0, 'stopped' => 0,
                'running' => 0, 'failed' => 0, 'success_rate' => 0
            ],
            'books' => [
                'total_processed' => 0, 'total_created' => 0, 'total_enhanced' => 0,
                'total_failed' => 0, 'total_duplicate' => 0, 'total_impactful' => 0,
                'actual_in_db' => 0, 'overall_impact_rate' => 0, 'enhancement_rate' => 0,
                'creation_rate' => 0, 'duplicate_rate' => 0, 'failure_rate' => 0
            ],
            'today' => [
                'processed' => 0, 'created' => 0, 'enhanced' => 0,
                'failed' => 0, 'duplicate' => 0, 'impactful' => 0, 'impact_rate' => 0
            ],
            'performance' => [
                'avg_books_per_execution' => 0, 'enhancement_rate' => 0, 'creation_rate' => 0,
                'avg_execution_time_seconds' => 0, 'avg_records_per_second' => 0, 'total_execution_hours' => 0
            ],
            'enhancement' => [
                'total_enhanced' => 0, 'executions_with_enhancements' => 0,
                'enhancement_percentage' => 0, 'avg_enhanced_per_run' => 0, 'max_enhanced_per_run' => 0
            ]
        ];
    }
}
