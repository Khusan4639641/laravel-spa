<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'external_id',
        'content_hash',
        'author',
        'review_date',
        'text',
        'rating',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'review_date' => 'date',
            'rating' => 'integer',
            'raw_payload' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
