<?php

namespace Tests\Unit;

use App\Services\Yandex\YandexMapsUrlNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class YandexMapsUrlNormalizerTest extends TestCase
{
    public function test_it_normalizes_supported_yandex_maps_org_url_with_query(): void
    {
        $normalizer = new YandexMapsUrlNormalizer;

        $url = 'http://yandex.uz/maps/org/test_place/123456789/?ll=69.2%2C41.3&z=16';

        $this->assertSame(
            'https://yandex.uz/maps/org/test_place/123456789/?ll=69.2%2C41.3&z=16',
            $normalizer->normalize($url),
        );
        $this->assertSame('123456789', $normalizer->extractExternalId($url));
    }

    public function test_it_accepts_yandex_subdomains_and_supported_locales(): void
    {
        $normalizer = new YandexMapsUrlNormalizer;

        $url = 'https://www.yandex.com/maps/org/demo/987654321';

        $this->assertSame($url, $normalizer->normalize($url));
        $this->assertSame('987654321', $normalizer->extractExternalId($url));
    }

    public function test_it_rejects_non_yandex_hosts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new YandexMapsUrlNormalizer)->normalize('https://example.com/maps/org/demo/1');
    }

    public function test_it_rejects_yandex_urls_that_are_not_organization_cards(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new YandexMapsUrlNormalizer)->normalize('https://yandex.ru/maps/213/moscow');
    }
}
