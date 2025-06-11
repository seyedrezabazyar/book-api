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

        Log::info("🔍 پردازش source ID {$sourceId}", [
            'config_id' => $this->config->id,
            'execution_id' => $executionLog->execution_id
        ]);

        try {
            // بررسی تکراری بودن در book_sources
            if ($this->bookProcessor->isSourceAlreadyProcessed($this->config->source_name, $sourceId)) {
                $executionLog->addLogEntry("⏭️ Source ID {$sourceId} قبلاً پردازش شده در book_sources");

                $stats = [
                    'total_processed' => 1,
                    'total_success' => 0,
                    'total_failed' => 0,
                    'total_duplicate' => 1,
                    'total_enhanced' => 0
                ];

                $this->updateStats($executionLog, $stats);
                return $this->buildResult($sourceId, 'skipped_duplicate', $stats);
            }

            // بررسی اینکه آیا این source ID قبلاً ناموفق بوده و نیاز به retry دارد
            $existingFailure = FailedRequest::where('config_id', $this->config->id)
                ->where('source_name', $this->config->source_name)
                ->where('source_id', (string)$sourceId)
                ->where('is_resolved', false)
                ->first();

            if ($existingFailure && !$existingFailure->shouldRetry()) {
                $executionLog->addLogEntry("❌ Source ID {$sourceId} حداکثر تلاش رسیده - رد شد", [
                    'retry_count' => $existingFailure->retry_count,
                    'max_retries' => FailedRequest::MAX_RETRY_COUNT
                ]);

                $stats = [
                    'total_processed' => 1,
                    'total_success' => 0,
                    'total_failed' => 1,
                    'total_duplicate' => 0,
                    'total_enhanced' => 0
                ];

                $this->updateStats($executionLog, $stats);
                return $this->buildResult($sourceId, 'max_retries_reached', $stats);
            }

            // درخواست API با retry logic
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

            // اگر پردازش موفق بود، failure موجود را حل شده علامت‌گذاری کن
            if (isset($result['stats']) && ($result['stats']['total_success'] > 0 || $result['stats']['total_enhanced'] > 0)) {
                if ($existingFailure) {
                    $existingFailure->markAsResolved();
                }
            }

            // بروزرسانی آمار از نتیجه BookProcessor
            if (isset($result['stats'])) {
                $this->updateStats($executionLog, $result['stats']);
            }

            return $result;

        } catch (\Exception $e) {
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
                ['exception_type' => get_class($e)]
            );

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
     * درخواست API با منطق retry
     */
    private function makeApiRequestWithRetry(int $sourceId, ExecutionLog $executionLog): array
    {
        $url = $this->config->buildApiUrl($sourceId);
        $maxRetries = 3;
        $retryDelay = 2; // ثانیه

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                Log::debug("🌐 تلاش {$attempt}/{$maxRetries} برای source ID {$sourceId}", [
                    'url' => $url
                ]);

                $response = $this->apiClient->request($url);

                if ($response->successful()) {
                    $data = $response->json();

                    if (empty($data)) {
                        throw new \Exception('پاسخ API خالی است');
                    }

                    Log::debug("✅ درخواست موفق در تلاش {$attempt}", [
                        'source_id' => $sourceId,
                        'response_size' => strlen($response->body())
                    ]);

                    return [
                        'success' => true,
                        'data' => $data,
                        'attempt' => $attempt
                    ];
                }

                // HTTP error
                $errorMessage = "HTTP {$response->status()}: {$response->reason()}";
                Log::warning("⚠️ خطای HTTP در تلاش {$attempt}", [
                    'source_id' => $sourceId,
                    'status' => $response->status(),
                    'error' => $errorMessage
                ]);

                // اگر 404 است، دیگر retry نکن
                if ($response->status() === 404) {
                    return [
                        'success' => false,
                        'error' => 'کتاب با این ID یافت نشد (404)',
                        'http_status' => 404,
                        'attempt' => $attempt,
                        'details' => ['no_retry_reason' => '404_not_found']
                    ];
                }

                // اگر آخرین تلاش بود
                if ($attempt === $maxRetries) {
                    return [
                        'success' => false,
                        'error' => $errorMessage,
                        'http_status' => $response->status(),
                        'attempt' => $attempt,
                        'details' => ['max_retries_reached' => true]
                    ];
                }

            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                Log::warning("⚠️ خطای exception در تلاش {$attempt}", [
                    'source_id' => $sourceId,
                    'error' => $errorMessage
                ]);

                // اگر آخرین تلاش بود
                if ($attempt === $maxRetries) {
                    return [
                        'success' => false,
                        'error' => $errorMessage,
                        'attempt' => $attempt,
                        'details' => [
                            'exception_type' => get_class($e),
                            'max_retries_reached' => true
                        ]
                    ];
                }
            }

            // تاخیر قبل از تلاش بعدی
            if ($attempt < $maxRetries) {
                $delaySeconds = $retryDelay * $attempt; // تاخیر افزایشی
                Log::debug("⏳ تاخیر {$delaySeconds} ثانیه قبل از تلاش بعدی", [
                    'source_id' => $sourceId,
                    'next_attempt' => $attempt + 1
                ]);

                sleep($delaySeconds);
            }
        }

        // این خط هرگز نباید اجرا شود
        return [
            'success' => false,
            'error' => 'خطای غیرمنتظره در retry logic',
            'attempt' => $maxRetries
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

            // گزارش failed requests
            $failedCount = FailedRequest::where('config_id', $this->config->id)
                ->where('source_name', $this->config->source_name)
                ->where('is_resolved', false)
                ->count();

            if ($failedCount > 0) {
                Log::info("📊 اجرا تمام شد با {$failedCount} source ID ناموفق", [
                    'config_id' => $this->config->id,
                    'execution_id' => $executionLog->execution_id,
                    'final_stats' => $finalStats,
                    'failed_requests_count' => $failedCount
                ]);
            } else {
                Log::info("🎉 اجرا کامل شد بدون source ID ناموفق", [
                    'config_id' => $this->config->id,
                    'execution_id' => $executionLog->execution_id,
                    'final_stats' => $finalStats
                ]);
            }

            return ['action' => 'completed', 'final_stats' => $finalStats, 'failed_requests_count' => $failedCount];

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
