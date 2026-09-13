<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SyncTestCase;

class AuthorizationTest extends SyncTestCase
{
    public function test_guests_cannot_access_protected_endpoints(): void
    {
        foreach (['/api/me', '/api/organization', '/api/organization/reviews', '/api/organization/snapshots', '/api/parsing-runs/1'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        foreach (['/api/organization', '/api/organization/sync', '/api/logout'] as $url) {
            $this->postJson($url)->assertUnauthorized();
        }
    }

    public function test_other_users_cannot_access_organization_reviews_runs_or_snapshots(): void
    {
        $organization = $this->organization();
        $run = $this->synchronize($organization, $this->payload());
        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/api/organization')->assertJsonPath('data', null);
        $this->getJson('/api/organization/reviews?organization_id='.$organization->id)->assertJsonCount(0, 'data');
        $this->getJson('/api/parsing-runs/'.$run->id)->assertNotFound();
        $this->getJson('/api/organization/snapshots')->assertNotFound();
        $this->postJson('/api/organization/sync', ['organization_id' => $organization->id])->assertNotFound();
        $this->assertDatabaseCount('parsing_runs', 1);
    }

    public function test_input_cannot_assign_ownership_or_synchronize_foreign_run(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner)->postJson('/api/organization', [
            'source_url' => 'https://yandex.ru/maps/org/123456789/', 'user_id' => $other->id, 'status' => 'ready', 'rating' => 5,
        ])->assertAccepted();
        $this->assertDatabaseHas('organizations', ['user_id' => $owner->id, 'status' => 'pending', 'rating' => null]);
    }
}
