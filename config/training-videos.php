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

    /*
    |--------------------------------------------------------------------------
    | Auto-generated how-to videos (AT-525, spec section 9)
    |--------------------------------------------------------------------------
    */
    'auto' => [
        // Screen scripts: resources/training/scripts/<screen_key>.php (written by Claude from the spec + code, approved by Johan).
        'scripts_dir' => resource_path('training/scripts'),
        // Finished files + working files. PRIVATE disk (agents only, streamed through an authenticated route), on the data volume with the rest of storage.
        'disk' => 'local',
        'folder' => 'training-videos-auto',
        'work_dir' => storage_path('app/training-videos-work'),
        'max_seconds' => 120,
        'intro_seconds' => 3.0,
        'outro_seconds' => 4.0,
        // Silence kept after each narrated step so the picture can breathe, and the pause before a step's action starts.
        'step_pad_seconds' => 0.6,
        'words_per_second' => 2.5,

        'tts' => [
            // 'piper' (free, offline, MIT) | 'fake' (silent, tests). A paid voice is one more class implementing App\Services\TrainingVideos\Tts\TtsProvider.
            'driver' => env('TRAINING_TTS_DRIVER', 'piper'),
            'piper' => [
                'python' => env('PIPER_PYTHON', '/mnt/HC_Volume_103099143/training-videos/tts-venv/bin/python'),
                'voice' => env('PIPER_VOICE', 'en_GB-jenny_dioco-medium'),
                'data_dir' => env('PIPER_DATA_DIR', '/mnt/HC_Volume_103099143/training-videos/voices'),
                'timeout' => 120,
            ],
        ],

        'recorder' => [
            'node' => env('TRAINING_NODE_BIN', 'node'),
            'script' => base_path('scripts/training-video-record.mjs'),
            'chromium' => env('TRAINING_CHROMIUM', '/usr/bin/chromium'),
            'base_url' => env('TRAINING_BASE_URL'),   // null = APP_URL
            'width' => 1280,
            'height' => 720,
            'timeout' => 600,
        ],
    ],
];
