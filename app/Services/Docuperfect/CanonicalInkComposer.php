<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\SignatureRequest;
use Illuminate\Support\Facades\Log;

/**
 * ESIGN-WETINK Phase 1c — bake a party's ink INTO the canonical artifact.
 *
 * The wet-ink doctrine (ESIGN-WETINK.md I3): when a party fills a field,
 * initials, or signs, that ink is composed INTO the ONE canonical HTML and
 * persisted — it becomes part of the document body, NOT a per-viewer overlay
 * filtered by `is_mine`. After party N completes, `canonical_html` literally
 * contains party N's signature image / initials / field values, so party N+1
 * loads that same artifact and sees them because they ARE the document.
 *
 * The NEW piece the doctrine calls out is IDENTITY-SCOPING: every party's ink
 * must stay distinct. The old `embedSignaturesIntoHtml` matched by party ALIAS
 * (`data-marker-party`), so its "fill every same-party surface" fallback bled
 * seller-1's signature onto seller-2's markers (gap audit finding (b)). Here we
 * match by `data-recipient-identity="{role}_{index}"` — the per-recipient stamp
 * the expansion now writes onto every marker inside a cloned block — so party
 * N's ink lands ONLY on party N's positions.
 *
 * N-PARTY: identity is resolved from the SignatureRequest at runtime
 * (`role_identity`); there is no `seller_1`/`seller_2` assumption and no ceiling
 * on same-role recipients.
 *
 * Fail-safe: any parse/DOM failure returns the input HTML unchanged — a
 * document that keeps its prior state is always safer than a 500 at signing.
 */
class CanonicalInkComposer
{
    /** Party-role → the marker-party aliases that denote the same party. */
    private const AGENT_ALIASES     = ['agent', 'property_practitioner'];
    private const OWNER_ALIASES     = ['owner_party', 'lessor', 'seller', 'landlord', 'owner'];
    private const ACQUIRING_ALIASES = ['acquiring_party', 'lessee', 'buyer', 'tenant', 'purchaser'];
    private const WITNESS_ALIASES   = ['witness'];

