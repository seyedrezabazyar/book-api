<?php

namespace App\Services;

use App\Models\Config;
use App\Models\Book;
use App\Models\ExecutionLog;
use App\Services\BookProcessor;
use App\Services\ApiClient;
use Illuminate\Support\Facades\Log;

class ApiDataService
{
    public function __construct(
        private Config $config,
        private ApiClient $apiClient,
        private BookProcessor $bookProcessor
    ) {}

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
                return $this->buildResult($sourceId, 'skipped', ['total' => 0, 'success' => 0, 'failed' => 0, 'duplicate' => 1]);
            }

            // درخواست API
            $response = $this->apiClient->request($this->config->buildApiUrl($sourceId));

            if (!$response->successful()) {
                $this->logFailure($sourceId, "HTTP {$response->status()}: {$response->reason()}");
                return $this->buildResult($sourceId, 'failed', ['total' => 1, 'success' => 0, 'failed' => 1, 'duplicate' => 0]);
            }

            // استخراج داده‌ها
            $data = $response->json();
            if (empty($data)) {
                $this->logFailure($sourceId, 'پاسخ API خالی است');
                return $this->buildResult($sourceId, 'failed', ['total' => 1, 'success' => 0, 'failed' => 1, 'duplicate' => 0]);
            }

            $bookData = $this->extractBookData($data, $sourceId);
            if (empty($bookData) || empty($bookData['title'])) {
                $this->logFailure($sourceId, 'ساختار کتاب در پاسخ API یافت نشد');
                return $this->buildResult($sourceId, 'failed', ['total' => 1, 'success' => 0, 'failed' => 1, 'duplicate' => 0]);
            }

            // پردازش کتاب
            return $this->bookProcessor->processBook($bookData, $sourceId, $this->config, $executionLog);

        } catch (\Exception $e) {
            Log::error("❌ خطا در پردازش source ID {$sourceId}", [
                'config_id' => $this->config->id,
                'error' => $e->getMessage()
            ]);

            return $this->buildResult($sourceId, 'failed', ['total' => 1, 'success' => 0, 'failed' => 1, 'duplicate' => 0]);
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

        // بروزرسانی آمار کانفیگ
        $this->config->updateProgress($sourceId, $stats);

        return $result;
    }

    private function completeExecution(ExecutionLog $executionLog): array
    {
        $finalStats = [
            'total' => $this->config->total_processed,
            'success' => $this->config->total_success,
            'failed' => $this->config->total_failed,
            'execution_time' => $executionLog->started_at ? now()->diffInSeconds($executionLog->started_at) : 0
        ];

        $executionLog->markCompleted($finalStats);
        $this->config->update(['is_running' => false]);

        Log::info("🎉 اجرا کامل شد", [
            'config_id' => $this->config->id,
            'execution_id' => $executionLog->execution_id,
            'final_stats' => $finalStats
        ]);

        return ['action' => 'completed'];
    }

    private function logFailure(int $sourceId, string $reason): void
    {
        ScrapingFailure::create([
            'config_id' => $this->config->id,
            'url' => $this->config->buildApiUrl($sourceId),
            'error_message' => "Source ID {$sourceId}: {$reason}",
            'error_details' => [
                'source_id' => $sourceId,
                'source_name' => $this->config->source_name,
                'reason' => $reason
            ],
            'http_status' => 404,
            'retry_count' => 0,
            'last_attempt_at' => now()
        ]);
    }
}
