<?php

namespace App\DTO;

final readonly class ParsedOrganizationData
{
    public function __construct(
        public string $title, public ?float $rating, public int $ratingsCount,
        public int $reviewsCount, public string $externalId,
    ) {}
}
