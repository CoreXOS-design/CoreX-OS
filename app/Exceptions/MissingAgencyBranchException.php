<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * AT-379/AT-380 audit — an action that must attach a record to a branch (e.g.
 * `contacts.branch_id`, NOT NULL) was attempted for an agency that resolves to
 * NO branch at all: the acting user has no branch_id, no context branch was
 * supplied, and the agency has neither a `default_branch_id` nor any row in
 * `branches`.
 *
 * AT-378 guarantees every agency created through AgencyController::store()
 * gets a first branch in the same DB transaction, so this should be
 * unreachable for any agency created after that landed. It remains reachable
 * for a legacy agency that predates AT-378, or any future write path that
 * creates an agency without going through that controller — and the
 * alternative to raising this is a raw SQL 1364 "branch_id cannot be null"
 * 500, which is exactly the failure mode AT-379 already fixed once
 * (EntryPointController::resolveBranchId()). Same rule as
 * MissingAgencyContextException: a write with nowhere to land is a question
 * the system cannot answer for itself, so it says so instead of guessing.
 */
final class MissingAgencyBranchException extends RuntimeException
{
    public function __construct(public readonly int $agencyId, public readonly string $action = 'this record')
    {
        parent::__construct(sprintf('Agency %d has no branch to assign %s to.', $agencyId, $action));
    }

    /** Plain language, and it names the way forward — never a stack trace (BUILD_STANDARD §4). */
    public function userMessage(): string
    {
        return sprintf(
            'This agency has no branch set up yet, so %s cannot be saved. '
            . 'Ask an admin to add a branch under Company Settings first, then try again.',
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
                'reason' => 'missing_agency_branch',
            ], 422);
        }

        return back()->withInput()->with('error', $this->userMessage());
    }
}
