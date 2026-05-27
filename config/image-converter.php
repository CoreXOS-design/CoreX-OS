<?php

return [
    // ImageMagick CLI binary. Install on Windows:
    //   winget install ImageMagick.ImageMagick
    // The binary is usually `magick.exe`. Set IMAGE_CONVERTER_MAGICK_PATH in .env
    // to its absolute path if it's not on PATH (e.g. C:\Program Files\ImageMagick-7.x.x\magick.exe).
    'magick_path' => env('IMAGE_CONVERTER_MAGICK_PATH', 'magick'),

    // Hard cap on a single uploaded file (kilobytes).
    'max_upload_kb' => env('IMAGE_CONVERTER_MAX_UPLOAD_KB', 51200),
];
