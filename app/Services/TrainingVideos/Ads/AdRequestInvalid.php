<?php

namespace App\Services\TrainingVideos\Ads;

/** AT-525 / AT-527 - an ad request the renderer will not render; `problems` are plain sentences the Ad Studio editor can show as they are. */
class AdRequestInvalid extends \InvalidArgumentException
{
    /** @param array<int,string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(implode(' ', $problems));
    }
}
