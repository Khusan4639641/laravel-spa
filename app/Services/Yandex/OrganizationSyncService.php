<?php

namespace App\Services\Yandex;

use App\DTO\ParsedReviewData;
use App\Exceptions\YandexParserException;
use App\Models\Organization;
use App\Models\OrganizationReview;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrganizationSyncService
{
    public function __construct(
        private readonly YandexMapsUrlNormalizer $urlNormalizer,
        private readonly YandexMapsParserService $parser,
    ) {
    }

    public function saveAndSync(User $user, string $sourceUrl): Organization
    {
        $normalizedUrl = $this->urlNormalizer->normalize($sourceUrl);
        $externalId = $this->urlNormalizer->extractExternalId($normalizedUrl);
        $organization = Organization::query()->firstOrNew(['user_id' => $user->id]);
        $urlChanged = $organization->exists && $organization->normalized_url !== $normalizedUrl;

        $organization->fill([
            'source_url' => $sourceUrl,
            'normalized_url' => $normalizedUrl,
            'yandex_external_id' => $externalId,
            'scrape_status' => 'pending',
            'scrape_error' => null,
        ]);
        $organization->save();

        if ($urlChanged) {
            $organization->reviews()->delete();
        }

        return $this->sync($organization);
    }

    public function sync(Organization $organization): Organization
    {
        $organization->forceFill([
            'scrape_status' => 'processing',
            'scrape_error' => null,
        ])->save();

        try {
            $parsed = $this->parser->parse($organization->normalized_url);
        } catch (YandexParserException $exception) {
            $this->markAsFailed($organization, $exception->getMessage(), $exception->rawError);

            throw $exception;
        } catch (Throwable $exception) {
            $this->markAsFailed($organization, 'Не удалось получить данные из Яндекс.Карт.', $exception->getMessage());

            throw new YandexParserException('Не удалось получить данные из Яндекс.Карт.', $exception->getMessage(), (int) $exception->getCode());
        }

        DB::transaction(function () use ($organization, $parsed): void {
            $organization->forceFill([
                'yandex_external_id' => $parsed->externalId ?? $organization->yandex_external_id,
                'title' => $parsed->title,
                'rating' => $parsed->rating,
                'ratings_count' => $parsed->ratingsCount,
                'reviews_count' => $parsed->reviewsCount > 0 ? $parsed->reviewsCount : count($parsed->reviews),
                'scrape_status' => 'success',
                'scrape_error' => null,
                'last_scraped_at' => now(),
                'raw_meta' => $parsed->meta,
            ])->save();

            foreach (array_slice($parsed->reviews, 0, 600) as $review) {
                $this->upsertReview($organization, $review);
            }
        });

        return $organization->refresh();
    }

    private function upsertReview(Organization $organization, ParsedReviewData $review): void
    {
        $contentHash = $this->contentHash($review);

        $reviewModel = null;

        if ($review->externalId !== null) {
            $reviewModel = OrganizationReview::query()
                ->where('organization_id', $organization->id)
                ->where('external_id', $review->externalId)
                ->first();
        }

        if ($reviewModel === null) {
            $reviewModel = OrganizationReview::query()
                ->where('organization_id', $organization->id)
                ->where('content_hash', $contentHash)
                ->first();
        }

        $reviewModel ??= new OrganizationReview(['organization_id' => $organization->id]);

        $reviewModel->fill([
            'external_id' => $review->externalId ?? $reviewModel->external_id,
            'content_hash' => $contentHash,
            'author' => $review->author,
            'review_date' => $this->parseDate($review->date),
            'text' => $review->text,
            'rating' => $review->rating,
            'raw_payload' => $review->raw,
        ]);

        $reviewModel->save();
    }

    private function contentHash(ParsedReviewData $review): string
    {
        return hash('sha256', implode('|', [
            mb_strtolower($review->externalId ?? ''),
            mb_strtolower($review->author ?? ''),
            $review->date ?? '',
            mb_strtolower($review->text ?? ''),
            (string) ($review->rating ?? ''),
        ]));
    }

    private function parseDate(?string $date): ?string
    {
        if ($date === null) {
            return null;
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function markAsFailed(Organization $organization, string $safeMessage, ?string $rawError): void
    {
        $organization->forceFill([
            'scrape_status' => 'failed',
            'scrape_error' => $safeMessage,
            'last_scraped_at' => now(),
        ])->save();

        Log::warning('Yandex organization sync failed', [
            'organization_id' => $organization->id,
            'safe_message' => $safeMessage,
            'raw_error' => $rawError,
        ]);
    }
}
