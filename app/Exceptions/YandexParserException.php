<?php

namespace App\Exceptions;

use RuntimeException;

class YandexParserException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $rawError = null,
        int $code = 0,
    ) {
        parent::__construct($message, $code);
    }
}
