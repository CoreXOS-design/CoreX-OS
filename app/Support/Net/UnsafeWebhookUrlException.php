<?php

declare(strict_types=1);

namespace App\Support\Net;

use RuntimeException;

/** A webhook address was refused by SafeWebhookUrl. The message is plain words, safe to show a person. */
class UnsafeWebhookUrlException extends RuntimeException
{
    /**
     * @param bool $permanent false when the host merely failed to resolve right now (a delivery may be retried);
     *                        true when the address itself is never allowed (private, bad scheme, …).
     */
    public function __construct(string $message, public readonly bool $permanent = true)
    {
        parent::__construct($message);
    }
}
