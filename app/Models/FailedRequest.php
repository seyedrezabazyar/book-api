<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class FailedRequest extends Model
{
    protected $fillable = [
        'config_id',
        'source_name',
        'source_id',
        'url',
        'error_message',
        'error_details',
        'http_status',
        'retry_count',
        'is_resolved',
        'last_attempt_at',
        'first_failed_at'
    ];

    protected $casts = [
        'error_details' => 'array',
        'is_resolved' => 'boolean',
        'retry_count' => 'integer',
        'http_status' => 'integer',
        'last_attempt_at' => 'datetime',
        'first_failed_at' => 'datetime'
    ];

    const MAX_RETRY_COUNT = 3; // حداکثر 3 بار تلاش

    /**
     * روابط
     */
    public function config(): BelongsTo
    {
        return $this->belongsTo(Config::class);
    }

    /**
     * ثبت درخواست ناموفق جدید یا بروزرسانی موجود
     */
    public static function recordFailure(
        int $configId,
        string $sourceName,
        string $sourceId,
        string $url,
        string $errorMessage,
        ?int $httpStatus = null,
        array $errorDetails = []
    ): self {
        // پیدا کردن رکورد موجود یا ایجاد جدید
        $failedRequest = self::updateOrCreate(
            [
                'config_id' => $configId,
                'source_name' => $sourceName,
                'source_id' => $sourceId
            ],
            [
                'url' => $url,
                'error_message' => $errorMessage,
                'error_details' => $errorDetails,
                'http_status' => $httpStatus,
                'last_attempt_at' => now(),
                'first_failed_at' => now() // اگر جدید باشد، first_failed_at ست میشه
            ]
        );

        // اگر رکورد موجود بود، retry_count و first_failed_at را حفظ کن
        if (!$failedRequest->wasRecentlyCreated) {
            $failedRequest->increment('retry_count');

            Log::warning("⚠️ تلاش مجدد برای source ID ناموفق", [
                'config_id' => $configId,
                'source_name' => $sourceName,
                'source_id' => $sourceId,
                'retry_count' => $failedRequest->retry_count,
                'max_retries' => self::MAX_RETRY_COUNT,
                'error' => $errorMessage
            ]);
        } else {
            Log::warning("❌ ثبت source ID ناموفق جدید", [
                'config_id' => $configId,
                'source_name' => $sourceName,
                'source_id' => $sourceId,
                'error' => $errorMessage,
                'http_status' => $httpStatus
            ]);
        }

        return $failedRequest;
    }

    /**
     * بررسی اینکه آیا باید دوباره تلاش کرد
     */
    public function shouldRetry(): bool
    {
        return !$this->is_resolved && $this->retry_count < self::MAX_RETRY_COUNT;
    }

    /**
     * پاکسازی درخواست‌های قدیمی حل شده
     */
    public static function cleanupOldResolved(int $daysOld = 30): int
    {
        $deletedCount = self::where('is_resolved', true)
            ->where('updated_at', '<', now()->subDays($daysOld))
            ->delete();

        if ($deletedCount > 0) {
            Log::info("🧹 پاکسازی درخواست‌های ناموفق قدیمی", [
                'deleted_count' => $deletedCount,
                'days_old' => $daysOld
            ]);
        }

        return $deletedCount;
    }

    /**
     * آمار درخواست‌های ناموفق برای منبع خاص
     */
    public static function getSourceStats(string $sourceName): array
    {
        $stats = self::where('source_name', $sourceName)
            ->selectRaw('
                        COUNT(*) as total_failed,
                        COUNT(CASE WHEN is_resolved = 1 THEN 1 END) as resolved_count,
                        COUNT(CASE WHEN is_resolved = 0 THEN 1 END) as unresolved_count,
                        COUNT(CASE WHEN retry_count >= ? THEN 1 END) as max_retries_reached
                    ', [self::MAX_RETRY_COUNT])
            ->first();

        return [
            'source_name' => $sourceName,
            'total_failed' => $stats->total_failed ?? 0,
            'resolved_count' => $stats->resolved_count ?? 0,
            'unresolved_count' => $stats->unresolved_count ?? 0,
            'max_retries_reached' => $stats->max_retries_reached ?? 0,
            'retry_rate' => $stats->total_failed > 0 ?
                round(($stats->resolved_count / $stats->total_failed) * 100, 1) : 0
        ];
    }

}
