<?php

namespace App\Services\Yandex;

use InvalidArgumentException;

class YandexMapsUrlNormalizer
{
    /**
     * @var array<int, string>
     */
    private array $allowedHosts = [
        'yandex.ru',
        'yandex.com',
        'yandex.uz',
        'yandex.kz',
        'yandex.by',
        'yandex.kg',
        'yandex.tj',
        'yandex.tm',
        'yandex.az',
        'yandex.ge',
        'yandex.md',
        'yandex.com.tr',
    ];

    public function normalize(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ($parts === false || empty($parts['host']) || empty($parts['path'])) {
            throw new InvalidArgumentException('Введите полную ссылку на карточку организации в Яндекс.Картах.');
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $path = $parts['path'];

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Ссылка должна использовать HTTP или HTTPS.');
        }

        if (! $this->isAllowedHost($host)) {
            throw new InvalidArgumentException('Введите ссылку на домене Яндекс.Карт.');
        }

        if (! str_contains($path, '/maps/') || ! str_contains($path, '/org/')) {
            throw new InvalidArgumentException('Ссылка должна вести на карточку организации Яндекс.Карт.');
        }

        $normalized = 'https://'.$host.$this->normalizePath($path);

        if (! empty($parts['query'])) {
            $normalized .= '?'.$parts['query'];
        }

        return $normalized;
    }

    public function extractExternalId(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        if (preg_match('~/org/[^/]+/(\d+)~', $path, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function isAllowedHost(string $host): bool
    {
        foreach ($this->allowedHosts as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private function normalizePath(string $path): string
    {
        $path = preg_replace('~/+~', '/', $path) ?: $path;

        return $path === '' ? '/' : $path;
    }
}
