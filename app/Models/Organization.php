<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'source_url',
        'normalized_url',
        'yandex_external_id',
        'title',
        'rating',
        'ratings_count',
        'reviews_count',
        'scrape_status',
        'scrape_error',
        'last_scraped_at',
        'raw_meta',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'decimal:2',
            'ratings_count' => 'integer',
            'reviews_count' => 'integer',
            'last_scraped_at' => 'datetime',
            'raw_meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(OrganizationReview::class);
    }
}
