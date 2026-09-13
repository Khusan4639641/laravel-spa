<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rating' => 'float', 'ratings_count' => 'integer', 'reviews_count' => 'integer', 'payload' => 'array'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function parsingRun(): BelongsTo
    {
        return $this->belongsTo(ParsingRun::class);
    }
}
