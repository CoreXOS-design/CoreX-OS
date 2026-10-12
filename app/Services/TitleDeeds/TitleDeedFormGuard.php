<?php

namespace App\Services\TitleDeeds;

use App\Exceptions\TitleDeedFormException;
use App\Models\TitleDeeds\TitleDeedFormClaim;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * .ai/specs/title-deed-register.md §14.1 - the one-time form token that makes a state-changing form single-use.
 *
 * THE BUG CLASS this closes: a form whose submit has no memory. Back button + submit, double click, refresh-and-resend, a
 * slow connection that makes a person press twice - every one of them re-sends the SAME request, and a handler with no
 * memory acts on it again (a second record, a second email, a second PIN).
 *
 * How: every rendered form carries a token (`_form_token`) - a signed string (nonce + issue time + purpose + user + deed,
 * HMAC with the app key), nothing is stored when it is issued. Submitting CLAIMS it: one row in `title_deed_form_claims`,
 * unique on the token's hash. The claim is written in the SAME transaction as the work where there is a transaction to share
 * (creating a record), so a failed attempt rolls the claim back and the same form can be sent again; where the work cannot be
 * one transaction (it sends email) the claim is committed first and a refusal re-renders the screen with a fresh token. A
 * second submit of a claimed token hits the unique key and is answered "already done", never acted on.
 *
 * Signed, not guessable: a token for one purpose / user / deed is refused for any other. Older than the lifetime = expired.
 */
class TitleDeedFormGuard
{
    public const LIFETIME_HOURS = 12;

    public function issue(User $user, string $purpose, ?int $deedId = null): string
    {
        $nonce = bin2hex(random_bytes(12));
        $issued = time();

        return implode('.', ['v1', $issued, $nonce, $this->sign($issued, $nonce, $purpose, $user, $deedId)]);
    }

    /**
     * Claim a token for an action. Call inside the work's transaction when there is one.
     *
     * @throws TitleDeedFormException kind 'invalid' | 'expired' | 'already' (with the existing claim)
     */
    public function claim(?string $token, User $user, string $purpose, ?int $deedId = null): TitleDeedFormClaim
    {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 4 || $parts[0] !== 'v1' || ! ctype_digit($parts[1])) {
            throw new TitleDeedFormException(TitleDeedFormException::INVALID);
        }
        [, $issued, $nonce, $signature] = $parts;

        if (! hash_equals($this->sign((int) $issued, $nonce, $purpose, $user, $deedId), $signature)) {
            throw new TitleDeedFormException(TitleDeedFormException::INVALID);
        }
        if ((int) $issued < time() - self::LIFETIME_HOURS * 3600 || (int) $issued > time() + 300) {
            throw new TitleDeedFormException(TitleDeedFormException::EXPIRED);
        }

        $hash = hash('sha256', (string) $token);
        try {
            return TitleDeedFormClaim::create([
                'agency_id' => (int) $user->effectiveAgencyId(),
                'token_hash' => $hash,
                'purpose' => $purpose,
                'user_id' => $user->id,
                'title_deed_id' => $deedId,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // A current read (lock), not a snapshot read: the first request may have committed a moment ago.
            $existing = TitleDeedFormClaim::withoutGlobalScopes()->where('token_hash', $hash)->lockForUpdate()->first();

            throw new TitleDeedFormException(TitleDeedFormException::ALREADY, $existing);
        }
    }

    private function sign(int $issued, string $nonce, string $purpose, User $user, ?int $deedId): string
    {
        $payload = implode('|', ['v1', $issued, $nonce, $purpose, $user->id, (int) $user->effectiveAgencyId(), (int) $deedId]);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }
}
