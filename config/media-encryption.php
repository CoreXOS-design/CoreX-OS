<?php

// AT-173 — application-level media encryption at rest.
//
// CoreX encrypts client-sensitive files (WhatsApp media, FICA documents) with an
// app-managed key BEFORE writing to disk, and decrypts transparently on serve.
// Protects the disk, the off-box backups, a DB/volume dump and casual file
// browsing. It does NOT defend a live-root adversary who can read this key from
// the environment — that is an accepted, documented limit (see the spec).

return [

    // Master switch. When false, NEW writes stay plaintext (reads still transparently
    // decrypt anything already encrypted, so toggling is always safe). Auto-enables
    // when a key is present unless explicitly overridden.
    'enabled' => (bool) env('MEDIA_ENCRYPTION_ENABLED', env('MEDIA_ENCRYPTION_KEY') !== null),

    // The active data-encryption key. 32 raw bytes, provided base64 as
    // `MEDIA_ENCRYPTION_KEY=base64:....`. Generate with `php artisan media:key:generate`.
    // NEVER commit it; it lives only in each environment's .env (mode 0600), the same
    // trust level as APP_KEY / DB credentials. Deliberately SEPARATE from APP_KEY so
    // rotating login/session keys never breaks media decryption.
    'key' => env('MEDIA_ENCRYPTION_KEY'),

    // Previous key, kept during a rotation window so files written under the old key
    // still decrypt while `media:rotate-key` re-wraps them. Same base64 form.
    'previous_key' => env('MEDIA_ENCRYPTION_KEY_PREVIOUS'),

    // Envelope version marker written as the first bytes of every ciphertext file,
    // so the read path can tell encrypted from legacy-plaintext during migration and
    // we can rotate the algorithm later without ambiguity.
    'magic' => 'CXE1',

    'cipher' => 'aes-256-gcm',
];
