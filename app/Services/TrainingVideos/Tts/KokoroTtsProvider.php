<?php

namespace App\Services\TrainingVideos\Tts;

use Symfony\Component\Process\Process;

/**
 * AT-525 (spec section 9.19) - Kokoro-82M through kokoro-onnx: Apache-2.0 (commercial use OK), free, CPU only, in its own venv on the DATA volume. One process per line
 * (scripts/kokoro-tts.py: text on stdin, wav out); about 0.7-0.9 seconds of work per second of speech on this server.
 */
class KokoroTtsProvider implements TtsProvider
{
    /** $config = training-videos.auto.tts.kokoro (python, script, model, voices, timeout) + `voice` (the Kokoro voice id) + `key`. */
    public function __construct(private readonly array $config)
    {
    }

    public function name(): string
    {
        return 'kokoro:' . ($this->config['key'] ?? $this->config['voice']);
    }

    public function synthesize(string $text, string $outWav): float
    {
        $p = new Process([$this->config['python'], $this->config['script'], '--model', $this->config['model'], '--voices', $this->config['voices'], '--voice', $this->config['voice'], '--out', $outWav],
            null, ['TMPDIR' => dirname($this->config['model']) . '/../tmp']);
        $p->setInput(trim($text));
        $p->setTimeout((float) ($this->config['timeout'] ?? 180));
        $p->run();
        if (! $p->isSuccessful() || ! is_file($outWav)) {
            throw new \RuntimeException('The voice (Kokoro) could not speak this line: ' . trim(substr($p->getErrorOutput(), -300)));
        }

        return WavDuration::of($outWav);
    }
}
