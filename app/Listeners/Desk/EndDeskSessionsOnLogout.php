<?php

namespace App\Listeners\Desk;

use App\Models\DeskSession;
use App\Models\User;
use App\Services\Desk\DeskSessionService;
use Illuminate\Auth\Events\Logout;

/**
 * CoreX Desk - signing out ends the user's open sessions (spec .ai/specs/corex-desk.md section 3). Never lets a failure here block a logout.
 */
class EndDeskSessionsOnLogout
{
    public function handle(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        try {
            app(DeskSessionService::class)->endAllFor($event->user, DeskSession::ENDED_BY_LOGOUT);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
