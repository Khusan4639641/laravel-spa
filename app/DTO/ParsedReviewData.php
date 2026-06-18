<?php

namespace App\DTO;

final readonly class ParsedReviewData
{
    public function __construct(
        public ?string $externalId,
        public ?string $author,
        public ?string $date,
        public ?string $text,
        public ?int $rating,
        public array $raw,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $rating = isset($data['rating']) && is_numeric($data['rating'])
            ? max(1, min(5, (int) $data['rating']))
            : null;

        return new self(
            externalId: self::nullableString($data['external_id'] ?? null),
            author: self::nullableString($data['author'] ?? null),
            date: self::nullableString($data['date'] ?? null),
            text: self::nullableString($data['text'] ?? null),
            rating: $rating,
            raw: is_array($data['raw'] ?? null) ? $data['raw'] : $data,
        );
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
