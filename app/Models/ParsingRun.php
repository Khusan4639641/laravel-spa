<?php

namespace App\Models;

use App\Enums\ParsingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParsingRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ParsingStatus::class,
            'queued_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime',
            'metadata' => 'array', 'progress' => 'integer', 'attempt' => 'integer',
            'reviews_found' => 'integer', 'reviews_saved' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
