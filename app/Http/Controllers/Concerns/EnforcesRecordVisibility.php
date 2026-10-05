<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;

/**
 * OWN / BRANCH / AGENCY visibility for a single bound record (BUILD_STANDARD
 * §1a: "direct-URL access by ID is blocked, not just unlinked"). The list
 * screens already narrow with the model's scopeVisibleTo(); route-model
 * binding only applies AgencyScope, so every show/action/download endpoint
 * calls assertVisible() to re-check the SAME scope against the bound record.
 * Archived rows are included so restore actions are gated identically.
 * A record the user may not see answers 404 — its existence is not revealed.
 */
trait EnforcesRecordVisibility
{
    protected function assertVisible(Request $request, Model $record): void
    {
        $user = $request->user();

        $visible = $record->newQuery()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->visibleTo($user)
            ->whereKey($record->getKey())
            ->exists();

        abort_unless($visible, 404);
    }
}
