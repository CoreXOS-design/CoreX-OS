<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Contracts;

use App\Models\Docuperfect\CompiledTemplate;
use App\Support\Docuperfect\Cds\Pipeline\SegmentationResult;

/**
 * AT-177 / WS4-E → WS4-S seam (spec §3 steps 3–4). Manages the mutable COMPILE DRAFT — a
 * `CompiledTemplate` in status=draft whose `structure` is the working CDS. The Studio (WS4-S,
 * cc1) drives binding and topology declaration through these methods; each mutation re-saves
 * the draft's structure. A published template is immutable (WS0 guard) — editing is always a
 * new version, never an in-place change to a published row.
 *
 * INTEGRATION (AT-177): WS4-E (cc2) implements; the Studio calls it. The Studio owns the
 * routes/controllers/blades; it never writes the `compiled_templates` row directly.
 */
interface CompileDraftManager
{
    /**
     * Seed a new draft from a segmentation result.
     *
     * @param array<string,mixed> $attributes agency_id, family, source_template_id, compiled_by…
     */
    public function createFromSegmentation(SegmentationResult $segmentation, array $attributes = []): CompiledTemplate;

    /** Replace the draft's whole CDS structure (Studio bulk-save). Rejects if not a draft. */
    public function updateStructure(CompiledTemplate $draft, array $structure): CompiledTemplate;

    /** Bind one fill-point to a Data Dictionary key (spec §3 step 3, satisfies L1). */
    public function bindField(CompiledTemplate $draft, string $blockId, string $fieldId, string $dictionaryKey): CompiledTemplate;

    /**
     * Declare / replace a signing party (spec §3 step 4).
     *
     * @param array<string,mixed> $party key, role, cardinality, required, ordering
     */
    public function declareParty(CompiledTemplate $draft, array $party): CompiledTemplate;

    /**
     * Set a block's visibility or editability PartyExpr (spec §2 declared, not detected).
     *
     * @param array{mode:string,party_keys?:list<string>} $expr
     */
    public function setBlockVisibility(CompiledTemplate $draft, string $blockId, array $expr): CompiledTemplate;

    public function setBlockEditability(CompiledTemplate $draft, string $blockId, array $expr): CompiledTemplate;
}
