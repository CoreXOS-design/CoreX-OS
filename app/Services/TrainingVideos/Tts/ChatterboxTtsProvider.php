<?php

namespace App\Services\TrainingVideos\Tts;

use Symfony\Component\Process\Process;

/**
 * AT-525 (spec section 9.20) - Chatterbox (Resemble AI, MIT, commercial use OK): speaks in the voice of a CoreX voice's consented reference clip. Free, CPU only, in its own
 * venv on the DATA volume (scripts/chatterbox-tts.py). The reference clip is read from the private disk and never leaves the server.
 */
class ChatterboxTtsProvider implements BatchTtsProvider
{
    /** $config = training-videos.auto.tts.chatterbox (python, script, hf_home, tmp, timeout) + `reference` (absolute path of the clip) + `key`. */
    public function __construct(private readonly array $config)
    {
    }

    public function name(): string
    {
        return 'chatterbox:' . $this->config['key'];
    }

    public function synthesize(string $text, string $outWav): float
    {
        return $this->synthesizeMany([['text' => $text, 'out' => $outWav]])[0];
    }

    public function synthesizeMany(array $jobs): array
    {
        $p = new Process([$this->config['python'], $this->config['script'], '--ref', $this->config['reference'], '--batch'], null,
            ['HF_HOME' => $this->config['hf_home'], 'TMPDIR' => $this->config['tmp'], 'TOKENIZERS_PARALLELISM' => 'false']);
        $p->setInput(json_encode(array_map(fn ($j) => ['text' => trim($j['text']), 'out' => $j['out']], $jobs)));
        $p->setTimeout((float) ($this->config['timeout'] ?? 900));
        $p->run();
        $out = [];
        foreach ($jobs as $j) {
            if (! $p->isSuccessful() || ! is_file($j['out'])) {
                throw new \RuntimeException('The voice (Chatterbox) could not speak this line: ' . trim(substr($p->getErrorOutput(), -300)));
            }
            $out[] = WavDuration::of($j['out']);
        }

        return $out;
    }
}
