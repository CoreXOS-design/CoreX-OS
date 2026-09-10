<?php

return [
    // 2026-09-10 (cc5, AT-392 PDF load fix) — how many concurrent pdftoppm
    // processes RentalApplicationDocumentHighlightService::rasterizeIntoCache()
    // spawns when rendering a multi-page range. A server-tuning knob, not a
    // business setting — deliberately conservative default (this box shares
    // its 16 CPU cores across six concurrent lanes).
    'pdf_render_workers' => env('RENTAL_APPLICATIONS_PDF_RENDER_WORKERS', 4),
];
