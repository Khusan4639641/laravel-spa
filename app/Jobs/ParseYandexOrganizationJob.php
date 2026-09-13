<?php

namespace App\Jobs;

use App\Enums\ParsingStatus;
use App\Jobs\Middleware\LimitYandexConcurrency;
use App\Models\ParsingRun;
use App\Services\Yandex\OrganizationSyncService;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ParseYandexOrganizationJob implements ShouldQueue
{
    use Queueable;

    // Queue reservations include waiting for a browser slot. Parser attempts are bounded separately.
    public int $tries = 240;

    public int $timeout;

    public bool $failOnTimeout = true;

    public int $deadline;

    public function __construct(public int $parsingRunId)
    {
        $this->timeout = (int) ceil(config('yandex.total_timeout_ms') / 1000) + 30;
        $this->deadline = now()->addHours(6)->timestamp;
        $this->onConnection(config('yandex.queue_connection'));
        $this->onQueue(config('yandex.queue'));
    }

    public function middleware(): array
    {
        return [new LimitYandexConcurrency];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->setTimestamp($this->deadline);
    }

    public function backoff(): array
    {
        return [10, 30, 120];
    }

    public function handle(OrganizationSyncService $sync): void
    {
        try {
            $sync->execute($this->parsingRunId);
        } catch (Throwable $exception) {
            $run = ParsingRun::query()->find($this->parsingRunId);
            if ($run?->status === ParsingStatus::Pending) {
                $this->release($this->backoff()[min(max($run->attempt - 1, 0), 2)]);
            } else {
                $this->fail($exception);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(OrganizationSyncService::class)->fail($this->parsingRunId,
            $exception ?? new \RuntimeException('Queue execution failed.'));
    }
}
