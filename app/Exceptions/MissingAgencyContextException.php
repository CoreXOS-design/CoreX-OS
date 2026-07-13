<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * AT-253 (STANDARDS Rule 17) — an action that must belong to an agency was attempted by an
 * actor that belongs to none.
 *
 * Owners/super-admins carry `agency_id = NULL`, and so do console commands, queued jobs and
 * webhooks. Historically these paths did one of two damaging things:
 *
 *   `?? 1`  → silently wrote the record into AGENCY 1. No error, wrong tenant, and nobody
 *             finds out until another agency's data is sitting in Home Finders' books.
 *   `?: 0`  → wrote agency_id 0, which has no parent row → FK 1452 → a raw 500 page.
 *
 * Both are worse than simply saying so. A write with no tenant to write into is not a
 * fallback situation — it is a question the system cannot answer, so it asks the human:
 * switch into an agency first.
 *
 * Reads are different and are NOT this exception's business: they resolve to the sentinel 0
 * and hit a `<= 0` guard that returns defaults (see CommissionSetting::forAgency).
 */
final class MissingAgencyContextException extends RuntimeException
{
    public function __construct(public readonly string $action = 'this action')
    {
        parent::__construct(sprintf('No agency context for %s.', $action));
    }

    /** Plain language, and it names the way forward — never a stack trace (BUILD_STANDARD §4). */
    public function userMessage(): string
    {
        return sprintf(
            'Your account is not attached to an agency, so %s cannot be saved against one. '
            . 'Switch into an agency first, then try again.',
            $this->action,
        );
    }

    /**
     * Rendered centrally so EVERY caller — controller, JSON endpoint, job — is covered by one
     * rule rather than each remembering to catch it.
     */
    public function render(\Illuminate\Http\Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok'     => false,
                'error'  => $this->userMessage(),
                'reason' => 'missing_agency_context',
            ], 422);
        }

        return back()->withInput()->with('error', $this->userMessage());
    }
}
