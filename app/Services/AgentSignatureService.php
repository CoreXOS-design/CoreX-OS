<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentSignature;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Support\Facades\Hash;

/**
 * The reusable saved-signature core. Both e-sign and the CMA certificate generator
 * call THIS (not their own copy) to unlock + place an agent's saved signature.
 *
 * HARD REQUIREMENT: not even a switch-user / admin impersonation can view or use a
 * saved signature. Impersonation is flagged by session('impersonator_id') (read via
 * App\Support\Impersonation::actingAdminId()). Every path that could read the image
 * or unlock it is gated by guardNotImpersonating() / the isUnlocked() impersonation
 * check below, so an impersonated session gets a 403 and never the pixels.
 *
 * UX: the agent enters their signing PIN ONCE per document (contextKey) to unlock;
 * after that they click-to-place signature + initial repeatedly with no PIN per page.
 */
final class AgentSignatureService
{
    /** Unlock window — one PIN entry covers a normal signing session. */
    private const UNLOCK_TTL_SECONDS = 8 * 3600;

    /** Refuse outright whenever the session is impersonating another user. */
    private function guardNotImpersonating(): void
    {
        if (Impersonation::actingAdminId() !== null) {
            abort(403, 'Saved signatures are unavailable while acting as another user.');
        }
    }

    public function forUser(int $userId): ?AgentSignature
    {
        return AgentSignature::where('user_id', $userId)->first();
    }

    /** Ready to sign = both images + a PIN are set. */
    public function isConfigured(User $user): bool
    {
        $sig = $this->forUser((int) $user->id);
        return $sig !== null && $sig->hasImages() && $sig->hasPin();
    }

    /**
     * Set / replace the agent's own signature + initial images and/or PIN (My Portal).
     * Only the real logged-in agent may do this — never an impersonator.
     */
    public function save(User $user, ?string $signatureDataUri, ?string $initialDataUri, ?string $pin): AgentSignature
    {
        $this->guardNotImpersonating();

        $sig = AgentSignature::firstOrNew(['user_id' => (int) $user->id]);
        $sig->agency_id = (int) $user->agency_id;

        if ($signatureDataUri !== null && $signatureDataUri !== '') {
            $sig->signature_image  = $signatureDataUri;
            $sig->images_updated_at = now();
        }
        if ($initialDataUri !== null && $initialDataUri !== '') {
            $sig->initial_image    = $initialDataUri;
            $sig->images_updated_at = now();
        }
        if ($pin !== null && $pin !== '') {
            $sig->signing_pin = Hash::make($pin);
            $sig->pin_set_at  = now();
        }

        $sig->save();
        return $sig;
    }

    /**
     * Verify the PIN and unlock this contextKey (document/session) for repeated
     * placement. Returns false on wrong PIN / not configured. Never unlocks under
     * impersonation.
     */
    public function verifyPinAndUnlock(User $user, string $pin, string $contextKey): bool
    {
        $this->guardNotImpersonating();

        $sig = $this->forUser((int) $user->id);
        if ($sig === null || ! $sig->hasPin() || ! $sig->hasImages()) {
            return false;
        }
        if (! Hash::check($pin, (string) $sig->signing_pin)) {
            return false;
        }

        session()->put($this->unlockSessionKey($user, $contextKey), now()->timestamp);
        return true;
    }

    /** Is this user's signature unlocked for this context right now? */
    public function isUnlocked(User $user, string $contextKey): bool
    {
        // An impersonated session is NEVER considered unlocked, regardless of session state.
        if (Impersonation::actingAdminId() !== null) {
            return false;
        }
        $ts = (int) session()->get($this->unlockSessionKey($user, $contextKey), 0);
        return $ts > 0 && (now()->timestamp - $ts) <= self::UNLOCK_TTL_SECONDS;
    }

    /**
     * The decrypted signature/initial PNG data-URI — ONLY when unlocked for this
     * context and NOT impersonating. Aborts 403 otherwise (never returns the pixels).
     */
    public function image(User $user, string $type, string $contextKey): ?string
    {
        $this->guardNotImpersonating();

        if (! $this->isUnlocked($user, $contextKey)) {
            abort(403, 'Enter your signing PIN to unlock your signature.');
        }

        $sig = $this->forUser((int) $user->id);
        if ($sig === null) {
            return null;
        }

        return $type === 'initial' ? $sig->initial_image : $sig->signature_image;
    }

    /** Drop the unlock for a context (e.g. on document complete). */
    public function lock(User $user, string $contextKey): void
    {
        session()->forget($this->unlockSessionKey($user, $contextKey));
    }

    private function unlockSessionKey(User $user, string $contextKey): string
    {
        return 'sig_unlock.' . (int) $user->id . '.' . sha1($contextKey);
    }
}
