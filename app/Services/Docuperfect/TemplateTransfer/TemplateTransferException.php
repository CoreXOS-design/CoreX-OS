<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\TemplateTransfer;

use RuntimeException;

/**
 * A template-transfer problem whose message is written for the person at the
 * screen (plain language, no paths / SQL). Anything else is caught by the
 * caller and shown as a generic "nothing was created" message; the technical
 * detail goes to the log only.
 */
final class TemplateTransferException extends RuntimeException
{
    /** @param string[] $details extra plain-language lines (e.g. every unsafe item found) */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
