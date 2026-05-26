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
];
