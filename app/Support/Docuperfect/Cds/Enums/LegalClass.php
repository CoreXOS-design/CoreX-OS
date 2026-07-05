<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds\Enums;

/**
 * CDS v2 — the legal document-class of a compiled template (spec §6.1, linter L7).
 *
 * The legal e-sign constraint is a DOCUMENT-CLASS FACT, resolved from the document
 * family at compile time — NOT a runtime name-regex. South African law forbids
 * e-signing certain instruments:
 *   - Alienation of Land Act 68 of 1981 §2(1) + ECTA 25 of 2002 §13(1) — a sale of
 *     land / Offer to Purchase MUST be wet-ink.
 *
 * L7 rejects at compile time any template whose legal_class forbids e-sign while
 * `web_esign` is enabled. The block is a compile-time invariant with an audit trail.
 */
enum LegalClass: string
{
    /** Sale of land / OTP / deed of sale — e-sign forbidden (wet-ink only). */
    case AlienationOfLand = 'alienation_of_land';

    /** Wills, per ECTA Schedule 1 — e-sign forbidden. */
    case Will = 'will';

    /** Bills of exchange, per ECTA Schedule 1 — e-sign forbidden. */
    case BillOfExchange = 'bill_of_exchange';

    /** Everything else — e-sign permitted (mandates, FICA, disclosures, leases, marketing consent). */
    case General = 'general';

    /**
     * Does this legal class forbid electronic signature?
     * When true, L7 blocks publish with DeliveryMode::WebEsign enabled.
     */
    public function forbidsEsign(): bool
    {
        return match ($this) {
            self::AlienationOfLand, self::Will, self::BillOfExchange => true,
            self::General => false,
        };
    }

    /** The statute cited on the L7 audit record, for the lint report. */
    public function statuteCitation(): ?string
    {
        return match ($this) {
            self::AlienationOfLand => 'Alienation of Land Act 68 of 1981 §2(1); ECTA 25 of 2002 §13(1)',
            self::Will => 'ECTA 25 of 2002 Schedule 1',
            self::BillOfExchange => 'ECTA 25 of 2002 Schedule 1',
            self::General => null,
        };
    }
}
