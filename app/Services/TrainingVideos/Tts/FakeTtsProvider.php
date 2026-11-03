<?php

namespace App\Services\TrainingVideos\Tts;

/** AT-525 - a silent voice for tests: a valid WAV of the length the words would take, written in pure PHP (no ffmpeg, no model). */
class FakeTtsProvider implements TtsProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function synthesize(string $text, string $outWav): float
    {
        $seconds = max(0.5, str_word_count($text) / (float) config('training-videos.auto.words_per_second', 2.5));
        $rate = 8000;
        $samples = (int) round($seconds * $rate);
        $data = str_repeat("\0\0", $samples);
        file_put_contents($outWav, 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16) . 'data' . pack('V', strlen($data)) . $data);

        return $samples / $rate;
    }
}
