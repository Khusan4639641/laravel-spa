<?php

namespace App\Services\Yandex;

use App\DTO\ParsedYandexResult;

interface YandexMapsParserInterface
{
    /** Progress callbacks carry parser observations, never persistence counts. */
    public function parse(string $url, ?callable $onProgress = null): ParsedYandexResult;
}
