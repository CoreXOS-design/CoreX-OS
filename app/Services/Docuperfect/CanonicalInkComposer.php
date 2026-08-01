<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
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

            // ── Signatures ── PER-ANCHOR binding. Each captured signature carries the
            // document-order index of the marker it was drawn in ("{party}-sig-{n}"),
            // so the k-th signature marker this signer owns takes capture index k —
            // NOT a single "representative" painted over every box (which collapsed
            // seller#1+#2's four distinct captures to sig-0 and dropped sig-1/2/3).
            // Mirrors embedSignaturesIntoHtml's per-anchor keying (the agent path).
            // Adopt-once/apply-to-all stays correct: one capture (or N identical) →
            // every owned marker falls back to the representative → same mark везде.
            $this->paintOwnedMarkersByIndex($xpath, $dom, 'signature', $signatures, $ownsMarker, $signerName);

            // ── Initials ── same per-anchor binding (k-th owned initial marker ←
            // "{party}-init-{k}"). Inert for docs whose page-break initials are
            // injected at pagination (restoreStoredInitials handles those) — but
            // correct for any doc that carries baked-in initial markers.
            $this->paintOwnedMarkersByIndex($xpath, $dom, 'initial', $initials, $ownsMarker, $signerName);

            // ── Ceremony values ── text fields (location/day/month/year/time/
            // am_pm) keyed "{party}_{fieldType}"; fill this signer's owned
            // markers of that field type.
            //
            // Ceremony spans carry NO data-name (only the signature cell does), so
            // markerBelongsToSigner's name-rescue cannot bind them. An un-stamped
            // ceremony span (a single-recipient / un-cloned attestation block) then
            // depends solely on the sole-of-role party fallback — and a party whose
            // marker token is not in the alias set, or who is not counted sole, is
            // left blank. That is the "seller's captured place/date/time dropped
            // from the render" defect: her values ARE in $ceremonyValues but never
            // bind. Bind such UN-STAMPED spans by PARTY as well — the exact match
            // embedCeremonyValuesIntoHtml() already uses (data-marker-party equals
            // the key's party, or starts with it). The party fallback is gated on
            // the ABSENCE of data-recipient-identity, so a cloned co-party span
            // (seller_1 vs seller_2, which IS identity-stamped) stays strictly
            // identity-scoped and never bleeds. Signatures/initials above are
            // untouched — this only widens the CEREMONY-text binding.
            foreach ($ceremonyValues as $key => $value) {
                if (trim((string) $value) === '') {
                    continue;
                }
                $split = $this->splitCeremonyKey((string) $key);
                if ($split === null) {
                    continue;
                }
                [$keyParty, $fieldType] = $split;
                // Materialise the match set before mutating — stampCeremonyFilled may
                // REPLACE an <input> node, and iterating a live query while
                // restructuring the tree is unsafe.
                $matches = iterator_to_array($xpath->query('//*[@data-marker-party][@data-marker-type="' . $this->xpathLiteral($fieldType) . '"]'));
                foreach ($matches as $el) {
                    if (! $el instanceof \DOMElement) {
                        continue;
                    }
                    $ownedByParty = $this->ceremonySpanMatches($el, $keyParty);
                    if ($ownsMarker($el) || $ownedByParty) {
                        $this->stampCeremonyFilled($el, (string) $value);
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
     * Render-time re-application of EVERY captured ceremony value onto its own
     * party's spans — the repair path for docs signed BEFORE the party-binding
     * fix above, applied where the PDF is actually assembled
     * (SignaturePdfService::resolveRenderHtml).
     *
     * `ceremony_values` (keyed "{party}_{fieldType}") is the source of truth: a
     * signer provided those place/date/time values. A frozen signed_paginated_html
     * or a canonical baked by the OLD strict binding can still show a party's
     * spans blank; this paints the captured values back onto the exact spans they
     * came from — the key's party IS that span's data-marker-party, by
     * construction of the capture (rawParty + '_' + fieldType) — so the rendered
     * PDF is faithful and an already-signed document repairs on a no-re-sign
     * re-render. Party-scoped (agent values never touch seller spans) and
     * idempotent (re-writing the same value is a no-op). Fail-safe: any parse/DOM
     * error returns the HTML unchanged — a document that keeps its state is always
     * safer than a 500 at render.
     *
     * @param  array<string,string> $ceremonyValues  captured "{party}_{fieldType}" => value
     */
    public function applyCeremonyValues(string $html, array $ceremonyValues): string
    {
        if (trim($html) === '' || $ceremonyValues === []) {
            return $html;
        }

        try {
            $dom = new \DOMDocument();
            @$dom->loadHTML(
                '<?xml encoding="utf-8"?>' . $html,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
            $xpath = new \DOMXPath($dom);

            foreach ($ceremonyValues as $key => $value) {
                if (trim((string) $value) === '') {
                    continue;
                }
                $split = $this->splitCeremonyKey((string) $key);
                if ($split === null) {
                    continue;
                }
                [$keyParty, $fieldType] = $split;
                // Materialise before mutating — stampCeremonyFilled may REPLACE an
                // <input> node with a <span>.
                $matches = iterator_to_array($xpath->query('//*[@data-marker-party][@data-marker-type="' . $this->xpathLiteral($fieldType) . '"]'));
                foreach ($matches as $el) {
                    if (! $el instanceof \DOMElement) {
                        continue;
                    }
                    if (! $this->ceremonySpanMatches($el, $keyParty)) {
                        continue;
                    }
                    $this->stampCeremonyFilled($el, (string) $value);
                }
            }

            $out = $dom->saveHTML();
            $out = preg_replace('/^<\?xml encoding="utf-8"\?>/', '', (string) $out);
            return trim((string) $out);
        } catch (\Throwable $e) {
            Log::error('CanonicalInkComposer::applyCeremonyValues failed — HTML returned unchanged', [
                'error' => $e->getMessage(),
                'line'  => $e->getLine(),
            ]);
            return $html;
        }
    }

    /**
     * Ceremony party-match — the SAME binding embedCeremonyValuesIntoHtml() uses
     * (SignatureController::embedCeremonyValuesIntoHtml): the span's
     * data-marker-party equals the ceremony key's party, or starts with it. Reused
     * by bakeInk's un-stamped-span fallback and by applyCeremonyValues so both
     * paths bind ceremony text identically.
     */
    /**
     * Known ceremony field types, longest-first so the underscore-bearing "am_pm"
     * is matched before any shorter type. Mirrors the client's ceremonyTypes list
     * (external/sign.blade.php).
     */
    private const CEREMONY_FIELD_TYPES = ['am_pm', 'location', 'month', 'year', 'time', 'day'];

    /**
     * Split a ceremony key "{party}_{fieldType}" into [party, fieldType].
     *
     * The party segment ITSELF may contain underscores — a 2nd+ same-role recipient
     * is keyed "seller_2" — and the field type "am_pm" contains one too. A naive
     * explode('_', $key, 2) therefore mis-parses "seller_2_location" as party
     * "seller" / type "2_location", so rec 2's captured Location/date never binds to
     * its span and drops from the agent-review and final document (AT — MDF rec-2
     * Location dropped, Johan 2026-07-30). Match the known field-type SUFFIX instead;
     * the party is the remaining prefix.
     *
     * @return array{0:string,1:string}|null  [party(lower), fieldType] or null
     */
    private function splitCeremonyKey(string $key): ?array
    {
        foreach (self::CEREMONY_FIELD_TYPES as $type) {
            $suffix = '_' . $type;
            if (str_ends_with($key, $suffix)) {
                $party = substr($key, 0, -strlen($suffix));
                if ($party !== '') {
                    return [strtolower($party), $type];
                }
            }
        }
        return null;
    }

    private function ceremonyPartyMatches(string $elParty, string $keyParty): bool
    {
        if ($elParty === '' || $keyParty === '') {
            return false;
        }

        // EXACT match only. Ceremony keys are "{data-marker-party}_{fieldType}" and the
        // per-recipient clone re-keys rec 2's marker to its own party ("seller_2"), so
        // rec 1's key "seller_location" and rec 2's key "seller_2_location" each bind to
        // their OWN span. The former `str_starts_with($elParty, $keyParty)` prefix match
        // made key "seller" ALSO match the "seller_2" span — mirroring rec 1's Location
        // onto rec 2 and blanking/overwriting rec 2's own value on the agent-review and
        // final document (AT — MDF rec-2 Location dropped, Johan 2026-07-30).
        return $elParty === $keyParty;
    }

    /**
     * Identity-aware ceremony match (Johan 2026-08). A per-recipient LOOPED block (the
     * EATS shared signature-block expanded per seller) emits BOTH sellers' Location/date
     * spans with the SAME `data-marker-party="seller"`, differing ONLY by
     * `data-recipient-identity` ("seller_1" vs "seller_2"). Matching by marker-party alone
     * bound both from `seller_location` — so the 2nd seller's own Location/date never
     * landed (blank on every doc). Bind by IDENTITY when the span carries one; fall back
     * to the exact marker-party match only for un-stamped (single-recipient) spans.
     */
    private function ceremonySpanMatches(\DOMElement $el, string $keyParty): bool
    {
        $rid = strtolower(trim($el->getAttribute('data-recipient-identity')));
        if ($rid !== '') {
            return $this->normaliseIdentity($rid) === $keyParty;
        }
        return $this->ceremonyPartyMatches(strtolower($el->getAttribute('data-marker-party')), $keyParty);
    }

    /**
     * Map a span's `data-recipient-identity` to the canonical ceremony-key identity.
     * The loop stamps the base recipient as "{role}_1" but the ceremony key uses the
     * bare role for role_index 1 (SignatureRequest::canonicalPartyKey: role_index 1 =
     * role, N>1 = role_N). Strip only the "_1" suffix so "seller_1" -> "seller",
     * "seller_2" -> "seller_2".
     */
    private function normaliseIdentity(string $rid): string
    {
        return preg_replace('/_1$/', '', $rid) ?? $rid;
    }

    /**
     * Fold a role-identity for OWNERSHIP comparison (signature / initial markers).
     * Distinct from normaliseIdentity (which maps ceremony spans to the bare-role
     * ceremony key). This answers "are these two identities the same signer?":
     *
     *  - CHECKPOINT-family roles ([[SignatureTemplate::CHECKPOINT_ROLE_ALIASES]]) are
     *    ONE human across routing checkpoints, so 'supervisor', 'supervisor_1' and
     *    'supervisor_final_1' all fold to the base 'supervisor' (index dropped —
     *    singleton).
     *  - Every OTHER role is index-preserving: 'seller' / 'seller_1' → 'seller_1',
     *    'seller_2' → 'seller_2'. Two same-role recipients stay strictly separate,
     *    so an authoriser fold never bleeds co-signers onto each other.
     *
     * Mirrored verbatim by _foldIdentity() in external/sign.blade.php.
     */
    private function foldIdentity(string $rid): string
    {
        $rid = strtolower(trim($rid));
        if ($rid === '') {
            return '';
        }
        if (preg_match('/^(.*)_(\d+)$/', $rid, $m)) {
            $role = $m[1];
            $idx  = $m[2];
        } else {
            $role = $rid;
            $idx  = '1';
        }
        $base = SignatureTemplate::CHECKPOINT_ROLE_ALIASES[$role] ?? $role;
        if (in_array($base, array_values(SignatureTemplate::CHECKPOINT_ROLE_ALIASES), true)) {
            return $base; // singleton checkpoint family — one identity across checkpoints
        }
        return $base . '_' . $idx;
    }

    /**
     * Write a ceremony text value into its span. Idempotent: the emphasis style is
     * added only once, so repeated re-renders (applyCeremonyValues runs on every
     * PDF assembly) never accrete duplicate `font-weight:500;` declarations.
     */
    private function stampCeremonyFilled(\DOMElement $el, string $value): void
    {
        // <input> ceremony fields (the recipient-editable place/date/time the
        // signing UI created for a party) are VOID elements: DOMDocument::saveHTML
        // DROPS any textContent written into them, so the value never renders — and
        // the browser never serialised the typed value onto the `value` attribute
        // either (a JS .value property is not reflected into the DOM attribute), so
        // the frozen artifact carries an EMPTY input. A signed document must read as
        // plain text anyway — exactly like the agent's <span> block — so replace the
        // <input> with a read-only <span> carrying the value, dropping the editable
        // field styling (yellow fill / heavy underline) so the executed document
        // shows text, not an empty-looking form control. Non-input elements (the
        // agent's spans) take textContent as before.
        if (strtolower($el->tagName) === 'input') {
            $doc = $el->ownerDocument;
            if ($doc instanceof \DOMDocument && $el->parentNode !== null) {
                $span = $doc->createElement('span');
                // Carry the marker identity (so an idempotent re-render still matches
                // this field) and the layout class (underline/width parity with the
                // agent's spans); the editable inline style is intentionally dropped.
                foreach (['data-marker-party', 'data-marker-type', 'data-recipient-identity', 'class'] as $attr) {
                    if ($el->hasAttribute($attr)) {
                        $span->setAttribute($attr, $el->getAttribute($attr));
                    }
                }
                $span->setAttribute('style', 'font-weight:500;');
                $span->setAttribute('data-signed', 'true');
                $span->textContent = $value;
                $el->parentNode->replaceChild($span, $el);
                return;
            }
        }

        $el->textContent = $value;
        $style = $el->getAttribute('style') ?: '';
        if (! str_contains($style, 'font-weight:500;')) {
            $el->setAttribute('style', $style . 'font-weight:500;');
        }
        $el->setAttribute('data-signed', 'true');
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
        // 2) Identity stamp (role-block-cloned markers). Fold through the checkpoint
        //    family so an authorising practitioner's 'supervisor'-identity markers are
        //    owned by BOTH their pre-external 'supervisor' and post-external
        //    'supervisor_final' signing requests (one human, two routing checkpoints).
        //    Non-checkpoint identities keep their per-recipient index, so seller_1 and
        //    seller_2 stay strictly isolated (foldIdentity is index-preserving there).
        $markerIdentity = strtolower($el->getAttribute('data-recipient-identity'));
        if ($markerIdentity !== '') {
            return $signerIdentity !== ''
                && $this->foldIdentity($markerIdentity) === $this->foldIdentity($signerIdentity);
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

    /**
     * Paint every marker of $type this signer owns with ITS OWN captured image,
     * bound per-anchor by the document-order index.
     *
     * Captures are keyed "{party}-{type}-{n}" where n is the position of the marker
     * the ink was drawn in (the frontend numbers them in document order). We walk
     * this signer's owned markers in document order and give the k-th marker the
     * capture whose index is k. A marker with no distinct capture at its position
     * falls back to the representative (first) image — so the common case, where a
     * signer adopts ONE signature/initial that applies to all their anchors (one
     * capture, or N identical copies), still fills every box with the same mark.
     * This kills the "representative over all" collapse (bleed + drop) while keeping
     * apply-to-all correct.
     */
    private function paintOwnedMarkersByIndex(
        \DOMXPath $xpath,
        \DOMDocument $dom,
        string $type,
        array $captures,
        callable $ownsMarker,
        string $signerName,
    ): void {
        // index (trailing -N) → image, for this signer's captures of this type.
        $byIndex = [];
        foreach ($captures as $key => $img) {
            if (is_string($img) && trim($img) !== '' && preg_match('/-(\d+)$/', (string) $key, $m)) {
                $byIndex[(int) $m[1]] = $img;
            }
        }
        $fallback = $this->representative($captures);
        if ($byIndex === [] && $fallback === null) {
            return;
        }

        $k = 0;
        foreach ($xpath->query('//*[@data-marker-type="' . $type . '"][@data-marker-party]') as $el) {
            if (! $el instanceof \DOMElement || ! $ownsMarker($el)) {
                continue;
            }
            $img = $byIndex[$k] ?? $fallback;   // this anchor's own capture, else representative
            if ($img !== null && trim($img) !== '') {
                $this->paintImage($dom, $el, $img, $type, $signerName);
            }
            $k++;
        }
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
