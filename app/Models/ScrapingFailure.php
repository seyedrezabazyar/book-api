<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapingFailure extends Model
{
    protected $fillable = [
        'config_id',
        'url',
        'error_message',
        'error_details',
        'response_content',
        'http_status',
        'retry_count',
        'is_resolved',
        'last_attempt_at'
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
}
