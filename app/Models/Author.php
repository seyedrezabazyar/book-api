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

    /**
     * اسکوپ برای دریافت نویسندگان فعال
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * اسکوپ برای جستجو
     */
    public function scopeSearch($query, $search)
    {
        if (!empty($search)) {
            return $query->where('name', 'like', "%{$search}%");
        }
        return $query;
    }

    /**
     * دریافت کتاب‌های فعال این نویسنده
     */
    public function activeBooks()
    {
        return $this->books()->where('status', 'active');
    }

    /**
     * بروزرسانی تعداد کتاب‌ها
     */
    public function updateBooksCount(): void
    {
        $this->update([
            'books_count' => $this->books()->count()
        ]);
    }
}
