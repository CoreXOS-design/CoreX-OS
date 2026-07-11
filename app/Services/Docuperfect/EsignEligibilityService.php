<?php

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\Template;
use Illuminate\Support\Collection;

/**
 * P0-1 — the ONE place that answers "may these documents be e-signed?"
 *
 * The legal predicate itself is Template::isEsignBlocked() (ES-1: Alienation of
 * Land Act / ECTA s13(1) — sale agreements and OTPs are wet-ink only). That
 * predicate was correct; the defect was that only the SINGLE-template path ever
 * called it. Both pack paths (web packs and PDF packs) bypassed it entirely, so
 * an Offer To Purchase riding inside a pack could be e-signed.
 *
 * Every caller — wizard listing, flow creation, dispatch — now resolves the
 * question here, over the FULL set of templates in the flow. A pack is
 * eligible only if EVERY document in it is eligible: one wet-ink document makes
 * the whole pack wet-ink, because the pack signs as one ceremony.
 *
 * Do not re-implement this test at a call site. Add the call site here.
 */
class EsignEligibilityService
{
    /**
     * The first legally-blocked template in the set, or null if all are clear.
     *
     * Order matters for the message: we name the offending document so the
     * agent knows WHICH one to pull out of the pack, not merely that the pack
     * is refused.
     */
    public function firstBlocked(iterable $templates): ?Template
    {
        foreach ($templates as $template) {
            if ($template instanceof Template && $template->isEsignBlocked()) {
                return $template;
            }
        }

        return null;
    }

    /**
     * Plain-English refusal, naming the document, or null when e-sign is allowed.
     *
     * STANDARDS.md "Plain-English Visible Labels" + BUILD_STANDARD.md §4:
     * the agent is told what is wrong AND which document caused it. No codes,
     * no statute-only jargon without the practical consequence.
     */
    public function blockReason(iterable $templates): ?string
    {
        $blocked = $this->firstBlocked($templates);

        if (! $blocked) {
            return null;
        }

        return sprintf(
            '“%s” cannot be e-signed — sale agreements and offers to purchase must be signed '
            . 'with wet ink under the Alienation of Land Act. Send this document by wet ink or '
            . 'download instead, or remove it from the pack.',
            $blocked->name
        );
    }

    /**
     * True when every document in the set may be e-signed.
     *
     * An EMPTY set is NOT eligible — "nothing to sign" must never present as a
     * green light (BUILD_STANDARD.md §3: prevent or absorb, never break).
     */
    public function isEligible(iterable $templates): bool
    {
        $items = $templates instanceof Collection ? $templates : collect($templates);

        if ($items->isEmpty()) {
            return false;
        }

        return $this->firstBlocked($items) === null;
    }

    /**
     * Whether a template may be auto-flagged is_esign=true by the wizard.
     *
     * The wizard "repairs" templates by stamping is_esign=true when they are
     * used. That is fine for an ordinary document — but it silently LAUNDERED a
     * wet-ink-only document into an e-sign-eligible one, and since pack
     * eligibility reads is_esign, one pass through the wizard permanently
     * poisoned the gate. A legally-blocked template is never auto-flagged.
     */
    public function mayAutoFlagEsign(Template $template): bool
    {
        return ! $template->isEsignBlocked();
    }
}
