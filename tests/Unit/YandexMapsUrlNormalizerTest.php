<?php

namespace Tests\Unit;

use App\Services\Yandex\YandexMapsUrlNormalizer;
use PHPUnit\Framework\TestCase;

class YandexMapsUrlNormalizerTest extends TestCase
{
    public function test_normalization_removes_tracking_and_preserves_identity(): void
    {
        $normalizer = new YandexMapsUrlNormalizer;
        $this->assertSame('https://yandex.uz/maps/org/123456789/', $normalizer->normalize('http://www.yandex.uz/maps/org/demo/123456789/?z=3'));
        $this->assertSame('123456789', $normalizer->extractExternalId('https://yandex.uz/maps/org/123456789/'));
    }
}
