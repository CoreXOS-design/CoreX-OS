<?php

namespace App\Services\TrainingVideos\Tts;

/** AT-525 - how long a PCM WAV lasts, read from its header (Piper and the fake voice both write plain PCM). */
final class WavDuration
{
    public static function of(string $path): float
    {
        $h = (string) file_get_contents($path, false, null, 0, 1024);
        if (strlen($h) < 44 || ! str_starts_with($h, 'RIFF')) {
            throw new \RuntimeException('Not a WAV file: ' . $path);
        }
        $byteRate = unpack('V', substr($h, 28, 4))[1] ?? 0;
        $pos = strpos($h, 'data');
        $size = $pos !== false ? (unpack('V', substr($h, $pos + 4, 4))[1] ?? 0) : 0;
        // a streamed WAV may carry 0 / 0xFFFFFFFF as the data size: fall back to the file size
        if ($size === 0 || $size === 0xFFFFFFFF) {
            $size = max(0, filesize($path) - ($pos !== false ? $pos + 8 : 44));
        }

        return $byteRate > 0 ? $size / $byteRate : 0.0;
    }
}
