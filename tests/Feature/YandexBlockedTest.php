<?php

namespace Tests\Feature;

use App\Enums\ParsingStatus;
use App\Exceptions\YandexParserException;
use App\Jobs\ParseYandexOrganizationJob;
use App\Services\Yandex\OrganizationSyncService;
use App\Services\Yandex\YandexMapsParserInterface;
use Tests\Support\SyncTestCase;

class YandexBlockedTest extends SyncTestCase
{
    public function test_captcha_stops_after_one_attempt_and_exposes_only_safe_error(): void
    {
        $organization = $this->organization();
        $run = $organization->parsingRuns()->create(['status' => 'pending']);
        $this->mock(YandexMapsParserInterface::class)->shouldReceive('parse')->once()
            ->andThrow(new YandexParserException('YANDEX_BLOCKED', 'technical challenge details'));
        (new ParseYandexOrganizationJob($run->id))->handle(app(OrganizationSyncService::class));
        $this->assertSame(ParsingStatus::Blocked, $run->refresh()->status);
        $this->assertSame(1, $run->attempt);
        $this->assertDatabaseCount('organization_snapshots', 0);
        $this->actingAs($organization->user)->getJson('/api/parsing-runs/'.$run->id)->assertOk()
            ->assertJsonPath('data.error_code', 'YANDEX_BLOCKED')->assertJsonPath('data.can_retry', false)
            ->assertDontSee('technical challenge details')->assertDontSee('trace');
    }
}
