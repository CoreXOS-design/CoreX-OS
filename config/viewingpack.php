<?php

return [
    /*
    | AT-160 items 2 & 4 — viewing-pack render tuning. All thresholds
    | env-configurable so the size/quality trade-off can be dialled per install.
    */

    // Redaction: DPI the source doc is rasterised to for the FLATTENED output
    // (the embedded redacted page). Lower = smaller pack + faster redaction.
    'redaction_default_dpi' => (int) env('VIEWINGPACK_REDACTION_DPI', 110),

    // Redaction: DPI for the on-screen PREVIEW only (decoupled from output so the
    // tool loads fast without hurting the flattened artifact).
    'redaction_preview_dpi' => (int) env('VIEWINGPACK_PREVIEW_DPI', 100),

    // Output: post-assembly Ghostscript pass — dedupes the fonts pdfunite
    // re-embeds per segment AND downsamples oversized images. Best-effort; a
    // failure never regresses the pack (the un-optimised merge is kept).
    'ghostscript_optimize'  => filter_var(env('VIEWINGPACK_GS_OPTIMIZE', true), FILTER_VALIDATE_BOOL),
    'ghostscript_path'      => env('VIEWINGPACK_GS_PATH', 'gs'),
    'gs_pdf_settings'       => env('VIEWINGPACK_GS_PDFSETTINGS', '/ebook'),
    'gs_image_dpi'          => (int) env('VIEWINGPACK_GS_IMAGE_DPI', 150),
];
