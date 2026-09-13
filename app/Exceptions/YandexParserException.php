<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class YandexParserException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly string $detail = '', ?Throwable $previous = null)
    {
        parent::__construct(match ($errorCode) {
            'YANDEX_BLOCKED' => 'Яндекс.Карты ограничили автоматический доступ или запросили CAPTCHA. Повторные попытки остановлены.',
            'YANDEX_SOURCE_STRUCTURE_CHANGED' => 'Структура страницы Яндекс.Карт изменилась. Требуется обновление парсера.',
            'YANDEX_PARSER_UNAVAILABLE' => 'Парсер недоступен. Проверьте настройку Chromium и worker.',
            default => 'Не удалось получить данные Яндекс.Карт. Источник временно недоступен.',
        }, 0, $previous);
    }

    public function retryable(): bool
    {
        return $this->errorCode === 'YANDEX_NETWORK_ERROR';
    }
}
