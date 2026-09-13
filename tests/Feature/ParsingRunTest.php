<?php

namespace Tests\Feature;

use App\DTO\ParsedYandexResult;
use App\Enums\ParsingStatus;
use App\Exceptions\YandexParserException;
use App\Jobs\Middleware\LimitYandexConcurrency;
use App\Jobs\ParseYandexOrganizationJob;
use App\Services\Yandex\OrganizationSyncService;
use App\Services\Yandex\YandexMapsParserInterface;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\Support\SyncTestCase;

class ParsingRunTest extends SyncTestCase
{
    public function test_network_error_retries_three_times_with_backoff_and_then_fails(): void
    {
        $organization = $this->organization();
        $run = $organization->parsingRuns()->create(['status' => 'pending']);
        $this->mock(YandexMapsParserInterface::class)->shouldReceive('parse')->times(3)
            ->andThrow(new YandexParserException('YANDEX_NETWORK_ERROR', 'timeout'));
        foreach ([10, 30, null] as $delay) {
            $job = new ParseYandexOrganizationJob($run->id);
            $transport = \Mockery::mock(Job::class);
            if ($delay !== null) {
                $transport->shouldReceive('release')->once()->with($delay);
            } else {
                $transport->shouldReceive('fail')->once();
            }
            $job->setJob($transport);
            $job->handle(app(OrganizationSyncService::class));
        }
        $this->assertSame(3, $run->refresh()->attempt);
        $this->assertSame(ParsingStatus::Failed, $run->status);
        $this->assertSame('YANDEX_NETWORK_ERROR', $run->error_code);
    }

    public function test_progress_is_visible_before_persistence(): void
    {
        $organization = $this->organization();
        $run = $organization->parsingRuns()->create(['status' => 'pending']);
        $payload = $this->payload();
        $this->actingAs($organization->user);
        $this->mock(YandexMapsParserInterface::class)->shouldReceive('parse')->andReturnUsing(function ($url, $progress) use ($run, $payload) {
            $progress(['progress' => 65, 'step' => 'loading_reviews', 'reviews_found' => 2]);
            $this->getJson('/api/parsing-runs/'.$run->id)->assertOk()->assertJsonPath('data.status', 'processing')
                ->assertJsonPath('data.progress', 65)->assertJsonPath('data.reviews_found', 2)->assertJsonPath('data.reviews_saved', 0);

            return ParsedYandexResult::fromArray($payload);
        });
        app(OrganizationSyncService::class)->execute($run->id);
    }

    public function test_browser_slots_limit_concurrency_without_consuming_parser_attempts(): void
    {
        config(['yandex.max_concurrent_jobs' => 1]);
        $lock = Cache::lock('yandex:browser:0', 60);
        $lock->get();
        $job = new ParseYandexOrganizationJob(123);
        $transport = \Mockery::mock(Job::class);
        $transport->shouldReceive('release')->once()->with(15);
        $job->setJob($transport);
        (new LimitYandexConcurrency)->handle($job, fn () => $this->fail('No browser slot available'));
        $lock->release();
        $called = false;
        (new LimitYandexConcurrency)->handle($job, function () use (&$called) {
            $called = true;
        });
        $this->assertTrue($called);
        $this->assertTrue(Cache::lock('yandex:browser:0', 60)->get());
    }

    public function test_abandoned_run_recovery_does_not_touch_completed_data(): void
    {
        $organization = $this->organization();
        $this->synchronize($organization, $this->payload());
        $run = $organization->parsingRuns()->create(['status' => 'processing']);
        $run->timestamps = false;
        $run->update(['updated_at' => now()->subHour()]);
        $this->artisan('yandex:recover-stale')->assertSuccessful();
        $this->assertSame(ParsingStatus::Failed, $run->refresh()->status);
        $this->assertDatabaseCount('organization_reviews', 2);
    }

    public function test_log_failure_cannot_roll_back_a_terminal_run_state(): void
    {
        $organization = $this->organization();
        $run = $organization->parsingRuns()->create(['status' => 'processing']);
        Log::shouldReceive('warning')->once()->andThrow(new \RuntimeException('Log disk unavailable'));
        try {
            app(OrganizationSyncService::class)->fail($run->id, new YandexParserException('YANDEX_BLOCKED'));
            $this->fail('Expected log failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Log disk unavailable', $exception->getMessage());
        }
        $this->assertSame(ParsingStatus::Blocked, $run->refresh()->status);
        $this->assertSame('blocked', $organization->refresh()->status);
    }
}
