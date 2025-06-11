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
     * اسکوپ‌ها
     */
    public function scopeUnresolved($query)
    {
        return $query->where('is_resolved', false);
    }

    public function scopeNeedsRetry($query)
    {
        return $query->where('is_resolved', false)
            ->where('retry_count', '<', self::MAX_RETRY_COUNT);
    }

    public function scopeBySource($query, string $sourceName)
    {
        return $query->where('source_name', $sourceName);
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
     * علامت‌گذاری به عنوان حل شده
     */
    public function markAsResolved(): void
    {
        $this->update([
            'is_resolved' => true,
            'last_attempt_at' => now()
        ]);

        Log::info("✅ source ID ناموفق حل شد", [
            'config_id' => $this->config_id,
            'source_name' => $this->source_name,
            'source_id' => $this->source_id,
            'total_attempts' => $this->retry_count + 1
        ]);
    }

    /**
     * بررسی وجود source ID ناموفق
     */
    public static function isSourceIdFailed(string $sourceName, string $sourceId): bool
    {
        return self::where('source_name', $sourceName)
            ->where('source_id', $sourceId)
            ->where('is_resolved', false)
            ->exists();
    }

    /**
     * دریافت تمام source ID های ناموفق که نیاز به تلاش مجدد دارند
     */
    public static function getRetryableSourceIds(string $sourceName, int $limit = 50): array
    {
        return self::where('source_name', $sourceName)
            ->needsRetry()
            ->orderBy('first_failed_at')
            ->limit($limit)
            ->pluck('source_id')
            ->toArray();
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

    /**
     * گزارش تفصیلی
     */
    public function getDetailedInfo(): array
    {
        return [
            'id' => $this->id,
            'config_id' => $this->config_id,
            'source_name' => $this->source_name,
            'source_id' => $this->source_id,
            'url' => $this->url,
            'error_message' => $this->error_message,
            'http_status' => $this->http_status,
            'retry_count' => $this->retry_count,
            'max_retries' => self::MAX_RETRY_COUNT,
            'is_resolved' => $this->is_resolved,
            'should_retry' => $this->shouldRetry(),
            'first_failed_at' => $this->first_failed_at,
            'last_attempt_at' => $this->last_attempt_at,
            'days_since_first_failure' => $this->first_failed_at ?
                $this->first_failed_at->diffInDays(now()) : 0
        ];
    }
}