    /**
     * Bake this signer's captured ink into the canonical HTML, scoped to the
     * signer's `data-recipient-identity`.
     *
     * @param  string           $canonicalHtml    the current canonical artifact (vK)
     * @param  SignatureRequest  $signer          whose ink is being composed in
     * @param  array<string,string> $signatures   captured signature images (base64 data URIs)
     * @param  array<string,string> $initials     captured initial images (base64 data URIs)
     * @param  array<string,string> $ceremonyValues ceremony field values keyed "{party}_{fieldType}"
     * @param  bool $signerIsSoleOfRole  true when this signer is the ONLY recipient of their
     *                                   party_role — the ONLY case in which it is bleed-safe to
     *                                   fill markers that carry no identity stamp (single-recipient
     *                                   roles + the agent, whose markers may sit outside any cloned
     *                                   block). When false, ONLY identity-exact markers are filled,
     *                                   so an un-stamped shared surface is left blank rather than
     *                                   risk cross-party contamination.
     * @return string  the canonical HTML with this signer's ink baked in (vK+1 body)
     */
    public function bakeInk(
        string $canonicalHtml,
        SignatureRequest $signer,
        array $signatures = [],
        array $initials = [],
        array $ceremonyValues = [],
        bool $signerIsSoleOfRole = false,
    ): string {
        if (trim($canonicalHtml) === '') {
            return $canonicalHtml;
        }
        // Nothing to bake — return untouched (keeps the call cheap + idempotent).
        if (empty($signatures) && empty($initials) && empty($ceremonyValues)) {
            return $canonicalHtml;
        }

        try {
            $dom = new \DOMDocument();
            @$dom->loadHTML(
                '<?xml encoding="utf-8"?>' . $canonicalHtml,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
            $xpath = new \DOMXPath($dom);

            $signerIdentity = strtolower((string) ($signer->role_identity ?? ''));
            $signerRole     = strtolower((string) ($signer->party_role ?? ''));
            $signerAliases  = $this->aliasesFor($signerRole);
            $signerName     = (string) ($signer->signer_name ?? '');
            $signerNameKey  = $this->normalizeName($signerName);

            $ownsMarker = fn (\DOMElement $el): bool => $this->markerBelongsToSigner(
                $el, $signerIdentity, $signerRole, $signerNameKey, $signerAliases, $signerIsSoleOfRole,
            );

            // ── Signatures ── representative capture → every signature marker
            // this signer owns. Apply-to-all yields identical captures, so a
            // representative image is faithful; recipient per-page captures are
            // the same hand either way. Uniform render box (I5 — refined in 1d).
            $repSig = $this->representative($signatures);
            if ($repSig !== null) {
                foreach ($xpath->query('//*[@data-marker-type="signature"][@data-marker-party]') as $el) {
                    if ($el instanceof \DOMElement && $ownsMarker($el)) {
                        $this->paintImage($dom, $el, $repSig, 'signature', $signerName);
                    }
                }
            }

            // ── Initials ── representative initial → every initial marker owned.
            $repInit = $this->representative($initials);
            if ($repInit !== null) {
                foreach ($xpath->query('//*[@data-marker-type="initial"][@data-marker-party]') as $el) {
                    if ($el instanceof \DOMElement && $ownsMarker($el)) {
                        $this->paintImage($dom, $el, $repInit, 'initial', $signerName);
                    }
                }
            }

            // ── Ceremony values ── text fields (location/day/month/year/time/
            // am_pm) keyed "{party}_{fieldType}"; fill this signer's owned
            // markers of that field type.
            foreach ($ceremonyValues as $key => $value) {
                if (trim((string) $value) === '') {
                    continue;
                }
                $parts = explode('_', (string) $key, 2);
                if (count($parts) < 2) {
                    continue;
                }
                $fieldType = $parts[1];
                foreach ($xpath->query('//*[@data-marker-party][@data-marker-type="' . $this->xpathLiteral($fieldType) . '"]') as $el) {
                    if ($el instanceof \DOMElement && $ownsMarker($el)) {
                        $el->textContent = (string) $value;
                        $el->setAttribute('style', ($el->getAttribute('style') ?: '') . 'font-weight:500;');
                        $el->setAttribute('data-signed', 'true');
                    }
                }
            }

            $out = $dom->saveHTML();
            $out = preg_replace('/^<\?xml encoding="utf-8"\?>/', '', (string) $out);
            return trim((string) $out);
        } catch (\Throwable $e) {
            Log::error('CanonicalInkComposer::bakeInk failed — canonical returned unchanged', [
                'signer_request_id' => $signer->id ?? null,
                'signer_identity'   => $signer->role_identity ?? null,
                'error'             => $e->getMessage(),
                'line'              => $e->getLine(),
            ]);
            return $canonicalHtml;
        }
    }

    /**
     * Does this marker belong to the signer whose ink we are baking?
     *
     * Match priority (most specific → least):
     *  1. `data-name` — the merged_html binds EVERY signature/initial marker to
     *     the exact person it belongs to (`data-name="Anine Van der Westhuizen"`).
     *     This is the primary key: it is per-person, N-party-safe, and — crucially
     *     — works even when the markers live in a shared signature table rather
     *     than inside cloned role-blocks (the real doc-431/EATS shape, where
     *     markers carry NO data-recipient-identity). This is the fix for the
     *     "agent review / next party shows NO recipient ink" defect: seller_1's
     *     ink was matching nothing because the seller markers are name-bound, not
     *     identity-stamped, and the sole-of-role party fallback is (correctly)
     *     disabled for a 2-seller document.
     *  2. `data-recipient-identity` — markers stamped inside cloned role-blocks.
     *  3. Party-alias fallback — ONLY when the signer is the sole recipient of
     *     their role (agent, single seller/buyer); safe because there is no
     *     same-role sibling to bleed onto. For a non-sole signer an un-keyed
     *     marker is left blank (safer than contaminated).
     *
     * A marker that carries a data-name for a DIFFERENT person is never filled by
     * this signer (step 1 returns false), so there is no cross-party bleed.
     */
    private function markerBelongsToSigner(
        \DOMElement $el,
        string $signerIdentity,
        string $signerRole,
        string $signerName,
        array $signerAliases,
        bool $signerIsSoleOfRole,
    ): bool {
        // 1) Name binding (the reliable per-person key).
        $markerName = $this->normalizeName($el->getAttribute('data-name'));
        if ($markerName !== '' && $signerName !== '') {
            return $markerName === $signerName;
        }
        // 2) Identity stamp (role-block-cloned markers).
        $markerIdentity = strtolower($el->getAttribute('data-recipient-identity'));
        if ($markerIdentity !== '') {
            return $signerIdentity !== '' && $markerIdentity === $signerIdentity;
        }
        // 3) Sole-of-role party fallback.
        if (! $signerIsSoleOfRole) {
            return false;
        }
        $markerParty = strtolower($el->getAttribute('data-marker-party'));
        return $markerParty === $signerRole || in_array($markerParty, $signerAliases, true);
    }

    /** Case-insensitive, whitespace-collapsed name key for data-name matching. */
    private function normalizeName(string $name): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));
    }

    /**
     * ESIGN-WETINK I5 / BUG5 — the ONE uniform ink render box. Every baked
     * signature and initial, on EVERY surface that serves the canonical
     * (ceremony, agent sign, agent review, PDF), renders at these fixed
     * dimensions with aspect preserved. A `min-height` floor stops a
     * small/low-DPI capture from rendering as a faint sliver, and object-fit
     * contain stops any capture from stretching — so ink is consistent in size
     * and weight regardless of the marker geometry it lands on.
     */
    private const INK_SIGNATURE_STYLE = 'display:block;height:56px;min-height:56px;max-height:56px;width:auto;max-width:100%;margin:2px auto;object-fit:contain;';
    private const INK_INITIAL_STYLE   = 'display:block;height:38px;min-height:38px;max-height:38px;width:auto;max-width:100%;margin:1px auto;object-fit:contain;';

    /** Paint an ink image into a marker element with the uniform render box. */
    private function paintImage(\DOMDocument $dom, \DOMElement $el, string $data, string $kind, string $signerName): void
    {
        while ($el->firstChild) {
            $el->removeChild($el->firstChild);
        }
        $isSig  = $kind === 'signature';
        $img = $dom->createElement('img');
        $img->setAttribute('src', $data);
        $img->setAttribute('class', 'web-sig-signed-img corex-ink corex-ink--' . $kind);
        $img->setAttribute('alt', $isSig ? 'Signature' : 'Initial');
        $img->setAttribute('style', $isSig ? self::INK_SIGNATURE_STYLE : self::INK_INITIAL_STYLE);
        $el->appendChild($img);
        $el->setAttribute('data-signed', 'true');
        if ($signerName !== '') {
            $el->setAttribute('data-inked-by', $signerName);
        }
        if ($isSig) {
            $label = $dom->createElement('div');
            $label->setAttribute('style', 'font-size:8px;color:#059669;text-align:center;font-weight:600;');
            $label->textContent = 'Signed by ' . ($signerName !== '' ? $signerName : 'party');
            $el->appendChild($label);
        } else {
            $existing = $el->getAttribute('class');
            $el->setAttribute('class', trim($existing . ' initial-signed'));
        }
    }

    /** First non-empty value in a capture map, or null. */
    private function representative(array $items): ?string
    {
        foreach ($items as $v) {
            if (is_string($v) && trim($v) !== '') {
                return $v;
            }
        }
        return null;
    }

    /** Marker-party aliases that denote the same party as $role. */
    private function aliasesFor(string $role): array
    {
        return match (true) {
            in_array($role, self::AGENT_ALIASES, true)     => self::AGENT_ALIASES,
            in_array($role, self::OWNER_ALIASES, true)     => self::OWNER_ALIASES,
            in_array($role, self::ACQUIRING_ALIASES, true) => self::ACQUIRING_ALIASES,
            in_array($role, self::WITNESS_ALIASES, true)   => self::WITNESS_ALIASES,
            default                                        => [$role],
        };
    }

    /**
     * Escape a marker field-type for safe embedding in an XPath attribute
     * predicate. Field types are internal tokens (day/month/location/…) so a
     * simple quote-guard suffices; falls back to concat() if a quote appears.
     */
    private function xpathLiteral(string $value): string
    {
        // Field types never legitimately contain quotes; strip any to keep the
        // predicate well-formed (defensive — no injection surface either way).
        return str_replace(['"', "'"], '', $value);
    }
}
