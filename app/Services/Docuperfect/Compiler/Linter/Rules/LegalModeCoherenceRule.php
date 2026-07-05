<?php

namespace App\Services\Docuperfect\Compiler\Linter\Rules;

use App\Services\Docuperfect\Compiler\Contracts\DataDictionaryResolver;
use App\Services\Docuperfect\Compiler\Linter\LintFinding;
use App\Services\Docuperfect\Compiler\Linter\LinterContext;
use App\Services\Docuperfect\Compiler\Linter\LintRule;
use App\Support\Docuperfect\Cds\Cds;
use App\Support\Docuperfect\Cds\Enums\DeliveryMode;

/**
 * L7 — Legal-mode coherence (§4 / §6.1).
 *
 * South African law forbids e-signing certain instruments: Alienation of Land Act 68 of
 * 1981 §2(1) + ECTA 25 of 2002 §13(1) mean a sale of land / Offer to Purchase MUST be
 * wet-ink; ECTA Schedule 1 excludes wills and bills of exchange. An OTP therefore may not
 * publish with web e-sign enabled.
 *
 * Today this is a runtime 4-layer name heuristic (`isEsignBlocked()`) a renamed template
 * could fool. Here it is a COMPILE-TIME INVARIANT over the declared, canonical
 * {@see \App\Support\Docuperfect\Cds\Enums\LegalClass} — a document-class fact, not a name
 * match. The forbidden-class fact and its statute citation live on the enum itself (WS0),
 * the single source of truth.
 */
final class LegalModeCoherenceRule implements LintRule
{
    public function code(): string
    {
        return 'L7';
    }

    public function evaluate(Cds $cds, DataDictionaryResolver $dictionary, LinterContext $context): array
    {
        if ($cds->legalClass->forbidsEsign() && $cds->hasDeliveryMode(DeliveryMode::WebEsign)) {
            return [LintFinding::error(
                'L7',
                '',
                'esign_forbidden_for_legal_class',
                sprintf(
                    'legal_class "%s" may not be e-signed under South African law (%s), but delivery_modes enables "web_esign". This instrument must be wet-ink / download only.',
                    $cds->legalClass->value,
                    $cds->legalClass->statuteCitation() ?? 'SA law'
                ),
                ['legal_class' => $cds->legalClass->value, 'statute' => $cds->legalClass->statuteCitation()],
            )];
        }

        return [LintFinding::pass('L7', 'Delivery modes are legally coherent with the document class.')];
    }
}
