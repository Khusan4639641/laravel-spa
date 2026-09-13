<?php

namespace App\Services\Yandex;

use App\Exceptions\YandexParserException;
use Illuminate\Support\Facades\Validator;

class YandexMapsResultValidator
{
    public function validate(array $payload): void
    {
        if (($payload['error']['code'] ?? null) === 'YANDEX_BLOCKED' || ($payload['meta']['blocked'] ?? false)) {
            throw new YandexParserException('YANDEX_BLOCKED');
        }
        $validator = Validator::make($payload, [
            'organization' => ['required', 'array'],
            'organization.external_id' => ['required', 'string', 'regex:/^[0-9]+$/', 'max:255'],
            'organization.title' => ['required', 'string', 'max:255'],
            'organization.rating' => ['present', 'nullable', 'numeric', 'between:1,5'],
            'organization.ratings_count' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'organization.reviews_count' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'reviews' => ['present', 'array', 'max:2000'],
            'reviews.*.external_id' => ['nullable', 'string', 'max:255'],
            'reviews.*.author' => ['present', 'nullable', 'string', 'max:255'],
            'reviews.*.date' => ['present', 'nullable', 'date_format:Y-m-d'],
            'reviews.*.text' => ['present', 'string', 'max:100000'],
            'reviews.*.rating' => ['present', 'nullable', 'integer', 'between:1,5'],
            'reviews.*.raw' => ['sometimes', 'array'],
            'meta' => ['required', 'array'],
            'meta.source' => ['required', 'in:yandex_maps'],
            'meta.organization_found' => ['required', 'accepted'],
            'meta.reviews_container_found' => ['required', 'accepted'],
            'meta.html_length' => ['required', 'integer', 'min:100'],
            'meta.reviews_loaded' => ['required', 'integer', 'min:0'],
            'meta.stop_reason' => ['required', 'in:declared_count,limit,exhausted'],
        ]);
        if ($validator->fails()) {
            throw new YandexParserException('YANDEX_SOURCE_STRUCTURE_CHANGED', implode(', ', array_keys($validator->errors()->toArray())));
        }
        $organization = $payload['organization'];
        $reviews = $payload['reviews'];
        if (! is_int($organization['ratings_count']) || ! is_int($organization['reviews_count'])
            || ($organization['rating'] !== null && ! is_int($organization['rating']) && ! is_float($organization['rating']))
            || ($organization['ratings_count'] > 0 && $organization['rating'] === null)
            || ($organization['reviews_count'] > 0 && $reviews === [])
            || count($reviews) !== $payload['meta']['reviews_loaded']) {
            throw new YandexParserException('YANDEX_SOURCE_STRUCTURE_CHANGED', 'Missing exact counts, rating, or expected reviews.');
        }
        foreach ($reviews as $review) {
            if (($review['rating'] !== null && ! is_int($review['rating']))
                || (trim($review['text']) === '' && $review['rating'] === null)) {
                throw new YandexParserException('YANDEX_SOURCE_STRUCTURE_CHANGED', 'Invalid review content/rating.');
            }
        }
    }
}
