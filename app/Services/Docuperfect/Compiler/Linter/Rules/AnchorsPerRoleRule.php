<?php

namespace App\Services\Docuperfect\Compiler\Linter\Rules;

use App\Services\Docuperfect\Compiler\Contracts\DataDictionaryResolver;
use App\Services\Docuperfect\Compiler\Linter\LintFinding;
use App\Services\Docuperfect\Compiler\Linter\LinterContext;
use App\Services\Docuperfect\Compiler\Linter\LintRule;
use App\Services\Docuperfect\Compiler\Linter\Support\CdsInspector;
use App\Support\Docuperfect\Cds\Cds;

/**
 * L3 — Anchors per declared role (§4).
 *
 * "Every declared signing party has ≥1 signature anchor; every anchor references a declared
 * party." A CDS party is a signer by construction (WS0 declares no non-signing party), and
 * a signing surface is any anchor for which {@see \App\Support\Docuperfect\Cds\Enums\AnchorKind::isSigningSurface()}
 * is true (signature or initial) — the canonical model's own encoding of "a place to sign".
 *
 * Obsoletes `RoleBlockDetectionService` clustering + `RoleBlockExpansionService` LCA
 * machinery + `SignatureSurfaceNormalizer` (§9): topology and signature surfaces are
 * DECLARED, so this is a compile-time check, not runtime HTML re-detection.
 */
final class AnchorsPerRoleRule implements LintRule
{
    public function code(): string
    {
        return 'L3';
    }

    public function evaluate(Cds $cds, DataDictionaryResolver $dictionary, LinterContext $context): array
    {
        $findings = [];
        $inspector = new CdsInspector($cds);

        // Party keys that own ≥1 signing-surface anchor + anchor→party reference integrity.
        $signedPartyKeys = [];
        foreach ($inspector->anchors() as $entry) {
            $blockId = $entry['blockId'];
            $anchor = $entry['anchor'];

            if ($anchor->partyKey === '') {
                $findings[] = LintFinding::error(
                    rule: 'L3',
                    target: $blockId,
                    code: 'anchor_missing_party',
                    message: sprintf('Anchor "%s" in block "%s" has no party_key. Every anchor must name the declared party that signs it.', $anchor->anchorId !== '' ? $anchor->anchorId : '(no anchor_id)', $blockId),
                    context: ['anchor_id' => $anchor->anchorId],
                );

                continue;
            }

            $party = $inspector->partyByKeyOrRoleBase($anchor->partyKey);
            if ($party === null) {
                $findings[] = LintFinding::error(
                    rule: 'L3',
                    target: $blockId,
                    code: 'anchor_orphan_party',
                    message: sprintf('Anchor "%s" references party "%s", which is not a declared party.', $anchor->anchorId !== '' ? $anchor->anchorId : '(no anchor_id)', $anchor->partyKey),
                    context: ['anchor_id' => $anchor->anchorId, 'party_key' => $anchor->partyKey],
                );

                continue;
            }

            if ($anchor->kind->isSigningSurface()) {
                $signedPartyKeys[$party->key] = true;
            }
        }

        // Every declared party must own a signing surface.
        foreach ($inspector->parties() as $party) {
            if ($party->key === '') {
                $findings[] = LintFinding::error(
                    rule: 'L3',
                    target: '',
                    code: 'party_missing_key',
                    message: 'A declared party has no key. Every party must declare a stable key (e.g. "seller").',
                );

                continue;
            }

            if (!isset($signedPartyKeys[$party->key])) {
                $findings[] = LintFinding::error(
                    rule: 'L3',
                    target: $party->key,
                    code: 'party_without_anchor',
                    message: sprintf('Signing party "%s" (role "%s") has no signature/initial anchor. Every signer needs a place to sign.', $party->key, $party->role),
                    context: ['party_key' => $party->key, 'role' => $party->role],
                );
            }
        }

        if ($findings === []) {
            $findings[] = LintFinding::pass('L3', 'Every declared party has a signing surface; every anchor targets a declared party.');
        }

        return $findings;
    }
}
