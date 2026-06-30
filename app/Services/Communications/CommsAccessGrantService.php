<?php

namespace App\Services\Communications;

use App\Models\Contact;
use App\Models\User;

/**
 * AT-118 — session-scoped access-grant store for the Communications Access Gate.
 *
 * This is the SEAM the Step-3 request/approve flow plugs into. The contact gate
 * (ContactController) asks `hasActiveGrant($user, $contact)` to decide whether a
 * non-owner who was granted transient access may currently see a contact's
 * threads. The grant is session-scoped + midnight-reset (built in Step 3).
 *
 * STEP 2: stub only — there is no grant store yet, so this always returns false
 * (a non-owner with no scope sees nothing until Step 3 lands the request flow).
 * Keeping the seam here means the gate logic is final now and Step 3 only fills
 * in the body — the chokepoint does not change again.
 */
class CommsAccessGrantService
{
    /**
     * Does $user hold a live, session-scoped grant for THIS contact's threads?
     *
     * STEP 2 STUB → always false. Step 3 replaces the body with a lookup against
     * the session-scoped grant store (alive, not expired, not past midnight reset,
     * scoped to this contact). The signature is the final contract.
     */
    public function hasActiveGrant(User $user, Contact $contact): bool
    {
        return false; // Step-3 seam — no grant store yet.
    }
}
