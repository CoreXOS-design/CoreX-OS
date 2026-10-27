<?php

namespace App\Services\TrainingVideos;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * ffmpeg → MP4 (H.264 + AAC), longest side capped at 1280 px (never upscaled), frame rate
 * capped at 30, quality-based (CRF), +faststart so playback starts before the whole file has
 * downloaded. Spec: .ai/specs/training-videos.md §8 U1. All numbers live in
 * config/training-videos.php.
 *
 * Runs at the lowest CPU priority on half the server's cores: a long compress must never
 * starve the site (U5).
 */
class FfmpegVideoCompressor implements VideoCompressor
{
    public function probe(string $path): array
    {
        $process = new Process([
            config('training-videos.ffprobe'), '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height,avg_frame_rate,r_frame_rate:format=duration',
            '-of', 'json', $path,
        ]);
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new UnreadableVideoException(UnreadableVideoException::MESSAGE, $e);
        }

        $data   = json_decode($process->getOutput(), true);
        $stream = $data['streams'][0] ?? null;

        if (! $process->isSuccessful() || ! is_array($stream) || empty($stream['width']) || empty($stream['height'])) {
            throw new UnreadableVideoException();
        }

        return [
            'duration' => (int) round((float) ($data['format']['duration'] ?? 0)),
            'width'    => (int) $stream['width'],
            'height'   => (int) $stream['height'],
            'fps'      => $this->fps($stream['avg_frame_rate'] ?? '') ?: $this->fps($stream['r_frame_rate'] ?? ''),
        ];
    }

    public function compress(string $source, string $mp4Path, string $posterPath, array $probe): void
    {
        $cfg  = config('training-videos.compress');
        $side = (int) $cfg['max_side'];

        // Fit inside a side×side box: the longest side is capped, nothing is ever enlarged.
        $filters = ["scale='min({$side},iw)':'min({$side},ih)':force_original_aspect_ratio=decrease:force_divisible_by=2"];
        if ($probe['fps'] > $cfg['max_fps']) {
            $filters[] = 'fps=' . (int) $cfg['max_fps'];
        }

        $threads = $cfg['threads'] ?: max(1, intdiv($this->cores(), 2));

        $this->run([
            'nice', '-n', '19', config('training-videos.ffmpeg'), '-y', '-v', 'error', '-i', $source,
            '-map', '0:v:0', '-map', '0:a:0?',
            '-vf', implode(',', $filters),
            '-c:v', 'libx264', '-preset', (string) $cfg['preset'], '-crf', (string) $cfg['crf'], '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', $cfg['audio_kbps'] . 'k',
            '-movflags', '+faststart', '-threads', (string) $threads,
            $mp4Path,
        ], (int) $cfg['timeout']);

        // Poster: a frame from ~3 seconds in (or the middle of a very short clip).
        $at = min((float) $cfg['poster_at_second'], max(0.0, $probe['duration'] / 2));
        $this->run([
            'nice', '-n', '19', config('training-videos.ffmpeg'), '-y', '-v', 'error',
            '-ss', (string) $at, '-i', $mp4Path, '-frames:v', '1', '-q:v', '3', $posterPath,
        ], 300);

        if (! is_file($mp4Path) || filesize($mp4Path) === 0 || ! is_file($posterPath)) {
            throw new RuntimeException('ffmpeg finished without writing the video.');
        }
    }

    private function run(array $command, int $timeout): void
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('ffmpeg failed: ' . mb_substr(trim($process->getErrorOutput()), -500));
        }
    }

    /** "30000/1001" → 29.97; "0/0" or junk → 0. */
    private function fps(string $rate): float
    {
        if (! preg_match('#^(\d+)/(\d+)$#', $rate, $m) || (int) $m[2] === 0) {
            return 0.0;
        }

        return (int) $m[1] / (int) $m[2];
    }

    private function cores(): int
    {
        $n = (int) @shell_exec('nproc 2>/dev/null');

        return $n > 0 ? $n : 2;
    }
}
