<?php

namespace App\Services\Images\BackgroundRemoval;

/** A successful segmentation call — the raw PNG bytes plus what it cost, if the provider says. */
final class BackgroundRemovalResult
{
    public function __construct(
        public readonly string $pngContents,
        public readonly string $driver,
        public readonly ?string $costCredits = null,
    ) {
    }
}
