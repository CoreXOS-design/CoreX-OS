<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Contracts;

use App\Support\Docuperfect\Cds\Pipeline\IngestedDocument;
use App\Support\Docuperfect\Cds\Pipeline\SegmentationResult;

/**
 * AT-177 / WS4-E → WS4-S seam (spec §3 step 2). Splits an {@see IngestedDocument} into TYPED
 * ADDRESSABLE BLOCKS with stable block_ids — replacing today's fragile marker detection with
 * deterministic segmentation + human confirmation in the Studio.
 *
 * Prose stays prose; a detected fill-point becomes an UNBOUND Field; a signature zone becomes
 * a signature block with anchors; the header becomes a letterhead block. Where unsure it emits
 * a {@see \App\Support\Docuperfect\Cds\Pipeline\SegmentationWarning} for operator confirmation.
 *
 * INTEGRATION (AT-177): WS4-E (cc2) implements; the Studio (WS4-S, cc1) calls this to seed a
 * compile draft, then binds/declares topology on top of the result.
 */
interface SegmentationService
{
    public function segment(IngestedDocument $document): SegmentationResult;
}
