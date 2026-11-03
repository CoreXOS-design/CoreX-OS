<?php

namespace App\Services\TrainingVideos\Tts;

/**
 * AT-525 - text to speech, swappable. The recorder and assembler only ever see this interface; a paid voice (OpenAI, ElevenLabs, Azure, Google) is one more
 * class implementing it plus a branch in TtsManager - nothing else changes.
 */
interface TtsProvider
{
    /** A short stable name stored on the video (e.g. "piper:en_GB-jenny_dioco-medium"). */
    public function name(): string;

    /** Speak $text into a WAV file at $outWav and return how many seconds it lasts. */
    public function synthesize(string $text, string $outWav): float;
}
