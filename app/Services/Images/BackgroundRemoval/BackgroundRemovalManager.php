<?php

namespace App\Services\Images\BackgroundRemoval;

use App\Contracts\Images\BackgroundRemovalDriver;

/**
 * Resolves the active background-removal driver from ONE config value
 * (`services.bg_removal.driver`, set via `BG_REMOVAL_DRIVER` in .env).
 * Swapping providers is a one-line .env change — no code edit, no
 * migration, no redeploy.
 *
 * `rembg` (self-hosted, §15.3) is the default. `photoroom`/`remove_bg`
 * (the earlier paid-API build, §15.2) remain fully wired and selectable —
 * left in place, unused, as a fallback that costs nothing to keep: if the
 * self-hosted model ever needs bypassing (a bad model update, the service
 * misbehaving), switching back is one `.env` line, not a rebuild.
 */
class BackgroundRemovalManager
{
    public function driver(): BackgroundRemovalDriver
    {
        return match ($this->driverName()) {
            'photoroom'  => app(PhotoroomDriver::class),
            'remove_bg'  => app(RemoveBgDriver::class),
            default      => app(RembgDriver::class),
        };
    }

    public function driverName(): string
    {
        return (string) config('services.bg_removal.driver', 'rembg');
    }
}
