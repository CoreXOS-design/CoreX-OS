<?php

namespace App\Services\Desk;

/** Too many wrong codes from one support user; $retryAfter is seconds. */
class DeskRateLimitedException extends \RuntimeException
{
    public function __construct(public int $retryAfter)
    {
        parent::__construct('Too many wrong codes.');
    }
}
