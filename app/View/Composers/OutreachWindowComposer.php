<?php

namespace App\View\Composers;

use App\Models\Agency;
use App\Services\Outreach\OutreachWindowService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * AT-117 §4a — shares the resolved send-window state to every outreach dispatch
 * view as a single `$outreachWindow` array, so each surface gates its WhatsApp
 * action from ONE server-computed source instead of reimplementing the check.
 *
 * Shape: ['allowed' => bool, 'message' => string, 'describe' => string,
 *         'next_opens_at' => string|null].
 *
 * Absorb: with no resolvable agency context (shouldn't happen on these
 * authenticated views) the gate defaults OPEN — the server endpoints remain the
 * authoritative refusal, so a missing UI flag can never permit an actual
 * out-of-window dispatch.
 */
class OutreachWindowComposer
{
    public function __construct(private OutreachWindowService $window)
    {
    }

    public function compose(View $view): void
    {
        $view->with('outreachWindow', $this->resolve());
    }

    private function resolve(): array
    {
        $open = ['allowed' => true, 'message' => '', 'describe' => '', 'next_opens_at' => null];

        $user = Auth::user();
        if (!$user) {
            return $open;
        }

        $agencyId = method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : ($user->agency_id ?? null);
        $agency = $agencyId ? Agency::find($agencyId) : null;
        if (!$agency) {
            return $open;
        }

        $allowed = $this->window->isSendAllowed($agency);
        $next = $this->window->nextOpensAt($agency);

        return [
            'allowed'       => $allowed,
            'describe'      => $this->window->describeWindow($agency),
            'message'       => $allowed ? '' : $this->window->blockedMessage($agency),
            'next_opens_at' => $next?->toIso8601String(),
        ];
    }
}
