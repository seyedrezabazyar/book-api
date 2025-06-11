<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * مدل نویسندگان کتاب‌ها
 */
class Author extends Model
{
    use HasFactory;

    protected $table = 'authors';

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
    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_author');
    }
}
