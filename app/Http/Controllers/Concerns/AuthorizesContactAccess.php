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
        abort_unless($this->canMutateContact($contact), 403);
    }

    /**
     * The non-aborting boolean the VIEW layer needs: may the current user MUTATE this contact?
     *
     * Used to render the contact detail page read-only for an assistant who can see but not edit
     * it, so no edit affordances are shown that would only 403 on save.
     */
    protected function canMutateContact(Contact $contact): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        // Only assistants get a MUTATION set narrower than their VIEW set. For everyone else the
        // global ContactScope already filtered route-model binding to what they may edit.
        if (! $user->is_assistant) {
            return true;
        }

        // dataIdentityIds() = [assignedAgentId, assistantSelfId]. An assistant may mutate the
        // agent's own contacts (created_by_user_id ∈ that set), never another agent's — even one
        // in the branch the assistant can see.
        if (in_array((int) $contact->created_by_user_id, $user->dataIdentityIds(), true)) {
            return true;
        }

        // AT-267 (Johan 2026-07-21) — an UNOWNED contact (no linked agent at all) is fair game: an
        // assistant may edit a contact nobody is responsible for. "Linked agent" is the assigned
        // primary/secondary agent; the creator is not an agent link (an assistant-created contact
        // with no agent stays editable, which is the point).
        if ($contact->agent_id === null && $contact->second_agent_id === null) {
            return true;
        }

        return false;
    }
}
