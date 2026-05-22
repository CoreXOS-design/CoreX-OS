<?php

namespace App\Events;

use App\Models\Presentation;
use App\Models\PresentationVersion;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by PresentationGeneratorService after a one-button presentation has
 * been assembled (Presentation + PresentationSnapshot + PresentationVersion
 * all persisted with engine-run linkage intact).
 *
 * No listeners are attached in Phase 1. Phase 8 wires this to the
 * `presentation_outcome_pending` Event Class for chasing.
 *
 * Spec: .ai/specs/presentations.md §3.1 + §5.7
 */
class PresentationGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Presentation $presentation,
        public PresentationVersion $version,
    ) {}
}
