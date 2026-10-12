<?php

namespace App\Exceptions;

/** A Title Deed Register refusal whose message is safe and clear enough to show the user as it stands. */
class TitleDeedException extends \RuntimeException
{
    public const GENERAL = 'general';
    public const ALREADY_COLLECTED = 'already_collected';
    public const LOCKED = 'locked';
    public const NEEDS_OVERRIDE = 'needs_override';

    public function __construct(string $message, public readonly string $reason = self::GENERAL)
    {
        parent::__construct($message);
    }
}
