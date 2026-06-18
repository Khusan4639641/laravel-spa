<?php

namespace App\DTO;

final readonly class ParsedOrganizationData
{
    /**
     * @param  array<int, ParsedReviewData>  $reviews
     */
    public function __construct(
        public ?string $title,
        public ?float $rating,
        public int $ratingsCount,
        public int $reviewsCount,
        public ?string $externalId,
        public array $meta,
        public array $reviews,
    ) {
    }

    public static function fromArray(array $payload): self
    {
        $organization = is_array($payload['organization'] ?? null)
            ? $payload['organization']
            : [];

        $reviews = array_values(array_filter(array_map(
            fn (mixed $review): ?ParsedReviewData => is_array($review) ? ParsedReviewData::fromArray($review) : null,
            is_array($payload['reviews'] ?? null) ? $payload['reviews'] : [],
        )));

        $rating = isset($organization['rating']) && is_numeric($organization['rating'])
            ? round((float) $organization['rating'], 2)
            : null;

        return new self(
            title: self::nullableString($organization['title'] ?? null),
            rating: $rating,
            ratingsCount: self::integerCount($organization['ratings_count'] ?? 0),
            reviewsCount: self::integerCount($organization['reviews_count'] ?? count($reviews)),
            externalId: self::nullableString($organization['external_id'] ?? null),
            meta: is_array($organization['meta'] ?? null) ? $organization['meta'] : [],
            reviews: $reviews,
        );
    }

    private static function integerCount(mixed $value): int
    {
        if (is_string($value)) {
            $value = preg_replace('/[^\d]/', '', $value);
        }

        return max(0, (int) $value);
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
