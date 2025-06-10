<?php

namespace App\Services;

use App\Models\Config;
use App\Models\Book;
use App\Models\ExecutionLog;
use App\Models\ScrapingFailure;
use App\Services\BookProcessor;
use App\Services\ApiClient;
use Illuminate\Support\Facades\Log;

class ApiDataService
{
    private Config $config;
    private ApiClient $apiClient;
    private BookProcessor $bookProcessor;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->apiClient = new ApiClient($config);
        $this->bookProcessor = app(BookProcessor::class);
    }

    public function processSourceId(int $sourceId, ExecutionLog $executionLog): array
    {
        if ($sourceId === -1) {
            return $this->completeExecution($executionLog);
        }

        Log::info("🔍 پردازش source ID {$sourceId}", [
            'config_id' => $this->config->id,
            'execution_id' => $executionLog->execution_id
        ]);

        try {
            // بررسی تکراری بودن
            if ($this->bookProcessor->isSourceAlreadyProcessed($this->config->source_name, $sourceId)) {
                $executionLog->addLogEntry("⏭️ Source ID {$sourceId} قبلاً پردازش شده");

                $stats = [
                    'total_processed' => 1,
                    'total_success' => 0,
                    'total_failed' => 0,
                    'total_duplicate' => 1,
                    'total_enhanced' => 0
                ];

                $this->updateStats($executionLog, $stats);

                return $this->buildResult($sourceId, 'skipped', $stats);
            }

            // درخواست API
            $response = $this->apiClient->request($this->config->buildApiUrl($sourceId));

            if (!$response->successful()) {
                $this->logFailure($sourceId, "HTTP {$response->status()}: {$response->reason()}");

                $stats = [
                    'total_processed' => 1,
                    'total_success' => 0,
                    'total_failed' => 1,
                    'total_duplicate' => 0,
                    'total_enhanced' => 0
                ];

                $this->updateStats($executionLog, $stats);

                return $this->buildResult($sourceId, 'failed', $stats);
            }

            // استخراج داده‌ها
            $data = $response->json();
            if (empty($data)) {
                $this->logFailure($sourceId, 'پاسخ API خالی است');

                $stats = [
                    'total_processed' => 1,
                    'total_success' => 0,
                    'total_failed' => 1,
                    'total_duplicate' => 0,
                    'total_enhanced' => 0
                ];

                $this->updateStats($executionLog, $stats);

                return $this->buildResult($sourceId, 'no_book_found', $stats);
            }

            $bookData = $this->extractBookData($data, $sourceId);
            if (empty($bookData) || empty($bookData['title'])) {
                $this->logFailure($sourceId, 'ساختار کتاب در پاسخ API یافت نشد');

                $stats = [
                    'total_processed' => 1,
                    'total_success' => 0,
                    'total_failed' => 1,
                    'total_duplicate' => 0,
                    'total_enhanced' => 0
                ];

                $this->updateStats($executionLog, $stats);

                return $this->buildResult($sourceId, 'no_book_found', $stats);
            }

            // پردازش کتاب
            $result = $this->bookProcessor->processBook($bookData, $sourceId, $this->config, $executionLog);

            // بروزرسانی آمار از نتیجه BookProcessor
            if (isset($result['stats'])) {
                $this->updateStats($executionLog, $result['stats']);
            }

            return $result;

        } catch (\Exception $e) {
            Log::error("❌ خطا در پردازش source ID {$sourceId}", [
                'config_id' => $this->config->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $stats = [
                'total_processed' => 1,
                'total_success' => 0,
                'total_failed' => 1,
                'total_duplicate' => 0,
                'total_enhanced' => 0
            ];

            $this->updateStats($executionLog, $stats);

            return $this->buildResult($sourceId, 'failed', $stats);
        }
    }

    /**
     * بروزرسانی آمار ExecutionLog و Config
     */
    private function updateStats(ExecutionLog $executionLog, array $stats): void
    {
        try {
            // بروزرسانی ExecutionLog
            $executionLog->updateProgress($stats);

            // بروزرسانی Config
            $this->config->updateProgress($executionLog->id ?? 0, $stats);

            Log::debug("📊 آمار بروزرسانی شد", [
                'execution_id' => $executionLog->execution_id,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error("❌ خطا در بروزرسانی آمار", [
                'execution_id' => $executionLog->execution_id,
                'stats' => $stats,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function extractBookData(array $data, int $sourceId): array
    {
        // بررسی ساختار success/data/book
        if (isset($data['status']) && $data['status'] === 'success' && isset($data['data']['book'])) {
            return $data['data']['book'];
        }

        // بررسی ساختار مستقیم data/book
        if (isset($data['data']['book'])) {
            return $data['data']['book'];
        }

        // بررسی اینکه خود data یک کتاب است
        if (isset($data['id']) || isset($data['title'])) {
            return $data;
        }

        // بررسی کلیدهای احتمالی
        foreach (['data', 'book', 'result', 'item', 'response'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        return [];
    }

    private function buildResult(int $sourceId, string $action, array $stats, ?Book $book = null): array
    {
        $result = [
            'source_id' => $sourceId,
            'action' => $action,
            'stats' => $stats
        ];

        if ($book) {
            $result['book_id'] = $book->id;
            $result['title'] = $book->title;
        }

        return $result;
    }

    private function completeExecution(ExecutionLog $executionLog): array
    {
        try {
            // محاسبه آمار نهایی از ExecutionLog
            $finalStats = [
                'total_processed' => $executionLog->total_processed,
                'total_success' => $executionLog->total_success,
                'total_failed' => $executionLog->total_failed,
                'total_duplicate' => $executionLog->total_duplicate,
                'total_enhanced' => $executionLog->total_enhanced,
                'execution_time' => $executionLog->started_at ? now()->diffInSeconds($executionLog->started_at) : 0
            ];

            $executionLog->markCompleted($finalStats);
            $this->config->update(['is_running' => false]);

            Log::info("🎉 اجرا کامل شد", [
                'config_id' => $this->config->id,
                'execution_id' => $executionLog->execution_id,
                'final_stats' => $finalStats
            ]);

            return ['action' => 'completed', 'final_stats' => $finalStats];

        } catch (\Exception $e) {
            Log::error("❌ خطا در تکمیل اجرا", [
                'config_id' => $this->config->id,
                'execution_id' => $executionLog->execution_id,
                'error' => $e->getMessage()
            ]);

            return ['action' => 'completed_with_error'];
        }
    }

    private function logFailure(int $sourceId, string $reason): void
    {
        try {
            ScrapingFailure::logFailure(
                $this->config->id,
                $this->config->buildApiUrl($sourceId),
                "Source ID {$sourceId}: {$reason}",
                [
                    'source_id' => $sourceId,
                    'source_name' => $this->config->source_name,
                    'reason' => $reason,
                    'timestamp' => now()->toISOString()
                ],
                null,
                404
            );
        } catch (\Exception $e) {
            Log::error("❌ خطا در ثبت failure", [
                'source_id' => $sourceId,
                'reason' => $reason,
                'error' => $e->getMessage()
            ]);
        }
    }
}
