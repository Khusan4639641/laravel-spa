<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParsingRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'organization_id' => $this->organization_id, 'status' => $this->status->value,
            'progress' => $this->progress, 'reviews_found' => $this->reviews_found, 'reviews_saved' => $this->reviews_saved,
            'current_step' => $this->current_step, 'attempt' => $this->attempt,
            'error_code' => $this->error_code, 'error_message' => $this->error_message,
            'error_http_status' => $this->error_code ? ($this->error_code === 'INTERNAL_ERROR' ? 500 : 502) : null,
            'can_retry' => $this->status->value === 'failed' && in_array($this->error_code, ['YANDEX_NETWORK_ERROR', 'INTERNAL_ERROR']),
            'queued_at' => $this->queued_at?->toISOString(), 'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'coverage' => $this->metadata['coverage'] ?? null,
            'stop_reason' => $this->metadata['stop_reason'] ?? null,
        ];
    }
}
