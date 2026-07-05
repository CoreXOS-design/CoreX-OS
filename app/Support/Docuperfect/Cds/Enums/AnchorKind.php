<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds\Enums;

/**
 * CDS v2 — the kind of signing surface an Anchor represents (spec §2).
 * Every declared signing party must own at least one signature anchor (linter L3).
 */
enum AnchorKind: string
{
    case Signature = 'signature';
    case Initial = 'initial';
    case Date = 'date';
    case Name = 'name';

    /** The anchor kinds that satisfy L3 "every role has a place to sign". */
    public function isSigningSurface(): bool
    {
        return $this === self::Signature || $this === self::Initial;
    }
}
