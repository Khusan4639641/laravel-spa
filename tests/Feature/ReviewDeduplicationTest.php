<?php

namespace Tests\Feature;

use Tests\Support\SyncTestCase;

class ReviewDeduplicationTest extends SyncTestCase
{
    public function test_600_reviews_then_5_new_and_10_edited_results_in_605_reviews(): void
    {
        $organization = $this->organization();
        $this->synchronize($organization, $this->payload(600));
        $second = $this->payload(605);
        for ($i = 0; $i < 10; $i++) {
            $second['reviews'][$i]['text'] = 'Edited review '.$i;
        }
        $this->synchronize($organization, $second);
        $this->assertDatabaseCount('organization_reviews', 605);
        $this->assertSame(10, $organization->reviews()->where('text', 'like', 'Edited review%')->count());
    }

    public function test_content_hash_fallback_handles_normalization_and_later_external_id(): void
    {
        $organization = $this->organization();
        $payload = $this->payload(1);
        $payload['reviews'][0]['external_id'] = null;
        $this->synchronize($organization, $payload);
        $payload['reviews'][0]['external_id'] = 'stable-id';
        $payload['reviews'][0]['text'] = "  REVIEW   1 \n";
        $this->synchronize($organization, $payload);
        $this->assertDatabaseCount('organization_reviews', 1);
        $this->assertDatabaseHas('organization_reviews', ['external_id' => 'stable-id']);
        $payload['reviews'][0]['external_id'] = null;
        $this->synchronize($organization, $payload);
        $this->assertDatabaseHas('organization_reviews', ['external_id' => 'stable-id']);
    }
}
