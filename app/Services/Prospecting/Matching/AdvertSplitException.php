<?php

declare(strict_types=1);

namespace App\Services\Prospecting\Matching;

/** A split (or its undo) that cannot be done: the HTTP status and a plain-words message for the agent. */
final class AdvertSplitException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
