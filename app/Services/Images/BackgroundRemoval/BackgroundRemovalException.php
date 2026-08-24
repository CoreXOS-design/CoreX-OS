<?php

namespace App\Services\Images\BackgroundRemoval;

/** Any failure from a background-removal driver — bad/missing key, timeout, quota, bad response. */
class BackgroundRemovalException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $driver,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
