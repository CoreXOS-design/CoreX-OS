<?php

namespace App\Services\TrainingVideos;

use RuntimeException;

/** The file has no video stream CoreX can read (spec §8 U4). */
class UnreadableVideoException extends RuntimeException
{
    public const MESSAGE = "That file isn't a video CoreX can read. Try exporting it as MP4.";

    public function __construct(string $message = self::MESSAGE, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
