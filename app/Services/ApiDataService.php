<?php

namespace App\Services;

use App\Models\Config;
use App\Models\Book;
use App\Models\ExecutionLog;
use App\Models\FailedRequest;
use App\Models\MissingSource;
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
            'execution_id' => $executionLog->execution_id,
            'source_name' => $this->config->source_name
        ]);

        try {
            // 1. بررسی اولیه - آیا این source قبلاً پردازش شده؟
            $sourceProcessingResult = $this->bookProcessor->checkSourceProcessingStatus(
                $this->config->source_name,
                $sourceId,
                $this->config
            );

            if ($sourceProcessingResult['should_skip'] && !$sourceProcessingResult['needs_reprocessing']) {
                $executionLog->addLogEntry("⏭️ Source ID {$sourceId} رد شد", [
                    'reason' => $sourceProcessingResult['reason'],
                    'source_name' => $this->config->source_name,
                    'source_id' => $sourceId,
                    'book_id' => $sourceProcessingResult['book_id'] ?? null
                ]);

                $stats = $this->buildStats(1, 0, 0, 1, 0);
                $this->updateStats($executionLog, $stats);
                return $this->buildResult($sourceId, $sourceProcessingResult['action'], $stats);
            }

            // 2. بررسی اینکه آیا source قبلاً ناموجود بوده
            if (MissingSource::isMissing($this->config->id, $this->config->source_name, (string)$sourceId)) {
                $executionLog->addLogEntry("📭 Source ID {$sourceId} قبلاً ناموجود ثبت شده", [
                    'source_name' => $this->config->source_name,
                    'source_id' => $sourceId
                ]);

                $stats = $this->buildStats(1, 0, 1, 0, 0);
                $this->updateStats($executionLog, $stats);
                return $this->buildResult($sourceId, 'previously_missing', $stats);
            }

            // 3. درخواست API
            $apiResult = $this->makeApiRequest($sourceId, $executionLog);

            if (!$apiResult['success']) {
                return $this->handleApiFailure($sourceId, $apiResult, $executionLog);
            }

            // 4. استخراج و اعتبارسنجی داده‌های کتاب
            $data = $apiResult['data'];
            $bookData = $this->extractBookData($data, $sourceId);

            if (empty($bookData) || empty($bookData['title'])) {
                return $this->handleNoBookFound($sourceId, $data, $executionLog);
            }

            // 5. پردازش کتاب
            $result = $this->bookProcessor->processBook(
                $bookData,
                $sourceId,
                $this->config,
                $executionLog,
                $sourceProcessingResult
            );

            // 6. اگر کتاب موجود بود، از لیست ناموجود حذف کن
            if ($this->isProcessingSuccessful($result)) {
                MissingSource::markAsFound($this->config->id, $this->config->source_name, (string)$sourceId);
            }

            // 7. بروزرسانی آمار
            if (isset($result['stats'])) {
                $this->updateStats($executionLog, $result['stats']);
            }

            $this->logFinalResult($sourceId, $result, $executionLog);
            return $result;

        } catch (\Exception $e) {
            return $this->handleProcessingException($sourceId, $e, $executionLog);
        }
    }

    /**
     * درخواست API ساده‌شده
     */
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

                return [
                    'success' => true,
                    'data' => $data,
                    'status_code' => $response->status()
                ];
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

    /**
     * مدیریت شکست API
     */
    private function handleApiFailure(int $sourceId, array $apiResult, ExecutionLog $executionLog): array
    {
        $errorMessage = $apiResult['error'];
        $statusCode = $apiResult['status_code'];

        // اگر 404 بود، source رو ناموجود ثبت کن
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

            $stats = $this->buildStats(1, 0, 1, 0, 0);
            $this->updateStats($executionLog, $stats);
            return $this->buildResult($sourceId, 'not_found', $stats);
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

        $stats = $this->buildStats(1, 0, 1, 0, 0);
        $this->updateStats($executionLog, $stats);
        return $this->buildResult($sourceId, 'api_failed', $stats);
    }

    /**
     * مدیریت عدم یافتن کتاب در پاسخ
     */
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

        $stats = $this->buildStats(1, 0, 1, 0, 0);
        $this->updateStats($executionLog, $stats);
        return $this->buildResult($sourceId, 'no_book_found', $stats);
    }

    /**
     * استخراج داده‌های کتاب از پاسخ API
     */
    private function extractBookData(array $data, int $sourceId): array
    {
        // بررسی ساختارهای مختلف پاسخ
        $possiblePaths = [
            'data.book',
            'book',
            'data',
            'result.book',
            'response.book'
        ];

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

    /**
     * بررسی موفقیت‌آمیز بودن پردازش
     */
    private function isProcessingSuccessful(array $result): bool
    {
        $stats = $result['stats'] ?? [];
        return ($stats['total_success'] ?? 0) > 0 || ($stats['total_enhanced'] ?? 0) > 0;
    }

    /**
     * لاگ نتیجه نهایی
     */
    private function logFinalResult(int $sourceId, array $result, ExecutionLog $executionLog): void
    {
        $action = $result['action'] ?? 'unknown';
        $stats = $result['stats'] ?? [];

        $logData = [
            'source_id' => $sourceId,
            'action' => $action,
            'stats' => $stats
        ];

        if (isset($result['book_id'])) {
            $logData['book_id'] = $result['book_id'];
            $logData['title'] = $result['title'] ?? 'N/A';
        }

        $actionEmojis = [
            'created' => '🆕',
            'enhanced' => '🔧',
            'enriched' => '💎',
            'merged' => '🔗',
            'already_processed' => '📋',
            'source_added' => '📌',
            'no_changes' => '⚪',
            'not_found' => '📭',
            'previously_missing' => '🔍'
        ];

        $emoji = $actionEmojis[$action] ?? '❓';
        $executionLog->addLogEntry("{$emoji} پردازش source ID {$sourceId} تمام شد", $logData);
    }

    /**
     * مدیریت خطای پردازش
     */
    private function handleProcessingException(int $sourceId, \Exception $e, ExecutionLog $executionLog): array
    {
        Log::error("❌ خطا در پردازش source ID {$sourceId}", [
            'config_id' => $this->config->id,
            'error' => $e->getMessage()
        ]);

        // ثبت در FailedRequest
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

        $stats = $this->buildStats(1, 0, 1, 0, 0);
        $this->updateStats($executionLog, $stats);
        return $this->buildResult($sourceId, 'processing_failed', $stats);
    }

    /**
     * ساخت آمار استاندارد
     */
    private function buildStats(int $processed, int $success, int $failed, int $duplicate, int $enhanced): array
    {
        return [
            'total_processed' => $processed,
            'total_success' => $success,
            'total_failed' => $failed,
            'total_duplicate' => $duplicate,
            'total_enhanced' => $enhanced
        ];
    }

    /**
     * بروزرسانی آمار
     */
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

    /**
     * ساخت نتیجه استاندارد
     */
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

    /**
     * تکمیل اجرا
     */
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

            // گزارش missing sources
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
