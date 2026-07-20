<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * AT-173 — generate the media-encryption key. Mirrors `key:generate`: prints a
 * fresh 256-bit key (base64) and, with --write, sets MEDIA_ENCRYPTION_KEY in .env
 * ONLY if it is not already set (never silently rotates a live key — that would
 * orphan every already-encrypted file). Rotation is a deliberate separate flow.
 */
class GenerateMediaKey extends Command
{
    protected $signature = 'media:key:generate {--write : Write it into .env (only if MEDIA_ENCRYPTION_KEY is empty)}';

    protected $description = 'Generate a MEDIA_ENCRYPTION_KEY (AES-256) for AT-173 media encryption at rest';

    public function handle(): int
    {
        $key = 'base64:' . base64_encode(random_bytes(32));

        if (! $this->option('write')) {
            $this->info('Generated media key (add to .env as MEDIA_ENCRYPTION_KEY):');
            $this->line($key);
            $this->warn('Store it exactly once per environment. Losing it makes already-encrypted media unrecoverable.');

            return self::SUCCESS;
        }

        $path = base_path('.env');
        if (! File::exists($path)) {
            $this->error('.env not found.');

            return self::FAILURE;
        }

        $env = File::get($path);
        if (preg_match('/^MEDIA_ENCRYPTION_KEY=.+$/m', $env)) {
            $this->error('MEDIA_ENCRYPTION_KEY is already set — refusing to overwrite (would orphan encrypted media). Rotate deliberately with media:rotate-key.');

            return self::FAILURE;
        }

        if (preg_match('/^MEDIA_ENCRYPTION_KEY=\s*$/m', $env)) {
            $env = preg_replace('/^MEDIA_ENCRYPTION_KEY=\s*$/m', "MEDIA_ENCRYPTION_KEY={$key}", $env);
        } else {
            $env = rtrim($env, "\n") . "\n\n# AT-173 media encryption at rest\nMEDIA_ENCRYPTION_KEY={$key}\n";
        }
        File::put($path, $env);

        $this->info('MEDIA_ENCRYPTION_KEY written to .env.');

        return self::SUCCESS;
    }
}
