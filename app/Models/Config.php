<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class Config extends Model
{
    protected $fillable = [
        'name',
        'base_url',
        'source_type',
        'source_name',
        'timeout',
        'delay_seconds',
        'records_per_run',
        'page_delay',
        'start_page',
        'max_pages',
        'current_page',
        'last_source_id',
        'auto_resume',
        'fill_missing_fields',
        'update_descriptions',
        'config_data',
        'created_by',
        'total_processed',
        'total_success',
        'total_failed',
        'last_run_at',
        'is_running',
        'is_active'
    ];

    protected $casts = [
        'config_data' => 'array',
        'timeout' => 'integer',
        'delay_seconds' => 'integer',
        'records_per_run' => 'integer',
        'page_delay' => 'integer',
        'start_page' => 'integer',
        'max_pages' => 'integer',
        'current_page' => 'integer',
        'last_source_id' => 'integer',
        'total_processed' => 'integer',
        'total_success' => 'integer',
        'total_failed' => 'integer',
        'last_run_at' => 'datetime',
        'is_running' => 'boolean',
        'is_active' => 'boolean',
        'auto_resume' => 'boolean',
        'fill_missing_fields' => 'boolean',
        'update_descriptions' => 'boolean',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function executionLogs(): HasMany
    {
        return $this->hasMany(ExecutionLog::class);
    }

    public function missingSources(): HasMany
    {
        return $this->hasMany(MissingSource::class);
    }

    /**
     * تعیین صفحه شروع هوشمند بهینه‌شده
     */
    public function getSmartStartPage(): int
    {
        // اولویت 1: start_page تعیین شده توسط کاربر
        if ($this->start_page && $this->start_page > 0) {
            Log::info("🎯 شروع از start_page کاربر", [
                'config_id' => $this->id,
                'start_page' => $this->start_page
            ]);
            return $this->start_page;
        }

        // اولویت 2: آخرین ID موفق از book_sources
        $lastSuccessfulId = $this->getLastSuccessfulSourceId();
        if ($lastSuccessfulId > 0) {
            $nextId = $lastSuccessfulId + 1;
            Log::info("📊 شروع هوشمند از آخرین ID موفق", [
                'config_id' => $this->id,
                'last_successful_id' => $lastSuccessfulId,
                'next_start' => $nextId
            ]);
            return $nextId;
        }

        // اولویت 3: auto_resume
        if ($this->auto_resume && $this->last_source_id > 0) {
            $nextId = $this->last_source_id + 1;
            Log::info("🔄 ادامه از last_source_id", [
                'config_id' => $this->id,
                'last_source_id' => $this->last_source_id,
                'next_start' => $nextId
            ]);
            return $nextId;
        }

        // پیش‌فرض
        Log::info("🆕 شروع از ابتدا", ['config_id' => $this->id]);
        return 1;
    }

    /**
     * دریافت آخرین source_id موفق
     */
    public function getLastSuccessfulSourceId(): int
    {
        try {
            $lastSourceRecord = \App\Models\BookSource::where('source_name', $this->source_name)
                ->whereRaw('source_id REGEXP "^[0-9]+$"')
                ->orderByRaw('CAST(source_id AS UNSIGNED) DESC')
                ->first();

            return $lastSourceRecord ? (int)$lastSourceRecord->source_id : 0;
        } catch (\Exception $e) {
            Log::error("❌ خطا در دریافت آخرین ID موفق", [
                'config_id' => $this->id,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * دریافت آمار کامل شامل missing sources
     */
    public function getCompleteStats(): array
    {
        try {
            $basicStats = $this->getBasicStats();
            $missingStats = MissingSource::getStatsForConfig($this->id);

            return array_merge($basicStats, [
                'missing_sources' => $missingStats['total_missing'],
                'permanently_missing' => $missingStats['permanently_missing'],
                'missing_not_found' => $missingStats['not_found'],
                'missing_api_errors' => $missingStats['api_errors']
            ]);
        } catch (\Exception $e) {
            Log::error("❌ خطا در دریافت آمار کامل", [
                'config_id' => $this->id,
                'error' => $e->getMessage()
            ]);

            return $this->getBasicStats();
        }
    }

    private function getBasicStats(): array
    {
        $stats = $this->executionLogs()
            ->whereIn('status', ['completed', 'stopped'])
            ->selectRaw('
                SUM(total_processed) as total_processed,
                SUM(total_success) as total_success,
                SUM(total_failed) as total_failed,
                SUM(total_enhanced) as total_enhanced,
                SUM(total_duplicate) as total_duplicate
            ')
            ->first();

        $totalEnhanced = $stats ? ($stats->total_enhanced ?? 0) : 0;
        $totalDuplicate = $stats ? ($stats->total_duplicate ?? 0) : 0;
        $realSuccessCount = $this->total_success + $totalEnhanced;

        return [
            'total_processed' => $this->total_processed,
            'total_success' => $this->total_success,
            'total_failed' => $this->total_failed,
            'total_enhanced' => $totalEnhanced,
            'total_duplicate' => $totalDuplicate,
            'real_success_count' => $realSuccessCount,
            'real_success_rate' => $this->total_processed > 0 ?
                round(($realSuccessCount / $this->total_processed) * 100, 2) : 0,
            'enhancement_rate' => $this->total_processed > 0 ?
                round(($totalEnhanced / $this->total_processed) * 100, 2) : 0
        ];
    }

    /**
     * بررسی وجود source در missing list
     */
    public function isSourceMissing(string $sourceId): bool
    {
        return $this->missingSources()
            ->where('source_id', $sourceId)
            ->exists();
    }

    /**
     * دریافت تعداد source های ناموجود
     */
    public function getMissingSourcesCount(): int
    {
        return $this->missingSources()->count();
    }

    /**
     * بررسی gaps در source_id ها
     */
    public function findSourceGaps(int $maxId = 100): array
    {
        try {
            // دریافت تمام source_id های موجود
            $existingIds = \App\Models\BookSource::where('source_name', $this->source_name)
                ->whereRaw('source_id REGEXP "^[0-9]+$"')
                ->whereRaw('CAST(source_id AS UNSIGNED) <= ?', [$maxId])
                ->pluck('source_id')
                ->map(function($id) { return (int)$id; })
                ->sort()
                ->values()
                ->toArray();

            // دریافت source_id های ناموجود
            $missingIds = $this->missingSources()
                ->whereRaw('source_id REGEXP "^[0-9]+$"')
                ->whereRaw('CAST(source_id AS UNSIGNED) <= ?', [$maxId])
                ->pluck('source_id')
                ->map(function($id) { return (int)$id; })
                ->sort()
                ->values()
                ->toArray();

            // پیدا کردن gaps
            $allExpectedIds = range(1, $maxId);
            $gaps = array_diff($allExpectedIds, $existingIds, $missingIds);

            return [
                'gaps' => array_values($gaps),
                'existing_ids' => $existingIds,
                'missing_ids' => $missingIds,
                'gap_count' => count($gaps)
            ];

        } catch (\Exception $e) {
            Log::error("❌ خطا در یافتن gaps", [
                'config_id' => $this->id,
                'error' => $e->getMessage()
            ]);

            return [
                'gaps' => [],
                'existing_ids' => [],
                'missing_ids' => [],
                'gap_count' => 0
            ];
        }
    }

    /**
     * ساخت URL API برای ID خاص
     */
    public function buildApiUrl(int $sourceId): string
    {
        $apiSettings = $this->getApiSettings();
        $baseUrl = rtrim($this->base_url, '/');
        $endpoint = $apiSettings['endpoint'] ?? '';

        $fullUrl = $baseUrl . ($endpoint ? '/' . ltrim($endpoint, '/') : '');

        if (strpos($fullUrl, '{id}') !== false) {
            $fullUrl = str_replace('{id}', $sourceId, $fullUrl);
        } else {
            $params = ['id' => $sourceId];
            if (!empty($apiSettings['params'])) {
                $params = array_merge($params, $apiSettings['params']);
            }
            $fullUrl .= '?' . http_build_query($params);
        }

        return $fullUrl;
    }

    /**
     * بروزرسانی آمار بهینه‌شده
     */
    public function updateProgress(int $sourceId, array $stats): void
    {
        try {
            DB::transaction(function () use ($sourceId, $stats) {
                $totalToAdd = $this->extractStatValue($stats, ['total_processed', 'total']);
                $successToAdd = $this->extractStatValue($stats, ['total_success', 'success']);
                $failedToAdd = $this->extractStatValue($stats, ['total_failed', 'failed']);

                if ($totalToAdd > 0) $this->increment('total_processed', $totalToAdd);
                if ($successToAdd > 0) $this->increment('total_success', $successToAdd);
                if ($failedToAdd > 0) $this->increment('total_failed', $failedToAdd);

                if ($sourceId > ($this->last_source_id ?? 0)) {
                    $this->update([
                        'last_source_id' => $sourceId,
                        'current_page' => $sourceId,
                        'last_run_at' => now()
                    ]);
                }
            });

        } catch (\Exception $e) {
            Log::error("❌ خطا در بروزرسانی آمار", [
                'config_id' => $this->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function extractStatValue(array $stats, array $possibleKeys): int
    {
        foreach ($possibleKeys as $key) {
            if (isset($stats[$key]) && is_numeric($stats[$key])) {
                return (int)$stats[$key];
            }
        }
        return 0;
    }

    public function getApiSettings(): array
    {
        return $this->config_data['api'] ?? [];
    }

    public function getGeneralSettings(): array
    {
        return $this->config_data['general'] ?? [];
    }

    public static function getBookFields(): array
    {
        return [
            'title' => 'عنوان کتاب',
            'description' => 'توضیحات',
            'author' => 'نویسنده',
            'publisher' => 'ناشر',
            'category' => 'دسته‌بندی',
            'isbn' => 'شابک',
            'publication_year' => 'سال انتشار',
            'pages_count' => 'تعداد صفحات',
            'language' => 'زبان',
            'format' => 'فرمت فایل',
            'file_size' => 'حجم فایل',
            'image_url' => 'تصویر کتاب',
            'sha1' => 'SHA1 Hash',
            'sha256' => 'SHA256 Hash',
            'crc32' => 'CRC32 Hash',
            'ed2k' => 'ED2K Hash',
            'btih' => 'BitTorrent Info Hash',
            'magnet' => 'Magnet Link'
        ];
    }
}
