<?php

namespace App\Services\Communications;

use App\Models\Agency;

/**
 * Deterministic ingestion filter (AT-43, POPIA data-minimisation).
 *
 * Decides whether an inbound/outbound counterpart that did NOT match a CoreX
 * contact is "never-business" machine/service/bank mail that must not even be
 * parked in the pending buffer. No AI — pure pattern + domain rules.
 *
 * CONTRACT: this is only consulted AFTER the known-contact gate has failed
 * (EmailArchiveIngestor calls it in the no-contact branch), so "contact always
 * wins" is structural. dropReasonForUnknown() therefore does NOT re-check
 * contacts; callers that need the contact check (e.g. the purge command, which
 * scans historic rows) use shouldKeep() which layers the contact lookup on top.
 *
 * Rules (all agency-configurable; defaults in config/communications.php, never
 * hardcoded in this class):
 *   - no-reply rule: local-part markers (no-reply@, system@, bounce@, …)
 *   - blocklist domains: bank/accounting/portal-notification domains
 */
class CommunicationIngestFilter
{
    public function __construct(private ContactIdentifierResolver $resolver)
    {
    }

    public const REASON_NOREPLY  = 'no_reply_pattern';
    public const REASON_BLOCKED  = 'service_domain';

    /**
     * Reason this unknown-contact sender should be dropped, or null to keep.
     * Assumes the caller already confirmed there is no matching contact.
     */
    public function dropReasonForUnknown(?string $identifier, ?Agency $agency = null): ?string
    {
        $email = strtolower(trim((string) $identifier));
        if ($email === '' || ! str_contains($email, '@')) {
            return null; // non-email (e.g. WhatsApp number) — these rules don't apply
        }

        [$local, $domain] = $this->split($email);

        if ($this->dropNoReply($agency) && $this->matchesNoReply($local)) {
            return self::REASON_NOREPLY;
        }

        if ($this->matchesBlocklist($domain, $agency)) {
            return self::REASON_BLOCKED;
        }

        return null;
    }

    /**
     * For historic rows (purge command): KEEP if the sender is a contact OR is
     * not on the droplist; drop only never-business senders that aren't a
     * contact. Returns [keep(bool), reason(string|null)].
     *
     * @return array{0: bool, 1: ?string}
     */
    public function evaluateExisting(?string $identifier, int $agencyId, ?Agency $agency = null): array
    {
        // Contact always wins.
        $email = trim((string) $identifier);
        if ($email !== '' && $this->resolver->resolve($email, $agencyId)) {
            return [true, 'contact_match'];
        }

        $reason = $this->dropReasonForUnknown($identifier, $agency);

        return $reason === null ? [true, null] : [false, $reason];
    }

    private function split(string $email): array
    {
        $at = strrpos($email, '@');

        return [substr($email, 0, $at), substr($email, $at + 1)];
    }

    private function dropNoReply(?Agency $agency): bool
    {
        $override = $agency?->communication_ingest_drop_noreply;

        return $override === null
            ? (bool) config('communications.ingest_drop_noreply', true)
            : (bool) $override;
    }

    private function matchesNoReply(string $local): bool
    {
        foreach ((array) config('communications.ingest_noreply_local_parts', []) as $marker) {
            $marker = strtolower(trim((string) $marker));
            if ($marker !== '' && str_contains($local, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function matchesBlocklist(string $domain, ?Agency $agency): bool
    {
        $override = $agency?->communication_ingest_blocklist_domains;
        $domains  = is_array($override)
            ? $override
            : (array) config('communications.ingest_blocklist_domains', []);

        foreach ($domains as $blocked) {
            $blocked = strtolower(trim((string) $blocked));
            if ($blocked === '') {
                continue;
            }
            // Exact domain or any subdomain of it.
            if ($domain === $blocked || str_ends_with($domain, '.' . $blocked)) {
                return true;
            }
        }

        return false;
    }
}
