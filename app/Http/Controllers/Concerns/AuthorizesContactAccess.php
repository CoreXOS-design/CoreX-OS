<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Contact;
use App\Models\User;

/**
 * Per-contact MUTATION authorization — the single-record sibling of the global ContactScope.
 *
 * Contacts are visibility-scoped by the GLOBAL ContactScope, so route-model binding already
 * blocks a normal user from binding a contact outside their VIEW scope — which is why the web
 * write methods historically had no explicit per-record guard. That is safe only while VIEW and
 * EDIT breadth are the same.
 *
 * For an ASSISTANT they are NOT the same (spec §7.2, Johan 2026-07-20): an assistant SEES the
 * assigned agent's full breadth (branch/all, if the agent is a branch manager or admin) but may
 * only EDIT the agent's OWN contacts. So when a wide-scope agent's assistant binds a colleague's
 * contact they can legitimately see, this guard stops the write. It keys 'own' on
 * `created_by_user_id` — exactly the column ContactScope::applyAssistant() uses for the assistant
 * 'own' tier — so LIST and MUTATE stay consistent.
 *
 * Non-assistants are unaffected: their editable set already equals their bound (view) set, so we
 * return early rather than re-derive a branch/all rule here and risk 403'ing a legitimate manager.
 */
trait AuthorizesContactAccess
{
    protected function authorizeContact(Contact $contact): void
    {
        /** @var User|null $user */
        $user = auth()->user();
        abort_unless($user !== null, 403);

        // Only assistants get a MUTATION set narrower than their VIEW set. For everyone else the
        // global ContactScope already filtered route-model binding to what they may edit.
        if (! $user->is_assistant) {
            return;
        }

        // dataIdentityIds() = [assignedAgentId, assistantSelfId]. An assistant may mutate only the
        // agent's own contacts (created_by_user_id ∈ that set), never another agent's — even one
        // in the branch the assistant can see.
        if (in_array((int) $contact->created_by_user_id, $user->dataIdentityIds(), true)) {
            return;
        }

        abort(403);
    }
}
