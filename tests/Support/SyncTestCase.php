<?php

namespace Tests\Support;

use App\DTO\ParsedYandexResult;
use App\Models\Organization;
use App\Models\ParsingRun;
use App\Models\User;
use App\Services\Yandex\OrganizationSyncService;
use App\Services\Yandex\YandexMapsParserInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class SyncTestCase extends TestCase
{
    use RefreshDatabase;

    protected function organization(?User $user = null): Organization
    {
        return Organization::create([
            'user_id' => ($user ?? User::factory()->create())->id,
            'source_url' => 'https://yandex.ru/maps/org/123456789/',
            'normalized_url' => 'https://yandex.ru/maps/org/123456789/',
            'external_id' => '123456789', 'status' => 'idle',
        ]);
    }

    protected function payload(int $count = 2): array
    {
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/valid-yandex-response.json')), true);
        $payload['reviews'] = [];
        for ($i = 1; $i <= $count; $i++) {
            $payload['reviews'][] = ['external_id' => 'review-'.$i, 'author' => 'Author '.$i,
                'date' => '2026-01-10', 'text' => 'Review '.$i, 'rating' => 5, 'raw' => []];
        }
        $payload['organization']['reviews_count'] = $count;
        $payload['meta']['reviews_loaded'] = $count;

        return $payload;
    }

    protected function fakeParser(array $payload): void
    {
        $this->app->instance(YandexMapsParserInterface::class, new class($payload) implements YandexMapsParserInterface
        {
            public function __construct(private array $payload) {}

            public function parse(string $url, ?callable $onProgress = null): ParsedYandexResult
            {
                if ($onProgress) {
                    $onProgress(['step' => 'loading_reviews', 'progress' => 65, 'reviews_found' => count($this->payload['reviews'])]);
                }

                return ParsedYandexResult::fromArray($this->payload);
            }
        });
    }

    protected function synchronize(Organization $organization, array $payload): ParsingRun
    {
        $this->fakeParser($payload);
        $run = $organization->parsingRuns()->create(['status' => 'pending', 'queued_at' => now()]);
        app(OrganizationSyncService::class)->execute($run->id);

        return $run->refresh();
    }
}
