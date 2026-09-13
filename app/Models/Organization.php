<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'source_url',
        'normalized_url',
        'external_id',
        'title',
        'rating',
        'ratings_count',
        'reviews_count',
        'status',
        'last_error',
        'last_successful_sync_at',
        'raw_meta',
        'last_sync_started_at',
        'last_sync_finished_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'decimal:2',
            'ratings_count' => 'integer',
            'reviews_count' => 'integer',
            'last_successful_sync_at' => 'datetime',
            'raw_meta' => 'array',
            'last_sync_started_at' => 'datetime',
            'last_sync_finished_at' => 'datetime',
        ];
    }

    public function parsingRuns(): HasMany
    {
        return $this->hasMany(ParsingRun::class);
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(ParsingRun::class)->latestOfMany();
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(OrganizationSnapshot::class);
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
