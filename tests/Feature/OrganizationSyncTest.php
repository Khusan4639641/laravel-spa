<?php

namespace Tests\Feature;

use App\Enums\ParsingStatus;
use App\Exceptions\YandexParserException;
use App\Jobs\ParseYandexOrganizationJob;
use App\Models\ParsingRun;
use App\Models\User;
use App\Services\Yandex\OrganizationSyncScheduler;
use App\Services\Yandex\OrganizationSyncService;
use App\Services\Yandex\ReviewPersister;
use App\Services\Yandex\YandexMapsParserInterface;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SyncTestCase;

class OrganizationSyncTest extends SyncTestCase
{
    public function test_http_enqueues_then_real_database_worker_processes_fake_parser_atomically(): void
    {
        $this->fakeParser($this->payload());
        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/api/organization', ['source_url' => 'https://yandex.ru/maps/org/demo/123456789/?z=5'])
            ->assertAccepted()->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('organization_reviews', 0);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'yandex', '--once' => true, '--sleep' => 0])->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('organization_reviews', 2);
        $this->assertDatabaseCount('organization_snapshots', 1);
        $this->getJson('/api/parsing-runs/'.$response->json('data.parsing_run_id'))->assertOk()
            ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.progress', 100)->assertJsonPath('data.reviews_saved', 2);
        $this->getJson('/api/organization')->assertJsonPath('data.rating', 4.7)->assertJsonPath('data.status', 'ready');
    }

    public function test_concurrent_sync_is_rejected_and_dispatch_does_not_invoke_parser(): void
    {
        Queue::fake();
        $this->mock(YandexMapsParserInterface::class)->shouldNotReceive('parse');
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/organization', ['source_url' => 'https://yandex.ru/maps/org/demo/123456789/'])->assertAccepted();
        $this->postJson('/api/organization/sync')->assertConflict();
        $this->postJson('/api/organization', ['source_url' => 'https://yandex.com/maps/org/123456789/'])->assertConflict();
        Queue::assertPushed(ParseYandexOrganizationJob::class, 1);
        $this->assertDatabaseCount('parsing_runs', 1);
    }

    public function test_failed_sync_preserves_previous_successful_data(): void
    {
        $organization = $this->organization();
        $this->synchronize($organization, $this->payload());
        $lastSuccess = $organization->refresh()->last_successful_sync_at;
        $this->mock(YandexMapsParserInterface::class)->shouldReceive('parse')->once()
            ->andThrow(new YandexParserException('YANDEX_SOURCE_STRUCTURE_CHANGED', 'missing selector'));
        $run = $organization->parsingRuns()->create(['status' => 'pending']);
        (new ParseYandexOrganizationJob($run->id))->handle(app(OrganizationSyncService::class));
        $this->assertSame(ParsingStatus::Failed, $run->refresh()->status);
        $this->assertSame('YANDEX_SOURCE_STRUCTURE_CHANGED', $run->error_code);
        $this->assertSame('4.70', $organization->refresh()->rating);
        $this->assertEquals($lastSuccess, $organization->last_successful_sync_at);
        $this->assertDatabaseCount('organization_reviews', 2);
        $this->assertDatabaseCount('organization_snapshots', 1);
    }

    public function test_switching_organization_preserves_old_reviews_and_reselecting_reuses_it(): void
    {
        Queue::fake();
        $organization = $this->organization();
        $this->synchronize($organization, $this->payload());
        $this->actingAs($organization->user)->postJson('/api/organization', ['source_url' => 'https://yandex.uz/maps/org/999/'])->assertAccepted();
        $this->assertDatabaseCount('organizations', 2);
        $this->assertDatabaseCount('organization_reviews', 2);
        $this->getJson('/api/organization/reviews')->assertJsonCount(0, 'data');
        ParsingRun::where('status', 'pending')->update(['status' => 'failed']);
        $this->postJson('/api/organization', ['source_url' => 'https://yandex.com/maps/org/123456789/?z=3'])
            ->assertAccepted()->assertJsonPath('data.organization_id', $organization->id);
        $this->assertDatabaseCount('organizations', 2);
    }

    public function test_persistence_failure_rolls_back_organization_reviews_and_snapshot(): void
    {
        $organization = $this->organization();
        $this->synchronize($organization, $this->payload());
        $this->mock(ReviewPersister::class)->shouldReceive('persist')->andThrow(new \RuntimeException('storage unavailable'));
        $next = $this->payload(5);
        $next['organization']['rating'] = 3.5;
        try {
            $this->synchronize($organization, $next);
        } catch (\RuntimeException) {
        }
        $this->assertSame('4.70', $organization->refresh()->rating);
        $this->assertDatabaseCount('organization_reviews', 2);
        $this->assertDatabaseCount('organization_snapshots', 1);
        $this->assertDatabaseHas('parsing_runs', ['status' => 'failed', 'error_code' => 'INTERNAL_ERROR']);
    }

    public function test_reprocessing_completed_run_is_a_noop(): void
    {
        $organization = $this->organization();
        $run = $this->synchronize($organization, $this->payload());
        $this->mock(YandexMapsParserInterface::class)->shouldNotReceive('parse');
        app(OrganizationSyncService::class)->execute($run->id);
        $this->assertDatabaseCount('organization_snapshots', 1);
    }

    public function test_unexpected_api_failure_does_not_expose_debug_details(): void
    {
        config(['app.debug' => true]);
        $this->mock(OrganizationSyncScheduler::class)->shouldReceive('schedule')
            ->andThrow(new \RuntimeException('Private infrastructure detail'));
        $this->actingAs(User::factory()->create())->postJson('/api/organization', ['source_url' => 'https://yandex.ru/maps/org/123456789/'])
            ->assertStatus(500)->assertDontSee('Private infrastructure detail')->assertDontSee('trace');
    }
}
