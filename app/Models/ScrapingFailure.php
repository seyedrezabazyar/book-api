<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapingFailure extends Model
{
    protected $fillable = [
        'config_id', 'url', 'error_message', 'error_details',
        'response_content', 'http_status', 'retry_count',
        'is_resolved', 'last_attempt_at'
    ];

    protected $casts = [
        'error_details' => 'array',
        'is_resolved' => 'boolean',
        'last_attempt_at' => 'datetime',
        'retry_count' => 'integer',
        'http_status' => 'integer'
    ];

    // روابط
    public function config(): BelongsTo
    {
        return $this->belongsTo(Config::class);
    }

    // Scopes
    public function scopeUnresolved($query)
    {
        return $query->where('is_resolved', false);
    }

    public function scopeRecent($query)
    {
        return $query->orderBy('last_attempt_at', 'desc');
    }

    // متدها
    public function markAsResolved(): void
    {
        $this->update(['is_resolved' => true]);
    }

    public function incrementRetry(): void
    {
        $this->increment('retry_count');
        $this->update(['last_attempt_at' => now()]);
    }

    // ثبت شکست جدید
    public static function logFailure(
        int $configId,
        string $url,
        string $errorMessage,
        array $errorDetails = [],
        ?string $responseContent = null,
        ?int $httpStatus = null
    ): self {
        return self::create([
            'config_id' => $configId,
            'url' => $url,
            'error_message' => $errorMessage,
            'error_details' => $errorDetails,
            'response_content' => $responseContent,
            'http_status' => $httpStatus,
            'last_attempt_at' => now()
        ]);
    }
}
