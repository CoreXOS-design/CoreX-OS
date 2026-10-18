<?php

declare(strict_types=1);

namespace App\Support\Compliance;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The single answer to "where is this contact's FICA?" for the Quick Links chip (.ai/specs/quick-links.md §4.1).
 *
 * PURE: no queries. Callers hand in the contact's FICA submissions (newest first) and, for contacts that only ever had the
 * old e-sign FICA, the latest legacy signed date — so the contact page (one contact) and the contact list (a whole page of
 * contacts, batch-loaded) share one set of rules and cannot drift. Contact::ficaStatus() is untouched and still feeds the
 * FICA tab badge; this adds the richer "sent / waiting / under review" states it never had.
 *
 * Returns: key, tone (success|warning|danger|info|default), label, detail, action (send|resend|renew|null), submission_id.
 */
final class FicaQuickState
{
    /** Statuses meaning a request is out or being worked — anything not finished and not abandoned. */
    public const IN_FLIGHT = ['draft', 'submitted', 'under_review', 'agent_approved', 'referred_to_co', 'corrections_requested'];

    private const IN_REVIEW = ['submitted', 'under_review', 'agent_approved', 'referred_to_co'];

    /**
     * @param  Collection<int, object>  $submissions  FicaSubmission-like rows, NEWEST FIRST (status, created_at, updated_at, verified_at, token_expires_at, id)
     * @param  CarbonInterface|null     $legacySignedAt  latest signed legacy e-sign FICA document, if any
     * @param  int                      $expiringAfterMonths  the agency's setting (FicaWindows::currentMonths())
     */
    public static function resolve(Collection $submissions, ?CarbonInterface $legacySignedAt, int $expiringAfterMonths, ?CarbonInterface $now = null): array
    {
        $now ??= now();

        $inFlight = $submissions->first(fn ($s) => in_array($s->status, self::IN_FLIGHT, true));
        $approved = $submissions->first(fn ($s) => $s->status === 'approved');

        // A renewal in progress outranks an old approval; an old in-flight request never hides a newer approval.
        if ($inFlight && (! $approved || $inFlight->created_at->greaterThan($approved->verified_at ?? $approved->created_at))) {
            return self::inFlight($inFlight, $now);
        }

        if ($approved) {
            $verifiedAt = $approved->verified_at ?? $approved->created_at;
            $expiring   = $verifiedAt->diffInMonths($now) >= $expiringAfterMonths;

            return $expiring
                ? self::row('expiring', 'warning', 'FICA — expiring', 'Approved ' . $verifiedAt->format('d M Y') . ' — send a new FICA form to renew.', 'renew', $approved->id)
                : self::row('approved', 'success', 'FICA — approved ' . $verifiedAt->format('d M Y'), 'Approved ' . $verifiedAt->format('d M Y') . '.', null, $approved->id);
        }

        if ($legacySignedAt) {
            $expiring = $legacySignedAt->diffInMonths($now) >= $expiringAfterMonths;

            return $expiring
                ? self::row('legacy_expiring', 'warning', 'FICA — expiring', 'Signed ' . $legacySignedAt->format('d M Y') . ' on a previous e-sign FICA — send a new FICA form to renew.', 'renew', null)
                : self::row('legacy', 'success', 'FICA — on file', 'Signed ' . $legacySignedAt->format('d M Y') . ' (e-sign FICA).', null, null);
        }

        $rejected = $submissions->first(fn ($s) => $s->status === 'rejected');
        if ($rejected) {
            return self::row('rejected', 'danger', 'FICA — rejected', 'The last FICA was rejected — send a new FICA form.', 'send', $rejected->id);
        }

        return self::row('none', 'danger', 'FICA — not done', 'No FICA on file for this contact.', 'send', null);
    }

    private static function inFlight(object $s, CarbonInterface $now): array
    {
        if ($s->status === 'draft') {
            $sent = $s->updated_at ?? $s->created_at;   // a resend re-issues the token and touches updated_at
            if ($s->token_expires_at && $s->token_expires_at->lessThan($now)) {
                return self::row('awaiting_expired', 'warning', 'FICA — link expired', 'Sent ' . $sent->diffForHumans() . '; the link has expired — resend it.', 'resend', $s->id);
            }

            return self::row('awaiting', 'default', 'FICA — awaiting client', 'Sent ' . $sent->diffForHumans() . '; the client has not filled it in yet.', 'resend', $s->id);
        }

        if ($s->status === 'corrections_requested') {
            return self::row('corrections', 'warning', 'FICA — corrections requested', 'The client was asked to correct their FICA — resend the link.', 'resend', $s->id);
        }

        return self::row('in_review', 'info', 'FICA — under review', 'The client has submitted; the FICA is being reviewed.', null, $s->id);
    }

    private static function row(string $key, string $tone, string $label, string $detail, ?string $action, ?int $submissionId): array
    {
        return ['key' => $key, 'tone' => $tone, 'label' => $label, 'detail' => $detail, 'action' => $action, 'submission_id' => $submissionId];
    }
}
