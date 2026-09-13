<?php

namespace App\Services\Yandex;

use App\Enums\ParsingStatus;
use App\Jobs\ParseYandexOrganizationJob;
use App\Models\Organization;
use App\Models\ParsingRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class OrganizationSyncScheduler
{
    public function __construct(private readonly YandexMapsUrlNormalizer $normalizer) {}

    public function schedule(User $user, ?string $sourceUrl = null): ParsingRun
    {
        return DB::transaction(function () use ($user, $sourceUrl): ParsingRun {
            // Lock a row that exists even before the first organization is created.
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            $current = $owner->selectedOrganization();
            if ($current?->parsingRuns()->whereIn('status', ['pending', 'processing'])->exists()) {
                throw new ConflictHttpException('Синхронизация организации уже выполняется.');
            }
            $organization = $current;
            if ($sourceUrl !== null) {
                $url = $this->normalizer->normalize($sourceUrl);
                $externalId = $this->normalizer->extractExternalId($url);
                $organization = $owner->organizations()->where('external_id', $externalId)->first()
                    ?? $owner->organizations()->where('normalized_url', $url)->first()
                    ?? new Organization(['user_id' => $owner->id]);
                $organization->fill(['source_url' => $sourceUrl, 'normalized_url' => $url, 'external_id' => $externalId]);
            }
            abort_unless($organization, 404, 'Сначала сохраните ссылку на организацию.');
            if ($organization->exists && $organization->parsingRuns()->whereIn('status', ['pending', 'processing'])->exists()) {
                throw new ConflictHttpException('Синхронизация организации уже выполняется.');
            }
            $organization->fill(['status' => 'pending', 'last_error' => null])->save();
            $owner->forceFill(['current_organization_id' => $organization->id])->save();
            $run = $organization->parsingRuns()->create([
                'status' => ParsingStatus::Pending, 'queued_at' => now(), 'current_step' => 'В очереди',
                'metadata' => ['source_url' => $organization->normalized_url],
            ]);
            // The database queue uses the same connection: no lost run between commit and dispatch.
            ParseYandexOrganizationJob::dispatch($run->id)->beforeCommit();

            return $run;
        }, 3);
    }
}
