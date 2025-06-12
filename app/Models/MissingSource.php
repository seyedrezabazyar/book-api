<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MissingSource extends Model
{
    protected $fillable = [
        'config_id',
        'source_name',
        'source_id',
        'reason',
        'error_details',
        'http_status',
        'first_checked_at',
        'last_checked_at',
        'check_count',
        'is_permanently_missing'
    ];

    protected $casts = [
        'first_checked_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'check_count' => 'integer',
        'http_status' => 'integer',
        'is_permanently_missing' => 'boolean'
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(Config::class);
    }

    /**
     * ثبت source ناموجود
     */
    public static function recordMissing(
        int $configId,
        string $sourceName,
        string $sourceId,
        string $reason = 'not_found',
        ?string $errorDetails = null,
        ?int $httpStatus = null
    ): self {
        $now = now();

        $missing = self::updateOrCreate(
            [
                'config_id' => $configId,
                'source_name' => $sourceName,
                'source_id' => $sourceId
            ],
            [
                'reason' => $reason,
                'error_details' => $errorDetails,
                'http_status' => $httpStatus,
                'first_checked_at' => $now,
                'last_checked_at' => $now,
                'check_count' => 1
            ]
        );

        // اگر قبلاً وجود داشت، فقط آپدیت کن
        if (!$missing->wasRecentlyCreated) {
            $missing->increment('check_count');
            $missing->update([
                'last_checked_at' => $now,
                'reason' => $reason,
                'error_details' => $errorDetails,
                'http_status' => $httpStatus
            ]);

            // اگر بیش از 3 بار چک شده و هنوز ناموجوده، علامت‌گذاری کن
            if ($missing->check_count >= 3) {
                $missing->update(['is_permanently_missing' => true]);
            }
        }

        Log::info("📭 Source ناموجود ثبت شد", [
            'config_id' => $configId,
            'source_name' => $sourceName,
            'source_id' => $sourceId,
            'reason' => $reason,
            'check_count' => $missing->check_count,
            'is_new' => $missing->wasRecentlyCreated
        ]);

        return $missing;
    }

    /**
     * دریافت آمار source های ناموجود برای یک کانفیگ
     */
    public static function getStatsForConfig(int $configId): array
    {
        $stats = self::where('config_id', $configId)
            ->selectRaw('
                COUNT(*) as total_missing,
                COUNT(CASE WHEN is_permanently_missing = 1 THEN 1 END) as permanently_missing,
                COUNT(CASE WHEN reason = "not_found" THEN 1 END) as not_found,
                COUNT(CASE WHEN reason = "api_error" THEN 1 END) as api_errors,
                MIN(source_id) as first_missing_id,
                MAX(source_id) as last_missing_id
            ')
            ->first();

        return [
            'total_missing' => $stats->total_missing ?? 0,
            'permanently_missing' => $stats->permanently_missing ?? 0,
            'not_found' => $stats->not_found ?? 0,
            'api_errors' => $stats->api_errors ?? 0,
            'first_missing_id' => $stats->first_missing_id,
            'last_missing_id' => $stats->last_missing_id
        ];
    }

    /**
     * دریافت لیست source های ناموجود
     */
    public static function getMissingList(int $configId, int $limit = 50): array
    {
        return self::where('config_id', $configId)
            ->orderBy('source_id')
            ->limit($limit)
            ->get(['source_id', 'reason', 'check_count', 'is_permanently_missing', 'last_checked_at'])
            ->toArray();
    }

    /**
     * پاک کردن source های قدیمی
     */
    public static function cleanupOld(int $days = 90): int
    {
        return self::where('first_checked_at', '<', now()->subDays($days))
            ->where('is_permanently_missing', true)
            ->delete();
    }

    /**
     * بررسی اینکه آیا source ناموجود است
     */
    public static function isMissing(int $configId, string $sourceName, string $sourceId): bool
    {
        return self::where('config_id', $configId)
            ->where('source_name', $sourceName)
            ->where('source_id', $sourceId)
            ->exists();
    }

    /**
     * حذف source از لیست ناموجود (اگر بعداً پیدا شد)
     */
    public static function markAsFound(int $configId, string $sourceName, string $sourceId): bool
    {
        $deleted = self::where('config_id', $configId)
            ->where('source_name', $sourceName)
            ->where('source_id', $sourceId)
            ->delete();

        if ($deleted > 0) {
            Log::info("✅ Source از لیست ناموجود حذف شد", [
                'config_id' => $configId,
                'source_name' => $sourceName,
                'source_id' => $sourceId
            ]);
        }

        return $deleted > 0;
    }
}
