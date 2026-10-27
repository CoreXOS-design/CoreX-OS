<?php

/*
|--------------------------------------------------------------------------
| Training Videos — uploaded video files (spec .ai/specs/training-videos.md §8)
|--------------------------------------------------------------------------
|
| Platform settings for CoreX's own public Training Videos page. They are NOT
| agency settings (the page is CoreX-owned, identical for every visitor), so
| there is deliberately no Setup Wizard entry (non-negotiable #10a).
*/

return [

    // Largest file the owner may upload (U3).
    'max_upload_bytes' => 2 * 1024 * 1024 * 1024,

    // Size of each piece the browser sends. Must stay safely under the web server's
    // per-request limit (PHP upload_max_filesize 60M, nginx client_max_body_size 100M).
    'chunk_bytes' => 8 * 1024 * 1024,

    // Dedicated queue with its own worker so compressing can never hold up mail,
    // matching or anything an agent is waiting on (U5).
    'queue' => 'training-videos',

    // Where unfinished / awaiting-compression uploads live (private disk).
    'upload_dir' => 'training-video-uploads',

    // Disk folders for finished files: public while the video is live, private while archived (U7, U8).
    'folder' => 'training-videos',

    // Pieces of an upload never finished are removed after this long (U11).
    'abandoned_after_hours' => 24,

    // A video still Processing after this long gets the "worker may not be running" warning (U6).
    'stuck_after_minutes' => 60,

    'ffmpeg'  => env('FFMPEG_BIN', 'ffmpeg'),
    'ffprobe' => env('FFPROBE_BIN', 'ffprobe'),

    // Compression (U1). 720p keeps on-screen text readable at a fraction of the size.
    'compress' => [
        'max_side'         => 1280,
        'max_fps'          => 30,
        'crf'              => 26,
        'preset'           => 'medium',
        'audio_kbps'       => 96,
        'poster_at_second' => 3,
        'timeout'          => 3600,
        // null = half the server's cores, so a long compress never starves the site.
        'threads'          => null,
    ],
];
