<?php

namespace Tests\Feature;

use App\Services\Yandex\YandexMapsParserInterface;
use Tests\Support\SyncTestCase;

class PaginationTest extends SyncTestCase
{
    public function test_reviews_are_paginated_from_database_with_a_maximum_of_50(): void
    {
        $organization = $this->organization();
        $this->synchronize($organization, $this->payload(55));
        $this->mock(YandexMapsParserInterface::class)->shouldNotReceive('parse');
        $this->actingAs($organization->user)->getJson('/api/organization/reviews')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.per_page', 50);
        $this->getJson('/api/organization/reviews?page=2')->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.total', 55)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/organization/reviews?per_page=51')->assertUnprocessable();
        $this->getJson('/api/organization/reviews?page=0')->assertUnprocessable();
    }
}
