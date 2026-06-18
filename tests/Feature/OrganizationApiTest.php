<?php

namespace Tests\Feature;

use App\DTO\ParsedOrganizationData;
use App\DTO\ParsedReviewData;
use App\Exceptions\YandexParserException;
use App\Models\Organization;
use App\Models\OrganizationReview;
use App\Models\User;
use App\Services\Yandex\YandexMapsParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_save_organization_and_read_cached_reviews(): void
    {
        $this->fakeSuccessfulParser();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/organization', [
                'source_url' => 'https://yandex.ru/maps/org/demo_place/123456789/?ll=69.2%2C41.3',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Demo Place')
            ->assertJsonPath('data.rating', 4.7)
            ->assertJsonPath('data.ratings_count', 12)
            ->assertJsonPath('data.reviews_count', 2)
            ->assertJsonPath('data.scrape_status', 'success');

        $this->assertDatabaseHas('organizations', [
            'user_id' => $user->id,
            'title' => 'Demo Place',
            'scrape_status' => 'success',
        ]);
        $this->assertDatabaseHas('organization_reviews', [
            'author' => 'Anna',
            'rating' => 5,
        ]);

        $this->actingAs($user)
            ->getJson('/api/organization/reviews?page=1&per_page=50')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 50);
    }

    public function test_reviews_are_paginated_from_database_without_parser_call(): void
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'user_id' => $user->id,
            'source_url' => 'https://yandex.ru/maps/org/demo/1',
            'normalized_url' => 'https://yandex.ru/maps/org/demo/1',
            'title' => 'Demo',
            'scrape_status' => 'success',
        ]);

        for ($i = 1; $i <= 55; $i++) {
            OrganizationReview::query()->create([
                'organization_id' => $organization->id,
                'external_id' => 'review-'.$i,
                'content_hash' => hash('sha256', 'review-'.$i),
                'author' => 'Author '.$i,
                'review_date' => now()->subDays($i)->toDateString(),
                'text' => 'Review '.$i,
                'rating' => 5,
            ]);
        }

        $this->actingAs($user)
            ->getJson('/api/organization/reviews?page=2&per_page=50')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 55);
    }

    public function test_invalid_yandex_url_returns_validation_error(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/organization', [
                'source_url' => 'https://example.com/maps/org/demo/1',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_url');
    }

    public function test_parser_error_is_saved_and_returned_as_safe_response(): void
    {
        $this->app->instance(YandexMapsParserService::class, new class extends YandexMapsParserService
        {
            public function parse(string $url): ParsedOrganizationData
            {
                throw new YandexParserException('Yandex blocked automated access or captcha required', 'raw captcha page');
            }
        });

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/organization', [
                'source_url' => 'https://yandex.ru/maps/org/demo/123456789',
            ])
            ->assertStatus(502)
            ->assertJsonPath('message', 'Yandex blocked automated access or captcha required')
            ->assertJsonPath('data.scrape_status', 'failed');

        $this->assertDatabaseHas('organizations', [
            'user_id' => $user->id,
            'scrape_status' => 'failed',
            'scrape_error' => 'Yandex blocked automated access or captcha required',
        ]);
    }

    private function fakeSuccessfulParser(): void
    {
        $this->app->instance(YandexMapsParserService::class, new class extends YandexMapsParserService
        {
            public function parse(string $url): ParsedOrganizationData
            {
                return new ParsedOrganizationData(
                    title: 'Demo Place',
                    rating: 4.7,
                    ratingsCount: 12,
                    reviewsCount: 2,
                    externalId: '123456789',
                    meta: ['url' => $url],
                    reviews: [
                        new ParsedReviewData(
                            externalId: 'review-1',
                            author: 'Anna',
                            date: '2026-06-01',
                            text: 'Good place.',
                            rating: 5,
                            raw: ['id' => 'review-1'],
                        ),
                        new ParsedReviewData(
                            externalId: 'review-2',
                            author: 'Timur',
                            date: '2026-06-02',
                            text: 'Works as expected.',
                            rating: 4,
                            raw: ['id' => 'review-2'],
                        ),
                    ],
                );
            }
        });
    }
}
