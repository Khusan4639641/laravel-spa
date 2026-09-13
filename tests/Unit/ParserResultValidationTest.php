<?php

namespace Tests\Unit;

use App\DTO\ParsedYandexResult;
use App\Exceptions\YandexParserException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ParserResultValidationTest extends TestCase
{
    private function fixture(string $name = 'valid'): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/'.$name.'-yandex-response.json')), true);
    }

    public function test_valid_result_retains_exact_declared_counts(): void
    {
        $result = ParsedYandexResult::fromArray($this->fixture());
        $this->assertSame(1234, $result->organization->ratingsCount);
        $this->assertSame(4.7, $result->organization->rating);
        $this->assertCount(2, $result->reviews);
    }

    public static function malformedFields(): array
    {
        return [
            ['organization', null], ['organization.title', ''], ['organization.rating', 8],
            ['organization.rating', null], ['organization.ratings_count', -1], ['organization.ratings_count', '1.2K'],
            ['organization.ratings_count', '1234'], ['organization.reviews_count', null], ['reviews', []],
            ['reviews.0.rating', 9], ['reviews.0.date', '2026-02-31'], ['reviews.0.text', null],
            ['meta.organization_found', false], ['meta.reviews_container_found', false], ['meta.html_length', 0],
            ['meta.stop_reason', 'timeout'], ['meta.reviews_loaded', 8], ['meta.source', 'unknown'],
        ];
    }

    #[DataProvider('malformedFields')]
    public function test_invalid_extraction_is_never_silently_coerced_to_success(string $field, mixed $value): void
    {
        $payload = $this->fixture();
        data_set($payload, $field, $value);
        try {
            ParsedYandexResult::fromArray($payload);
            $this->fail('Expected validation failure');
        } catch (YandexParserException $exception) {
            $this->assertSame('YANDEX_SOURCE_STRUCTURE_CHANGED', $exception->errorCode);
        }
    }

    public function test_blocked_fixture_has_a_distinct_non_retryable_code(): void
    {
        try {
            ParsedYandexResult::fromArray($this->fixture('blocked'));
            $this->fail();
        } catch (YandexParserException $exception) {
            $this->assertSame('YANDEX_BLOCKED', $exception->errorCode);
            $this->assertFalse($exception->retryable());
        }
    }

    public function test_empty_invalid_fixture_fails(): void
    {
        $this->expectException(YandexParserException::class);
        ParsedYandexResult::fromArray($this->fixture('invalid'));
    }

    public function test_explicit_zero_reviews_is_valid_when_review_container_was_found(): void
    {
        $payload = $this->fixture();
        $payload['reviews'] = [];
        $payload['organization']['reviews_count'] = 0;
        $payload['organization']['ratings_count'] = 0;
        $payload['organization']['rating'] = null;
        $payload['meta']['reviews_loaded'] = 0;
        $this->assertCount(0, ParsedYandexResult::fromArray($payload)->reviews);
    }
}
