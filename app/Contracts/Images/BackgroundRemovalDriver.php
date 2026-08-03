<?php

namespace App\Contracts\Images;

use App\Services\Images\BackgroundRemoval\BackgroundRemovalException;
use App\Services\Images\BackgroundRemoval\BackgroundRemovalResult;

/**
 * Provider-agnostic contract for an AI background-segmentation API.
 * Photoroom and remove.bg have an identical integration shape (multipart
 * POST, API-key header, PNG bytes back) — this interface is what makes the
 * provider a config choice (`BG_REMOVAL_DRIVER` in .env), not an
 * architecture choice. See ad-manager.md §15.2.
 */
interface BackgroundRemovalDriver
{
    /** Short machine name — matches the BG_REMOVAL_DRIVER value and is logged/stored verbatim. */
    public function name(): string;

    /**
     * @param  string  $absolutePath  Absolute filesystem path to the source photo.
     * @throws BackgroundRemovalException on any failure (bad/missing key, timeout, quota, bad response).
     */
    public function removeBackground(string $absolutePath): BackgroundRemovalResult;
}
