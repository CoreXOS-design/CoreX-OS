<?php

namespace App\Services\TrainingVideos\Tts;

use Symfony\Component\Process\Process;

/**
 * AT-525 - Piper (https://github.com/OHF-Voice/piper1-gpl, MIT code; each voice has its own licence - see spec section 9): free, offline, runs on this server's CPU
 * (about 0.4x real time on the ARM box), no data leaves the machine. Installed once in a venv on the data volume (config training-videos.auto.tts.piper).
 */
class PiperTtsProvider implements TtsProvider
{
    public function __construct(private readonly array $config)
    {
    }

    public function name(): string
    {
        return 'piper:' . $this->config['voice'];
    }

    public function synthesize(string $text, string $outWav): float
    {
        $p = new Process([$this->config['python'], '-m', 'piper', '-m', $this->config['voice'], '--data-dir', $this->config['data_dir'], '-f', $outWav]);
        $p->setInput(trim($text));
        $p->setTimeout((float) ($this->config['timeout'] ?? 120));
        $p->run();
        if (! $p->isSuccessful() || ! is_file($outWav)) {
            throw new \RuntimeException('The voice (Piper) could not speak this line: ' . trim(substr($p->getErrorOutput(), -300)));
        }

        return WavDuration::of($outWav);
    }
}
