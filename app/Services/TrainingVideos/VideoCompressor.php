<?php

namespace App\Services\TrainingVideos;

use Illuminate\Container\Attributes\Bind;

/**
 * Reads and shrinks an uploaded training video.
 *
 * Spec: .ai/specs/training-videos.md §8 (U1, U4). An interface so the tests can swap in a
 * fake and never need ffmpeg.
 */
#[Bind(FfmpegVideoCompressor::class)]
interface VideoCompressor
{
    /**
     * Read the file's video stream. Throws UnreadableVideoException when it is not a video
     * CoreX can read (U4) — judged by what is inside the file, never by its extension.
     *
     * @return array{duration: int, width: int, height: int, fps: float}
     */
    public function probe(string $path): array;

    /**
     * Write the shrunk MP4 and its poster image. Throws on any failure; the caller cleans up.
     *
     * @param array{duration: int, width: int, height: int, fps: float} $probe
     */
    public function compress(string $source, string $mp4Path, string $posterPath, array $probe): void;
}
