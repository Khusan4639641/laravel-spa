<?php

namespace App\Jobs\Middleware;

use App\Jobs\ParseYandexOrganizationJob;
use Illuminate\Support\Facades\Cache;

class LimitYandexConcurrency
{
    public function handle(ParseYandexOrganizationJob $job, callable $next): void
    {
        $ttl = $job->timeout + 30;
        $runLock = Cache::lock('yandex:run:'.$job->parsingRunId, $ttl);
        if (! $runLock->get()) {
            $job->release(15);

            return;
        }
        try {
            for ($slot = 0; $slot < config('yandex.max_concurrent_jobs'); $slot++) {
                $lock = Cache::lock('yandex:browser:'.$slot, $ttl);
                if ($lock->get()) {
                    try {
                        $next($job);
                    } finally {
                        $lock->release();
                    }

                    return;
                }
            }
            $job->release(15);
        } finally {
            $runLock->release();
        }
    }
}
