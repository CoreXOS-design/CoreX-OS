<?php

namespace App\Services\Rentals;

use App\Models\RentalInspectionPhoto;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/rental-inspections.md §45.3 (Build I-1) — decides the `taken_at` / `taken_at_source` pair
 * for ONE uploaded inspection photo, server-side, in the order the spec fixes:
 *
 *   1. the client's own `captured_at` (ISO-8601 with offset) when the app sent one      → `client`
 *   2. otherwise the original file's EXIF DateTimeOriginal (+ OffsetTimeOriginal)        → `exif`
 *   3. otherwise the moment the server received it                                       → `server`
 *
 * Absorb, never break: this is evidence being uploaded, so NOTHING here may fail an upload. A value
 * that is missing, malformed or implausible is simply treated as "no claim" and the next source is
 * tried. A claim LATER than now + 5 minutes (clock skew allowance) is rejected as a claim — the photo
 * is stored as `server`, and the original claim is written to the log so it is never lost.
 *
 * Must be called with the ORIGINAL upload BEFORE PropertyImageStorer::store() (which re-encodes the
 * image and drops its metadata). It only READS the file; PropertyImageStorer itself is untouched.
 *
 * EXIF is only readable from JPEG here (PHP's exif_read_data); a HEIC/PNG/WebP upload with no client
 * time honestly falls through to `server`.
 */
class RentalInspectionPhotoCaptureTime
{
    /** A claim later than now + this many minutes is treated as a wrong clock, not a capture time. */
    public const CLOCK_SKEW_MINUTES = 5;

    /** Anything earlier cannot be a real capture time (EXIF "0000:00:00 00:00:00" garbage parses to year 0/-1). */
    private const EARLIEST_PLAUSIBLE_YEAR = 1970;

    /**
     * @param  array<string, mixed>  $logContext  identifying fields (inspection id, user id…) added to a rejection log line
     * @return array{taken_at: Carbon, taken_at_source: string}
     */
    public function resolve(?UploadedFile $file, ?string $clientCapturedAt, array $logContext = []): array
    {
        $claim = null;
        $source = null;

        $client = $this->parseClientTime($clientCapturedAt);
        if ($client !== null) {
            $claim = $client;
            $source = RentalInspectionPhoto::TAKEN_AT_CLIENT;
        } elseif ($file !== null) {
            $exif = $this->readExifTime($file);
            if ($exif !== null) {
                $claim = $exif;
                $source = RentalInspectionPhoto::TAKEN_AT_EXIF;
            }
        }

        if ($claim === null) {
            return $this->serverTime();
        }

        if ($claim->gt(now()->addMinutes(self::CLOCK_SKEW_MINUTES))) {
            Log::warning('Rental inspection photo: capture time in the future rejected — stored as the server receive time', $logContext + [
                'claimed_source' => $source,
                'claimed_taken_at' => $claim->toIso8601String(),
                'received_at' => now()->toIso8601String(),
            ]);

            return $this->serverTime();
        }

        return ['taken_at' => $claim, 'taken_at_source' => $source];
    }

    /** @return array{taken_at: Carbon, taken_at_source: string} */
    private function serverTime(): array
    {
        return ['taken_at' => now(), 'taken_at_source' => RentalInspectionPhoto::TAKEN_AT_SERVER];
    }

    private function parseClientTime(?string $value): ?Carbon
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            $time = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        return $this->plausible($time) ? $time->setTimezone(config('app.timezone')) : null;
    }

    private function readExifTime(UploadedFile $file): ?Carbon
    {
        if (! function_exists('exif_read_data')) {
            return null;
        }

        try {
            $path = $file->getRealPath();
            $mime = strtolower((string) $file->getMimeType());
            if (! $path || ! in_array($mime, ['image/jpeg', 'image/jpg', 'image/pjpeg'], true)) {
                return null;
            }

            $exif = @exif_read_data($path, 'EXIF');
            if (! is_array($exif)) {
                return null;
            }

            $raw = $exif['DateTimeOriginal'] ?? $exif['DateTimeDigitized'] ?? null;
            if (! is_string($raw) || ! preg_match('/^\d{4}:\d{2}:\d{2} \d{2}:\d{2}:\d{2}$/', trim($raw))) {
                return null;
            }

            // PHP 8.2's exif extension has no name for tag 0x9011/0x9012 (it surfaces them as
            // "UndefinedTag:0x9011"); newer builds name them. Accept either spelling, so a pool on
            // either PHP version reads the same offset.
            $offset = $exif['OffsetTimeOriginal'] ?? $exif['UndefinedTag:0x9011']
                ?? $exif['OffsetTimeDigitized'] ?? $exif['UndefinedTag:0x9012'] ?? null;
            $timezone = (is_string($offset) && preg_match('/^[+-]\d{2}:\d{2}$/', trim($offset)))
                ? trim($offset)
                : config('app.timezone');

            $time = Carbon::createFromFormat('Y:m:d H:i:s', trim($raw), $timezone);
            if ($time === false || ! $this->plausible($time)) {
                return null;
            }

            return $time->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function plausible(Carbon $time): bool
    {
        return $time->year >= self::EARLIEST_PLAUSIBLE_YEAR;
    }
}
