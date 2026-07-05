<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds\Enums;

/**
 * CDS v2 — the typed, closed set of block kinds a compiled document is built from.
 *
 * A block is addressable by a stable block_id and NEVER by DOM position (spec §2).
 * This enum is the contract WS1 (linter) and WS2 (renderer) switch over — adding a
 * kind here is a deliberate, reviewed change to the canonical model.
 */
enum BlockType: string
{
    case Prose = 'prose';
    case Clause = 'clause';
    case FieldGroup = 'field_group';
    case Signature = 'signature';
    case Initial = 'initial';
    case InsertableSlot = 'insertable_slot';
    case Letterhead = 'letterhead';
    case PageBreak = 'page_break';
    case Conditional = 'conditional';

    /** Blocks that may carry Field children. */
    public function mayHaveFields(): bool
    {
        return $this === self::FieldGroup || $this === self::Conditional;
    }

    /** Blocks that may carry signing Anchors. */
    public function mayHaveAnchors(): bool
    {
        return $this === self::Signature || $this === self::Initial;
    }
}
