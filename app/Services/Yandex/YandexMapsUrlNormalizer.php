<?php

namespace App\Services\Yandex;

use InvalidArgumentException;

class YandexMapsUrlNormalizer
{
    public const HOSTS = ['yandex.ru', 'yandex.com', 'yandex.uz', 'yandex.kz', 'yandex.by', 'yandex.com.tr'];

    public function normalize(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        $host = preg_replace('/^www\./', '', strtolower($parts['host'] ?? ''));
        if (! filter_var($url, FILTER_VALIDATE_URL)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ! in_array($host, self::HOSTS, true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ! preg_match('~^/maps/org/(?:[a-zA-Z0-9_%\-]+/)?([0-9]+)(?:/(?:reviews/?)?)?$~D', $parts['path'] ?? '', $match)) {
            throw new InvalidArgumentException('Введите полную ссылку на карточку организации Яндекс.Карт, например https://yandex.ru/maps/org/name/123/.');
        }

        // Query parameters must not change organization identity or drive arbitrary navigation.
        return 'https://'.$host.'/maps/org/'.$match[1].'/';
    }

    public function extractExternalId(string $url): string
    {
        preg_match('~/org/([0-9]+)/$~', $this->normalize($url), $match);

        return $match[1];
    }
}
