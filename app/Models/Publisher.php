<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مدل ناشران کتاب‌ها
 */
class Publisher extends Model
{
    use HasFactory;

    protected $table = 'publishers';

    protected $fillable = [
        'name',
        'slug',
        'is_active',
        'books_count'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'books_count' => 'integer'
    ];

    /**
     * روابط
     */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }
}
