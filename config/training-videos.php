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

        // Johan's plain-words rule for every how-to video (spec .ai/specs/training-videos.md section 9.13): the average agent's words, never the software's. word => what to say instead.
        // The same list is printed in the spec (a test keeps the two in step). One idea per line: a line over `max_line_words` is flagged.
        'plain_words' => [
            'banned' => [
                'tile' => 'box / block (or just say what it shows)', 'tiles' => 'boxes', 'panel' => 'list / box', 'record card' => 'everything we know about the house',
                'score reason' => 'why it ranks high', 'score reasons' => 'why it ranks high', 'provider' => 'who supplies it', 'filter chip' => 'button', 'chip' => 'button',
                'queue' => 'list of waiting jobs', 'sync' => 'update', 'toggle' => 'switch on or off', 'expand' => 'open', 'dropdown' => 'list', 'drop-down' => 'list',
                'modal' => 'box that opens', 'widget' => 'box', 'checkbox' => 'tick box', 'database' => 'our records', 'api' => 'connection', 'backend' => 'behind the scenes',
                'workflow' => 'steps', 'valuation' => 'evaluation', 'r/m²' => 'price per square metre', 'r/m2' => 'price per square metre',
            ],
            'max_line_words' => 20,
        ],

        'tts' => [
            // 'piper' (free, offline, MIT) | 'fake' (silent, tests). A paid voice is one more class implementing App\Services\TrainingVideos\Tts\TtsProvider.
            'driver' => env('TRAINING_TTS_DRIVER', 'piper'),
            // Chatterbox (Resemble AI, MIT) - voice cloning from a consented reference clip, CPU, own venv on the DATA volume; scripts/chatterbox-tts.py.
            'chatterbox' => [
                'python' => env('CHATTERBOX_PYTHON', '/mnt/HC_Volume_103099143/training-videos/chatterbox-venv/bin/python'),
                'script' => base_path('scripts/chatterbox-tts.py'),
                'hf_home' => env('CHATTERBOX_HF_HOME', '/mnt/HC_Volume_103099143/training-videos/chatterbox/hf'),
                'tmp' => env('CHATTERBOX_TMP', '/mnt/HC_Volume_103099143/training-videos/tmp'),
                'timeout' => 900,
            ],
            // Kokoro-82M via kokoro-onnx (CPU), in its own venv on the DATA volume; scripts/kokoro-tts.py speaks one text.
            'kokoro' => [
                'python' => env('KOKORO_PYTHON', '/mnt/HC_Volume_103099143/training-videos/kokoro-venv/bin/python'),
                'script' => base_path('scripts/kokoro-tts.py'),
                'model' => env('KOKORO_MODEL', '/mnt/HC_Volume_103099143/training-videos/kokoro/kokoro-v1.0.onnx'),
                'voices' => env('KOKORO_VOICES', '/mnt/HC_Volume_103099143/training-videos/kokoro/voices-v1.0.bin'),
                'timeout' => 180,
            ],
            'piper' => [
                'python' => env('PIPER_PYTHON', '/mnt/HC_Volume_103099143/training-videos/tts-venv/bin/python'),
                'data_dir' => env('PIPER_DATA_DIR', '/mnt/HC_Volume_103099143/training-videos/voices'),
                'timeout' => 120,
            ],
        ],

        // The voice every agency starts with; an agency picks its own in the Training library (agencies.training_video_voice).
        // AT-526: may the narrated tour's API start making voice clips by itself the first time it finds one missing? OFF until Johan has picked the CoreX voice
        // (31 Oct): clips are then made only by `php artisan training-videos:tour-audio`. Set TRAINING_TOUR_AUDIO_ON_DEMAND=true to switch the automatic making on.
        'tour_audio_on_demand' => (bool) env('TRAINING_TOUR_AUDIO_ON_DEMAND', false),
        'default_voice' => env('TRAINING_DEFAULT_VOICE', 'bf_emma'),
        // The LAST-RESORT voice: used only when the chosen voice's engine is not installed on this server (never on a runtime error - a video must not change voice half way).
        'fallback_voice' => env('TRAINING_FALLBACK_VOICE', 'jenny'),
        // The voice engines (spec section 9.18 / 9.19): licence and cost are shown above each group in the picker.
        'providers' => [
            'chatterbox' => ['label' => 'CoreX voices', 'licence' => 'Chatterbox, MIT - made by the system owner from a recording the speaker agreed to', 'cost' => 'Free - runs on our own server'],
            'kokoro' => ['label' => 'Kokoro', 'licence' => 'Apache-2.0, commercial use OK', 'cost' => 'Free - runs on our own server'],
            'piper' => ['label' => 'Piper', 'licence' => 'MIT code; each voice keeps its own dataset licence', 'cost' => 'Free - runs on our own server'],
        ],
        // Two plain sentences every voice speaks in the Training library's voice picker, so the voices can be compared like for like.
        'sample_text' => 'Click a house to see everything we know about it. To go after it, press Claim.',
        // The Piper voices on offer (British English, female; free and offline; models live on the DATA volume, in piper.data_dir). `speaker` = the id inside a
        // multi-speaker model. A voice is added here and its model downloaded with `python -m piper.download_voices <model> --data-dir <data_dir>`.
        'voices' => [
            // Kokoro (free, Apache-2.0). British female first, two natural American female voices for comparison.
            'bf_emma' => ['label' => 'Emma', 'provider' => 'kokoro', 'model' => 'kokoro', 'speaker' => null, 'about' => 'British female.'],
            'bf_isabella' => ['label' => 'Isabella', 'provider' => 'kokoro', 'model' => 'kokoro', 'speaker' => null, 'about' => 'British female.'],
            'bf_alice' => ['label' => 'Alice', 'provider' => 'kokoro', 'model' => 'kokoro', 'speaker' => null, 'about' => 'British female.'],
            'bf_lily' => ['label' => 'Lily', 'provider' => 'kokoro', 'model' => 'kokoro', 'speaker' => null, 'about' => 'British female.'],
            'af_heart' => ['label' => 'Heart', 'provider' => 'kokoro', 'model' => 'kokoro', 'speaker' => null, 'about' => 'American female, for comparison.'],
            'af_bella' => ['label' => 'Bella', 'provider' => 'kokoro', 'model' => 'kokoro', 'speaker' => null, 'about' => 'American female, for comparison.'],
            // Piper: no longer offered in the picker (Johan rejected all eight, 31 Oct); kept as the last-resort fallback and so older videos still show a voice name.
            'jenny' => ['label' => 'Jenny', 'provider' => 'piper', 'listed' => false, 'model' => 'en_GB-jenny_dioco-medium', 'speaker' => null, 'about' => 'Warm and friendly, southern British. The voice used so far.'],
            'cori' => ['label' => 'Cori', 'provider' => 'piper', 'listed' => false, 'model' => 'en_GB-cori-high', 'speaker' => null, 'about' => 'Clear and calm, high quality (the fullest sound of the set).'],
            'southern' => ['label' => 'Southern', 'provider' => 'piper', 'listed' => false, 'model' => 'en_GB-southern_english_female-low', 'speaker' => null, 'about' => 'Plain southern English, neutral and even. Lighter quality.'],
            'prudence' => ['label' => 'Prudence', 'provider' => 'piper', 'listed' => false, 'model' => 'en_GB-semaine-medium', 'speaker' => 0, 'about' => 'Bright and upbeat British voice.'],
            'poppy' => ['label' => 'Poppy', 'provider' => 'piper', 'listed' => false, 'model' => 'en_GB-semaine-medium', 'speaker' => 3, 'about' => 'Lively and animated British voice.'],
            'clara' => ['label' => 'Clara', 'provider' => 'piper', 'listed' => false, 'model' => 'en_GB-vctk-medium', 'speaker' => 107, 'about' => 'Standard southern English, even pace (speaker p225).'],
            'grace' => ['label' => 'Grace', 'provider' => 'piper', 'listed' => false, 'model' => 'en_GB-vctk-medium', 'speaker' => 85, 'about' => 'Standard English, soft and steady (speaker p229).'],
            'heather' => ['label' => 'Heather', 'provider' => 'piper', 'listed' => false, 'model' => 'en_GB-vctk-medium', 'speaker' => 18, 'about' => 'Standard English, clear and brisk (speaker p257).'],
        ],

        // Ad Studio's video ads (spec 9.16.1): optional music lives on the DATA volume with its licence beside it; NO track is configured or used until Johan approves one.
        'ads' => [
            'music_dir' => env('TRAINING_ADS_MUSIC_DIR', '/mnt/HC_Volume_103099143/training-videos/music'),
            'folder' => 'training-videos-auto/ads',
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
