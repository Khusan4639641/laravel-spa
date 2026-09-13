<?php

namespace App\Enums;

enum ParsingStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Blocked = 'blocked';

    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }
}
