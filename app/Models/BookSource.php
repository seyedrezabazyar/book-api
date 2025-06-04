<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookSource extends Model
{
    protected $table = 'book_sources';

    protected $fillable = [
        'book_id',
        'source_type',
        'source_id',
        'source_url',
        'source_updated_at',
        'is_active',
        'priority'
    ];

    protected $casts = [
        'source_updated_at' => 'datetime',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBySourceType($query, $sourceType)
    {
        return $query->where('source_type', $sourceType);
    }
}
