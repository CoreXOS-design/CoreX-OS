<?php

namespace App\Events\TrainingVideos;

use Illuminate\Foundation\Events\Dispatchable;

/** AT-525 / AT-527 - fired when a batch of ad renders has finished: `ready` (every format made) or `failed` (the reason is on the rows). Ad Studio listens; polling AdRenderer::status() also works. */
class AdRenderFinished
{
    use Dispatchable;

    public function __construct(public readonly string $batchId, public readonly ?int $adRef, public readonly string $status)
    {
    }
}
