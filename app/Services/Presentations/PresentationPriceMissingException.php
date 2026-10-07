<?php

namespace App\Services\Presentations;

/**
 * Thrown by SnapshotLinkService::createLink() (the one place every share /
 * send path creates a seller link) when the presentation has no price.
 * The message is the plain-language "what is missing and what to do" text
 * from PresentationPriceReadiness, safe to show an agent verbatim.
 */
final class PresentationPriceMissingException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
