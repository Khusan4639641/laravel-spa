<?php

namespace App\DTO;

final readonly class ParsedReviewData
{
    public function __construct(
        public ?string $externalId, public ?string $author, public ?string $date,
        public string $text, public ?int $rating, public array $raw,
    ) {}

    public function contentHash(int $organizationId): string
    {
        $normalize = fn (?string $value) => preg_replace('/\s+/u', ' ', mb_strtolower(trim($value ?? '')));

        return hash('sha256', json_encode([
            $organizationId, $normalize($this->author), $this->date, $this->rating, $normalize($this->text),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
