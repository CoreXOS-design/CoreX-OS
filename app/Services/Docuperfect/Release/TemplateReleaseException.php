<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Release;

use RuntimeException;

/** A refusal with a plain-language message a first-day user understands. $status is the HTTP status a controller answers with (403 / 404 / 422). */
final class TemplateReleaseException extends RuntimeException
{
    /** @param list<string> $details */
    public function __construct(string $message, public readonly int $status = 422, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
