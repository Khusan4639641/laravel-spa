<?php

namespace Tests\Feature;

use Tests\Support\SyncTestCase;

class OrganizationSnapshotTest extends SyncTestCase
{
    public function test_snapshots_record_before_and_after_without_replacing_declared_counts(): void
    {
        $organization = $this->organization();
        $one = $this->payload(2);
        $one['organization'] = [...$one['organization'], 'rating' => 4.6, 'ratings_count' => 1000, 'reviews_count' => 580];
        $one['meta']['stop_reason'] = 'exhausted';
        $one['meta']['coverage'] = 'available_only';
        $this->synchronize($organization, $one);
        $two = $one;
        $two['organization'] = [...$two['organization'], 'rating' => 4.7, 'ratings_count' => 1050, 'reviews_count' => 593];
        $run = $this->synchronize($organization, $two);
        $snapshots = $organization->snapshots()->orderBy('id')->get();
        $this->assertCount(2, $snapshots);
        $this->assertSame([4.6, 4.7], $snapshots->pluck('rating')->all());
        $this->assertSame([1000, 1050], $snapshots->pluck('ratings_count')->all());
        $this->assertSame([580, 593], $snapshots->pluck('reviews_count')->all());
        $this->assertSame(2, $run->reviews_found);
        $this->assertSame(593, $organization->refresh()->reviews_count);
        $this->actingAs($organization->user)->getJson('/api/organization/snapshots')->assertOk()
            ->assertJsonPath('data.0.changes.reviews_count.from', 580)->assertJsonPath('data.0.changes.reviews_count.to', 593);
    }
}
