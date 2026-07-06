<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds\Pipeline;

use App\Support\Docuperfect\Cds\Cds;

/**
 * AT-177 / WS4-E — the output of deterministic segmentation (spec §3 step 2).
 *
 * `structure` is a DRAFT CDS structure: typed addressable blocks with stable block_ids, with
 * detected fill-points as UNBOUND {@see \App\Support\Docuperfect\Cds\Field}s and detected
 * signature zones as anchors. It is NOT yet publishable — binding (§3 step 3) and topology
 * declaration (§3 step 4) happen in the Studio on top of this seed, then the linter gates it.
 *
 * `warnings` carry the block-addressed things the operator must confirm; `confidence` is the
 * overall segmentation confidence (0..1). The Studio reads all three.
 */
final class SegmentationResult
{
    /**
     * @param array                    $structure  draft CDS structure (partial; pre-bind, pre-topology)
     * @param list<SegmentationWarning> $warnings
     */
    public function __construct(
        public readonly array $structure,
        public readonly array $warnings = [],
        public readonly float $confidence = 1.0,
    ) {
    }

    /** Hydrate the draft structure into the canonical CDS DTO (may be pre-publishable). */
    public function toCds(): Cds
    {
        return Cds::fromArray($this->structure);
    }

    public function unboundFieldCount(): int
    {
        $count = 0;
        foreach ($this->structure['blocks'] ?? [] as $block) {
            foreach ($block['fields'] ?? [] as $field) {
                if (trim((string) ($field['binding'] ?? '')) === '') {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function detectedAnchorCount(): int
    {
        $count = 0;
        foreach ($this->structure['blocks'] ?? [] as $block) {
            $count += count($block['anchors'] ?? []);
        }

        return $count;
    }

    public function toArray(): array
    {
        return [
            'structure' => $this->structure,
            'warnings' => array_map(static fn (SegmentationWarning $w) => $w->toArray(), $this->warnings),
            'confidence' => $this->confidence,
            'unbound_field_count' => $this->unboundFieldCount(),
            'detected_anchor_count' => $this->detectedAnchorCount(),
        ];
    }
}
