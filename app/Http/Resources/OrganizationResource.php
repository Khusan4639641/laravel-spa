<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_url' => $this->source_url,
            'normalized_url' => $this->normalized_url,
            'title' => $this->title,
            'rating' => $this->rating === null ? null : (float) $this->rating,
            'ratings_count' => (int) $this->ratings_count,
            'reviews_count' => (int) $this->reviews_count,
            'scrape_status' => $this->scrape_status,
            'scrape_error' => $this->scrape_error,
            'last_scraped_at' => $this->last_scraped_at?->toISOString(),
        ];
    }
}
