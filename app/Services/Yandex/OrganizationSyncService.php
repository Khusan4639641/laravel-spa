<?php

namespace App\Services\Yandex;

use App\Enums\ParsingStatus;
use App\Exceptions\YandexParserException;
use App\Models\Organization;
use App\Models\ParsingRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrganizationSyncService
{
    public function __construct(
        private readonly YandexMapsParserInterface $parser,
        private readonly YandexMapsResultValidator $validator,
        private readonly ReviewPersister $reviews,
    ) {}

    public function execute(int $runId): void
    {
        $run = ParsingRun::query()->with('organization')->findOrFail($runId);
        if (! $run->status->isActive()) {
            return;
        }
        if ($run->attempt >= config('yandex.max_retries')) {
            $this->fail($runId, new YandexParserException('YANDEX_NETWORK_ERROR', 'Maximum parser attempts reached.'));

            return;
        }
        $started = microtime(true);
        $run->update([
            'status' => ParsingStatus::Processing, 'started_at' => $run->started_at ?? now(),
            'attempt' => $run->attempt + 1, 'progress' => 5, 'reviews_found' => 0, 'reviews_saved' => 0,
            'current_step' => 'Открываем Яндекс.Карты', 'error_code' => null, 'error_message' => null,
        ]);
        $run->organization->update(['status' => 'processing', 'last_sync_started_at' => now()]);
        try {
            $this->log($run, $started);
            // No database transaction is held during browser/network activity.
            $parsed = $this->parser->parse($run->organization->normalized_url, function (array $event) use ($run): void {
                $run->update([
                    'progress' => max($run->progress, min(80, max(5, (int) ($event['progress'] ?? 5)))),
                    'reviews_found' => max($run->reviews_found, min(2000, (int) ($event['reviews_found'] ?? 0))),
                    'current_step' => match ($event['step'] ?? '') {
                        'loading_reviews' => 'Загружаем отзывы',
                        'validating' => 'Проверяем результат',
                        default => 'Загружаем карточку организации',
                    },
                ]);
            });
            $this->validator->validate($parsed->payload);
            if ($parsed->organization->externalId !== app(YandexMapsUrlNormalizer::class)->extractExternalId($run->organization->normalized_url)) {
                throw new YandexParserException('YANDEX_SOURCE_STRUCTURE_CHANGED', 'Organization identity changed during navigation.');
            }
            $run->update(['progress' => 85, 'reviews_found' => count($parsed->reviews), 'current_step' => 'Сохраняем отзывы']);
            DB::transaction(function () use ($run, $parsed): void {
                $lockedRun = ParsingRun::query()->lockForUpdate()->findOrFail($run->id);
                if ($lockedRun->status !== ParsingStatus::Processing) {
                    return;
                }
                $organization = Organization::query()->lockForUpdate()->findOrFail($run->organization_id);
                $fields = ['title', 'rating', 'ratings_count', 'reviews_count'];
                $before = $organization->only($fields);
                $data = $parsed->organization;
                $organization->update([
                    'title' => $data->title, 'rating' => $data->rating, 'ratings_count' => $data->ratingsCount,
                    'reviews_count' => $data->reviewsCount, 'external_id' => $data->externalId,
                    'status' => 'ready', 'last_error' => null, 'last_successful_sync_at' => now(),
                    'last_sync_finished_at' => now(), 'raw_meta' => $parsed->meta,
                ]);
                $saved = $this->reviews->persist($organization, $parsed->reviews);
                $changes = [];
                foreach ($fields as $field) {
                    if ($before[$field] != $organization->$field) {
                        $changes[$field] = ['from' => $before[$field], 'to' => $organization->$field];
                    }
                }
                $organization->snapshots()->create([
                    ...$organization->only($fields), 'parsing_run_id' => $run->id,
                    'payload' => ['changes' => $changes, 'extraction' => $parsed->meta],
                ]);
                $lockedRun->update([
                    'status' => ParsingStatus::Completed, 'progress' => 100, 'finished_at' => now(),
                    'reviews_saved' => $saved, 'current_step' => 'Синхронизация завершена',
                    'metadata' => [...($run->metadata ?? []), ...$parsed->meta, 'changes' => $changes],
                ]);
            }, 3);
            $this->log($run->refresh(), $started);
        } catch (Throwable $exception) {
            $retry = $exception instanceof YandexParserException && $exception->retryable()
                && $run->attempt < config('yandex.max_retries');
            $this->fail($run->id, $exception, $retry);
            throw $exception;
        }
    }

    public function fail(int $runId, Throwable $exception, bool $retry = false): void
    {
        $code = $exception instanceof YandexParserException ? $exception->errorCode : 'INTERNAL_ERROR';
        $message = $exception instanceof YandexParserException ? $exception->getMessage() : 'Внутренняя ошибка синхронизации. Попробуйте позже.';
        $status = $retry ? ParsingStatus::Pending : ($code === 'YANDEX_BLOCKED' ? ParsingStatus::Blocked : ParsingStatus::Failed);
        $run = DB::transaction(function () use ($runId, $code, $message, $status, $retry): ?ParsingRun {
            $run = ParsingRun::query()->lockForUpdate()->find($runId);
            if (! $run || ! $run->status->isActive()) {
                return null;
            }
            $run->update([
                'status' => $status, 'finished_at' => $retry ? null : now(), 'error_code' => $code,
                'error_message' => $message, 'current_step' => $retry ? 'Ожидаем повторной попытки' : 'Синхронизация остановлена',
            ]);
            // Previous successful data is deliberately retained.
            $run->organization()->update([
                'status' => $status->value, 'last_error' => $message,
                'last_sync_finished_at' => $retry ? null : now(),
            ]);

            return $run;
        });
        if (! $run) {
            return;
        }
        // A log storage failure must not roll back the terminal business state.
        Log::warning('yandex.sync.failed', [
            'organization_id' => $run->organization_id, 'parsing_run_id' => $run->id,
            'status' => $status->value, 'attempt' => $run->attempt, 'error_code' => $code,
            'reviews_found' => $run->reviews_found, 'reviews_saved' => $run->reviews_saved,
            'duration_ms' => $run->started_at ? (int) $run->started_at->diffInMilliseconds(now()) : 0,
            'exception' => get_class($exception),
            'detail' => $exception instanceof YandexParserException ? $exception->detail : 'See internal exception report',
            'trace' => $exception->getTraceAsString(),
        ]);
        if (! $exception instanceof YandexParserException) {
            report($exception);
        }
    }

    private function log(ParsingRun $run, float $started): void
    {
        Log::info('yandex.sync.'.$run->status->value, [
            'organization_id' => $run->organization_id, 'parsing_run_id' => $run->id,
            'status' => $run->status->value, 'attempt' => $run->attempt,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'reviews_found' => $run->reviews_found, 'reviews_saved' => $run->reviews_saved, 'error_code' => $run->error_code,
        ]);
    }
}
