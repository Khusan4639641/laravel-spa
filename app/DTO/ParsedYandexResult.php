<?php

namespace App\DTO;

use App\Services\Yandex\YandexMapsResultValidator;

final readonly class ParsedYandexResult
{
    private function __construct(
        public ParsedOrganizationData $organization,
        /** @var list<ParsedReviewData> */
        public array $reviews, public array $meta, public array $payload,
    ) {}

    public static function fromArray(array $payload): self
    {
        // Validate before casting; malformed values must never silently become zero.
        (new YandexMapsResultValidator)->validate($payload);
        $organization = $payload['organization'];

        return new self(
            new ParsedOrganizationData(trim($organization['title']), $organization['rating'],
                $organization['ratings_count'], $organization['reviews_count'], $organization['external_id']),
            array_map(fn (array $review) => new ParsedReviewData(
                $review['external_id'] ?? null, $review['author'], $review['date'],
                $review['text'], $review['rating'], $review['raw'] ?? [],
            ), $payload['reviews']),
            $payload['meta'], $payload,
        );
    }
}
