<?php

namespace App\Console\Commands;

use App\Models\ParsingRun;
use App\Services\Yandex\OrganizationSyncService;
use Illuminate\Console\Command;

class RecoverStaleParsingRuns extends Command
{
    protected $signature = 'yandex:recover-stale';

    protected $description = 'Mark abandoned parsing runs as failed without removing their last successful data';

    public function handle(OrganizationSyncService $sync): int
    {
        $cutoff = now()->subSeconds((int) ceil(config('yandex.total_timeout_ms') / 1000) + 120);
        ParsingRun::query()->where(function ($query) use ($cutoff): void {
            $query->where(fn ($query) => $query->where('status', 'processing')->where('updated_at', '<', $cutoff))
                ->orWhere(fn ($query) => $query->where('status', 'pending')->where('queued_at', '<', now()->subHours(6)));
        })->eachById(function ($run) use ($sync): void {
            $sync->fail($run->id, new \RuntimeException('Worker lease or queue deadline expired.'));
            $this->line('Marked run '.$run->id.' as failed.');
        });

        return self::SUCCESS;
    }
}
