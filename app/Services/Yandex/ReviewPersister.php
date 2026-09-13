<?php

namespace App\Services\Yandex;

use App\DTO\ParsedReviewData;
use App\Models\Organization;
use App\Models\OrganizationReview;

class ReviewPersister
{
    /** @param list<ParsedReviewData> $reviews */
    public function persist(Organization $organization, array $reviews): int
    {
        // One read for identity resolution, followed by batched upserts; no per-review SELECTs.
        $existing = $organization->reviews()->get(['id', 'external_id', 'content_hash']);
        $byExternal = $existing->whereNotNull('external_id')->keyBy('external_id');
        $byHash = $existing->keyBy('content_hash');
        $rows = [];
        foreach ($reviews as $review) {
            $hash = $review->contentHash($organization->id);
            $model = ($review->externalId ? $byExternal->get($review->externalId) : null) ?? $byHash->get($hash);
            $row = [
                'organization_id' => $organization->id,
                'external_id' => $review->externalId ?? $model?->external_id,
                'content_hash' => $hash, 'author' => $review->author, 'review_date' => $review->date,
                'text' => $review->text, 'rating' => $review->rating,
                'raw_payload' => json_encode($review->raw, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ];
            if ($model) {
                $row['id'] = $model->id;
            }
            $key = $model ? 'id:'.$model->id : ($row['external_id'] ? 'ext:'.$row['external_id'] : 'hash:'.$hash);
            $rows[$key] = $row;
        }
        // Existing and new rows have different columns. Separate them for portable upsert SQL.
        foreach ([true, false] as $updates) {
            $batch = array_values(array_filter($rows, fn ($row) => isset($row['id']) === $updates));
            foreach (array_chunk($batch, 100) as $chunk) {
                OrganizationReview::query()->upsert($chunk,
                    $updates ? ['id'] : ['organization_id', 'content_hash'],
                    ['external_id', 'content_hash', 'author', 'review_date', 'text', 'rating', 'raw_payload', 'updated_at']);
            }
        }

        return count($rows);
    }
}
