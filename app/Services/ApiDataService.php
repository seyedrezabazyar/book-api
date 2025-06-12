<?php

namespace App\Services;

use App\Models\Config;
use App\Models\Book;
use App\Models\ExecutionLog;
use App\Models\FailedRequest;
use App\Models\MissingSource;
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
            'source_name' => $this->config->source_name
        ]);

        try {
            // 1. بررسی اولیه
            $sourceStatus = $this->bookProcessor->checkSourceProcessingStatus(
                $this->config->source_name,
                $sourceId,
                $this->config
            );

            if ($sourceStatus['should_skip'] && !$sourceStatus['needs_reprocessing']) {
                $executionLog->addLogEntry("⏭️ Source ID {$sourceId} رد شد", [
                    'reason' => $sourceStatus['reason'],
                    'book_id' => $sourceStatus['book_id'] ?? null
                ]);

                return $this->buildResult($sourceId, $sourceStatus['action'], [
                    'total_processed' => 1, 'total_success' => 0, 'total_failed' => 0,
                    'total_duplicate' => 1, 'total_enhanced' => 0
                ]);
            }

            // 2. بررسی missing source
            if (MissingSource::isMissing($this->config->id, $this->config->source_name, (string)$sourceId)) {
                $executionLog->addLogEntry("📭 Source ID {$sourceId} قبلاً ناموجود ثبت شده", [
                    'source_id' => $sourceId
                ]);

                return $this->buildResult($sourceId, 'previously_missing', [
                    'total_processed' => 1, 'total_success' => 0, 'total_failed' => 1,
                    'total_duplicate' => 0, 'total_enhanced' => 0
                ]);
            }

            // 3. درخواست API
            $apiResult = $this->makeApiRequest($sourceId, $executionLog);
            if (!$apiResult['success']) {
                return $this->handleApiFailure($sourceId, $apiResult, $executionLog);
            }

            // 4. استخراج داده‌های کتاب
            $bookData = $this->extractBookData($apiResult['data'], $sourceId);
            if (empty($bookData) || empty($bookData['title'])) {
                return $this->handleNoBookFound($sourceId, $apiResult['data'], $executionLog);
            }

            // 5. پردازش کتاب
            $result = $this->bookProcessor->processBook(
                $bookData,
                $sourceId,
                $this->config,
                $executionLog,
                $sourceStatus
            );

            // 6. حذف از لیست missing در صورت موفقیت
            if ($this->isProcessingSuccessful($result)) {
                MissingSource::markAsFound($this->config->id, $this->config->source_name, (string)$sourceId);
            }

            // 7. بروزرسانی آمار
            if (isset($result['stats'])) {
                $this->updateStats($executionLog, $result['stats']);
            }

            $this->logResult($sourceId, $result, $executionLog);
            return $result;

        } catch (\Exception $e) {
            return $this->handleException($sourceId, $e, $executionLog);
        }
    }

    private function makeApiRequest(int $sourceId, ExecutionLog $executionLog): array
    {
        $url = $this->config->buildApiUrl($sourceId);

        $executionLog->addLogEntry("🌐 درخواست API", [
            'source_id' => $sourceId,
            'url' => $url
        ]);

        try {
            $response = $this->apiClient->request($url);

            if ($response->successful()) {
                $data = $response->json();
                if (empty($data)) {
                    throw new \Exception('پاسخ API خالی است');
                }
                return ['success' => true, 'data' => $data, 'status_code' => $response->status()];
            }

            return [
                'success' => false,
                'error' => "HTTP {$response->status()}: {$response->reason()}",
                'status_code' => $response->status(),
                'is_404' => $response->status() === 404
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => null
            ];
        }
    }

    private function extractBookData(array $data, int $sourceId): array
    {
        // بررسی ساختارهای مختلف پاسخ
        $possiblePaths = ['data.book', 'book', 'data', 'result.book', 'response.book'];

        foreach ($possiblePaths as $path) {
            $bookData = $this->getNestedValue($data, $path);
            if ($bookData && is_array($bookData) && (isset($bookData['title']) || isset($bookData['id']))) {
                return $bookData;
            }
        }

        // اگر خود data یک کتاب است
        if (isset($data['title']) || isset($data['id'])) {
            return $data;
        }

        return [];
    }

    private function getNestedValue(array $data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } else {
                return null;
            }
        }

        return $value;
    }

    private function handleApiFailure(int $sourceId, array $apiResult, ExecutionLog $executionLog): array
    {
        $errorMessage = $apiResult['error'];
        $statusCode = $apiResult['status_code'];

        if ($apiResult['is_404'] ?? false) {
            MissingSource::recordMissing(
                $this->config->id,
                $this->config->source_name,
                (string)$sourceId,
                'not_found',
                'کتاب در API یافت نشد (404)',
                404
            );

            $executionLog->addLogEntry("📭 Source ID {$sourceId} ناموجود (404)", [
                'url' => $this->config->buildApiUrl($sourceId)
            ]);

            return $this->buildResult($sourceId, 'not_found', [
                'total_processed' => 1, 'total_success' => 0, 'total_failed' => 1,
                'total_duplicate' => 0, 'total_enhanced' => 0
            ]);
        }

        // سایر خطاهای API
        FailedRequest::recordFailure(
            $this->config->id,
            $this->config->source_name,
            (string)$sourceId,
            $this->config->buildApiUrl($sourceId),
            $errorMessage,
            $statusCode
        );

        $executionLog->addLogEntry("💥 خطای API", [
            'source_id' => $sourceId,
            'error' => $errorMessage,
            'status_code' => $statusCode
        ]);

        return $this->buildResult($sourceId, 'api_failed', [
            'total_processed' => 1, 'total_success' => 0, 'total_failed' => 1,
            'total_duplicate' => 0, 'total_enhanced' => 0
        ]);
    }

    private function handleNoBookFound(int $sourceId, array $data, ExecutionLog $executionLog): array
    {
        MissingSource::recordMissing(
            $this->config->id,
            $this->config->source_name,
            (string)$sourceId,
            'invalid_data',
            'ساختار کتاب در پاسخ API یافت نشد یا عنوان خالی است',
            200
        );

        $executionLog->addLogEntry("📭 کتاب در پاسخ API یافت نشد", [
            'source_id' => $sourceId,
            'response_keys' => array_keys($data)
        ]);

        return $this->buildResult($sourceId, 'no_book_found', [
            'total_processed' => 1, 'total_success' => 0, 'total_failed' => 1,
            'total_duplicate' => 0, 'total_enhanced' => 0
        ]);
    }

    private function handleException(int $sourceId, \Exception $e, ExecutionLog $executionLog): array
    {
        Log::error("❌ خطا در پردازش source ID {$sourceId}", [
            'config_id' => $this->config->id,
            'error' => $e->getMessage()
        ]);

        FailedRequest::recordFailure(
            $this->config->id,
            $this->config->source_name,
            (string)$sourceId,
            $this->config->buildApiUrl($sourceId),
            'خطای کلی: ' . $e->getMessage()
        );

        $executionLog->addLogEntry("💥 خطای کلی", [
            'source_id' => $sourceId,
            'error' => $e->getMessage()
        ]);

        return $this->buildResult($sourceId, 'processing_failed', [
            'total_processed' => 1, 'total_success' => 0, 'total_failed' => 1,
            'total_duplicate' => 0, 'total_enhanced' => 0
        ]);
    }

    private function isProcessingSuccessful(array $result): bool
    {
        $stats = $result['stats'] ?? [];
        return ($stats['total_success'] ?? 0) > 0 || ($stats['total_enhanced'] ?? 0) > 0;
    }

    private function logResult(int $sourceId, array $result, ExecutionLog $executionLog): void
    {
        $action = $result['action'] ?? 'unknown';
        $actionEmojis = [
            'created' => '🆕', 'enhanced' => '🔧', 'enriched' => '💎',
            'merged' => '🔗', 'already_processed' => '📋', 'source_added' => '📌',
            'no_changes' => '⚪', 'not_found' => '📭', 'previously_missing' => '🔍'
        ];

        $emoji = $actionEmojis[$action] ?? '❓';
        $executionLog->addLogEntry("{$emoji} پردازش source ID {$sourceId} تمام شد", [
            'source_id' => $sourceId,
            'action' => $action,
            'book_id' => $result['book_id'] ?? null,
            'title' => $result['title'] ?? null
        ]);
    }

    private function updateStats(ExecutionLog $executionLog, array $stats): void
    {
        try {
            $executionLog->updateProgress($stats);
            $this->config->updateProgress($executionLog->id ?? 0, $stats);
        } catch (\Exception $e) {
            Log::error("❌ خطا در بروزرسانی آمار", [
                'execution_id' => $executionLog->execution_id,
                'error' => $e->getMessage()
            ]);
        }
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

            $missingStats = MissingSource::getStatsForConfig($this->config->id);

            Log::info("🏁 اجرا تمام شد", [
                'config_id' => $this->config->id,
                'execution_id' => $executionLog->execution_id,
                'final_stats' => $finalStats,
                'missing_sources' => $missingStats
            ]);

            return [
                'action' => 'completed',
                'final_stats' => $finalStats,
                'missing_sources' => $missingStats
            ];

        } catch (\Exception $e) {
            Log::error("❌ خطا در تکمیل اجرا", [
                'config_id' => $this->config->id,
                'error' => $e->getMessage()
            ]);

            return ['action' => 'completed_with_error'];
        }
    }
}
