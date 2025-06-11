<?php

namespace App\Services;

use App\Models\Config;
use App\Models\Book;
use App\Models\ExecutionLog;
use App\Models\ScrapingFailure;
use App\Models\FailedRequest;
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

        Log::info("🔍 پردازش source ID {$sourceId} با منطق بهبود یافته", [
            'config_id' => $this->config->id,
            'execution_id' => $executionLog->execution_id,
            'source_name' => $this->config->source_name
        ]);

        try {
            // 1. بررسی اولیه - آیا این source قبلاً پردازش شده؟
            if ($this->bookProcessor->isSourceAlreadyProcessed($this->config->source_name, $sourceId)) {
                $executionLog->addLogEntry("⏭️ Source ID {$sourceId} قبلاً پردازش شده", [
                    'reason' => 'source_already_in_book_sources',
                    'source_name' => $this->config->source_name,
                    'source_id' => $sourceId
                ]);

                $stats = $this->buildStats(1, 0, 0, 1, 0);
                $this->updateStats($executionLog, $stats);
                return $this->buildResult($sourceId, 'already_processed', $stats);
            }

            // 2. بررسی FailedRequest - آیا این source قبلاً ناموفق بوده؟
            $existingFailure = FailedRequest::where('config_id', $this->config->id)
                ->where('source_name', $this->config->source_name)
                ->where('source_id', (string)$sourceId)
                ->where('is_resolved', false)
                ->first();

            if ($existingFailure && !$existingFailure->shouldRetry()) {
                $executionLog->addLogEntry("❌ Source ID {$sourceId} حداکثر تلاش رسیده", [
                    'retry_count' => $existingFailure->retry_count,
                    'max_retries' => FailedRequest::MAX_RETRY_COUNT,
                    'first_failed_at' => $existingFailure->first_failed_at
                ]);

                $stats = $this->buildStats(1, 0, 1, 0, 0);
                $this->updateStats($executionLog, $stats);
                return $this->buildResult($sourceId, 'max_retries_reached', $stats);
            }

            // 3. درخواست API با منطق retry
            $apiResult = $this->makeApiRequestWithRetry($sourceId, $executionLog);

            if (!$apiResult['success']) {
                // ثبت یا بروزرسانی failure
                FailedRequest::recordFailure(
                    $this->config->id,
                    $this->config->source_name,
                    (string)$sourceId,
                    $this->config->buildApiUrl($sourceId),
                    $apiResult['error'],
                    $apiResult['http_status'] ?? null,
                    $apiResult['details'] ?? []
                );

                $executionLog->addLogEntry("💥 خطا در درخواست API", [
                    'source_id' => $sourceId,
                    'error' => $apiResult['error'],
                    'http_status' => $apiResult['http_status'] ?? null,
                    'attempt' => $apiResult['attempt'] ?? 1
                ]);

                $stats = $this->buildStats(1, 0, 1, 0, 0);
                $this->updateStats($executionLog, $stats);
                return $this->buildResult($sourceId, 'api_failed', $stats);
            }

            // 4. استخراج و اعتبارسنجی داده‌های کتاب
            $data = $apiResult['data'];
            $bookData = $this->extractBookData($data, $sourceId);

            if (empty($bookData) || empty($bookData['title'])) {
                // ثبت به عنوان "کتاب یافت نشد"
                FailedRequest::recordFailure(
                    $this->config->id,
                    $this->config->source_name,
                    (string)$sourceId,
                    $this->config->buildApiUrl($sourceId),
                    'ساختار کتاب در پاسخ API یافت نشد یا عنوان خالی است',
                    200,
                    ['response_structure' => array_keys($data)]
                );

                $executionLog->addLogEntry("📭 کتاب در API یافت نشد", [
                    'source_id' => $sourceId,
                    'response_keys' => array_keys($data),
                    'extracted_title' => $bookData['title'] ?? 'N/A'
                ]);

                $stats = $this->buildStats(1, 0, 1, 0, 0);
                $this->updateStats($executionLog, $stats);
                return $this->buildResult($sourceId, 'no_book_found', $stats);
            }

            // 5. پردازش کتاب با منطق بهبود یافته
            $result = $this->bookProcessor->processBook($bookData, $sourceId, $this->config, $executionLog);

            // 6. اگر پردازش موفق بود، failure موجود را حل شده علامت‌گذاری کن
            if ($this->isProcessingSuccessful($result)) {
                if ($existingFailure) {
                    $existingFailure->markAsResolved();
                    $executionLog->addLogEntry("✅ FailedRequest حل شد", [
                        'source_id' => $sourceId,
                        'retry_count' => $existingFailure->retry_count
                    ]);
                }
            }

            // 7. بروزرسانی آمار
            if (isset($result['stats'])) {
                $this->updateStats($executionLog, $result['stats']);
            }

            // 8. لاگ نتیجه نهایی
            $this->logFinalResult($sourceId, $result, $executionLog);

            return $result;

        } catch (\Exception $e) {
            return $this->handleProcessingException($sourceId, $e, $executionLog);
        }
    }

    /**
     * درخواست API با منطق retry بهبود یافته
     */
    private function makeApiRequestWithRetry(int $sourceId, ExecutionLog $executionLog): array
    {
        $url = $this->config->buildApiUrl($sourceId);
        $maxRetries = 3;
        $retryDelays = [2, 5, 10]; // تاخیر افزایشی

        $executionLog->addLogEntry("🌐 شروع درخواست API", [
            'source_id' => $sourceId,
            'url' => $url,
            'max_retries' => $maxRetries
        ]);

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                Log::debug("🔄 تلاش {$attempt}/{$maxRetries}", [
                    'source_id' => $sourceId,
                    'url' => $url
                ]);

                $response = $this->apiClient->request($url);

                if ($response->successful()) {
                    $data = $response->json();

                    if (empty($data)) {
                        throw new \Exception('پاسخ API خالی است');
                    }

                    $executionLog->addLogEntry("✅ درخواست API موفق", [
                        'source_id' => $sourceId,
                        'attempt' => $attempt,
                        'response_size' => strlen($response->body()),
                        'status_code' => $response->status()
                    ]);

                    return [
                        'success' => true,
                        'data' => $data,
                        'attempt' => $attempt,
                        'status_code' => $response->status()
                    ];
                }

                // خطای HTTP
                $errorMessage = "HTTP {$response->status()}: {$response->reason()}";

                // برای 404 دیگر retry نکن
                if ($response->status() === 404) {
                    $executionLog->addLogEntry("🚫 کتاب یافت نشد (404)", [
                        'source_id' => $sourceId,
                        'attempt' => $attempt
                    ]);

                    return [
                        'success' => false,
                        'error' => 'کتاب با این ID یافت نشد (404)',
                        'http_status' => 404,
                        'attempt' => $attempt,
                        'should_retry' => false
                    ];
                }

                // اگر آخرین تلاش است
                if ($attempt === $maxRetries) {
                    return [
                        'success' => false,
                        'error' => $errorMessage,
                        'http_status' => $response->status(),
                        'attempt' => $attempt,
                        'details' => ['final_attempt_failed' => true]
                    ];
                }

                $executionLog->addLogEntry("⚠️ تلاش {$attempt} ناموفق، retry در {$retryDelays[$attempt-1]} ثانیه", [
                    'source_id' => $sourceId,
                    'status_code' => $response->status(),
                    'error' => $errorMessage
                ]);

            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();

                // اگر آخرین تلاش است
                if ($attempt === $maxRetries) {
                    return [
                        'success' => false,
                        'error' => $errorMessage,
                        'attempt' => $attempt,
                        'details' => [
                            'exception_type' => get_class($e),
                            'final_attempt_failed' => true
                        ]
                    ];
                }

                $executionLog->addLogEntry("💥 Exception در تلاش {$attempt}", [
                    'source_id' => $sourceId,
                    'error' => $errorMessage,
                    'retry_in_seconds' => $retryDelays[$attempt-1]
                ]);
            }

            // تاخیر قبل از تلاش بعدی
            if ($attempt < $maxRetries) {
                $delaySeconds = $retryDelays[$attempt-1];
                sleep($delaySeconds);
            }
        }

        return [
            'success' => false,
            'error' => 'تمام تلاش‌ها ناموفق بود',
            'attempt' => $maxRetries
        ];
    }

    /**
     * استخراج داده‌های کتاب از پاسخ API
     */
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
                $subData = $data[$key];
                if (isset($subData['title']) || isset($subData['id'])) {
                    return $subData;
                }
            }
        }

        Log::warning("ساختار داده API قابل شناسایی نیست", [
            'source_id' => $sourceId,
            'data_keys' => array_keys($data),
            'config_id' => $this->config->id
        ]);

        return [];
    }

    /**
     * بررسی موفقیت‌آمیز بودن پردازش
     */
    private function isProcessingSuccessful(array $result): bool
    {
        if (!isset($result['stats'])) {
            return false;
        }

        $stats = $result['stats'];
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
            'no_changes' => '⚪'
        ];

        $emoji = $actionEmojis[$action] ?? '❓';

        $executionLog->addLogEntry("{$emoji} پردازش source ID {$sourceId} تمام شد", $logData);

        Log::info("پردازش source ID {$sourceId} تمام شد", array_merge($logData, [
            'config_id' => $this->config->id,
            'execution_id' => $executionLog->execution_id
        ]));
    }

    /**
     * مدیریت خطای پردازش
     */
    private function handleProcessingException(int $sourceId, \Exception $e, ExecutionLog $executionLog): array
    {
        Log::error("❌ خطای کلی در پردازش source ID {$sourceId}", [
            'config_id' => $this->config->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // ثبت خطای کلی
        FailedRequest::recordFailure(
            $this->config->id,
            $this->config->source_name,
            (string)$sourceId,
            $this->config->buildApiUrl($sourceId),
            'خطای کلی: ' . $e->getMessage(),
            null,
            [
                'exception_type' => get_class($e),
                'error_context' => 'general_processing_error'
            ]
        );

        $executionLog->addLogEntry("💥 خطای کلی در پردازش", [
            'source_id' => $sourceId,
            'error_type' => get_class($e),
            'error_message' => $e->getMessage()
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

            // گزارش failed requests
            $failedCount = FailedRequest::where('config_id', $this->config->id)
                ->where('source_name', $this->config->source_name)
                ->where('is_resolved', false)
                ->count();

            $totalImpactful = $finalStats['total_success'] + $finalStats['total_enhanced'];
            $impactRate = $finalStats['total_processed'] > 0 ?
                round(($totalImpactful / $finalStats['total_processed']) * 100, 2) : 0;

            if ($failedCount > 0) {
                Log::info("🏁 اجرا تمام شد با {$failedCount} source ID ناموفق", [
                    'config_id' => $this->config->id,
                    'execution_id' => $executionLog->execution_id,
                    'final_stats' => $finalStats,
                    'impact_rate' => $impactRate,
                    'failed_requests_count' => $failedCount
                ]);
            } else {
                Log::info("🎉 اجرا کامل شد بدون source ID ناموفق", [
                    'config_id' => $this->config->id,
                    'execution_id' => $executionLog->execution_id,
                    'final_stats' => $finalStats,
                    'impact_rate' => $impactRate
                ]);
            }

            return [
                'action' => 'completed',
                'final_stats' => $finalStats,
                'failed_requests_count' => $failedCount,
                'impact_rate' => $impactRate
            ];

        } catch (\Exception $e) {
            Log::error("❌ خطا در تکمیل اجرا", [
                'config_id' => $this->config->id,
                'execution_id' => $executionLog->execution_id,
                'error' => $e->getMessage()
            ]);

            return ['action' => 'completed_with_error'];
        }
    }
}
