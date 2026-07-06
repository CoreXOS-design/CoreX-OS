<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds;

use App\Support\Docuperfect\Cds\Enums\AnchorKind;

/**
 * CDS v2 — a signing surface bound to a declared party (spec §2 Anchor).
 *
 * Anchors are COMPILED, not stamped at serve time (obsoletes SignatureSurfaceNormalizer).
 * `page`/`coords` position the anchor for the PDF render; `blockRelative` positions it
 * for the web render. Web/PDF placement parity is proven at compile time (L6).
 */
final class Anchor
{
    /**
     * @param array{page?:int,x?:float,y?:float,w?:float,h?:float}|null $coords  PDF placement
     * @param array{after?:string,align?:string}|null                   $blockRelative  web placement hint
     */
    public function __construct(
        public readonly string $anchorId,
        public readonly AnchorKind $kind,
        public readonly string $partyKey,
        public readonly ?array $coords = null,
        public readonly ?array $blockRelative = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['anchor_id'],
            AnchorKind::from((string) $data['kind']),
            (string) $data['party_key'],
            $data['coords'] ?? null,
            $data['block_relative'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'anchor_id' => $this->anchorId,
            'kind' => $this->kind->value,
            'party_key' => $this->partyKey,
            'coords' => $this->coords,
            'block_relative' => $this->blockRelative,
        ];
    }
}
