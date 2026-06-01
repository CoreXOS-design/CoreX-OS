<?php

return [
    // Ghostscript — used by the Compress tool. Install: apt install ghostscript (Linux)
    // or via the official Windows installer. On Windows the binary is usually
    // gswin64c.exe; set PDF_SUITE_GS_PATH in .env to the absolute path.
    'gs_path' => env('PDF_SUITE_GS_PATH', 'gs'),

    // Hard cap on a single uploaded file (kilobytes). 50 MB matches the splitter.
    'max_upload_kb' => env('PDF_SUITE_MAX_UPLOAD_KB', 51200),

    // Compress quality presets — passed to Ghostscript -dPDFSETTINGS.
    'compress_presets' => [
        'screen'  => 'Smallest size, lowest quality (72 dpi)',
        'ebook'   => 'Recommended — good quality, small size (150 dpi)',
        'printer' => 'Print quality, larger file (300 dpi)',
    ],

    // Enhance tool — DPI used when rasterising PDF pages before applying the
    // readability pipeline. Higher = sharper output but more memory per page.
    // 200 balances readability against the synchronous, single-request budget.
    'enhance_dpi' => env('PDF_SUITE_ENHANCE_DPI', 200),

    // Enhance one-click presets. Each maps to a tuned Imagick pipeline in
    // PdfSuiteController::enhancePage(). Order here is the order shown in the UI.
    'enhance_presets' => [
        'auto'     => 'Recommended — balanced contrast, de-blur and sharpen for most scans',
        'document' => 'Crisp black-on-white text — best for blurry photographed forms & IDs',
        'sharpen'  => 'Maximum de-blur — aggressive sharpening for very soft / out-of-focus scans',
        'photo'    => 'Colour-safe — denoise and gently sharpen while keeping original colours',
    ],
];
