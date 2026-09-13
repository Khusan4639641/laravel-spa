<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'external_id' => $this->external_id,
            'source_url' => $this->source_url, 'normalized_url' => $this->normalized_url, 'title' => $this->title,
            'rating' => $this->rating === null ? null : (float) $this->rating,
            'ratings_count' => $this->ratings_count, 'reviews_count' => $this->reviews_count,
            'status' => $this->status, 'last_error' => $this->last_error,
            'last_successful_sync_at' => $this->last_successful_sync_at?->toISOString(),
            'last_sync_started_at' => $this->last_sync_started_at?->toISOString(),
            'last_sync_finished_at' => $this->last_sync_finished_at?->toISOString(),
            'latest_run' => new ParsingRunResource($this->whenLoaded('latestRun')),
        ];
    }
}
