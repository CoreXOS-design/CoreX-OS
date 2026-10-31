<?php

namespace App\Services\TrainingVideos\Tts;

use App\Models\Agency;

/**
 * AT-525 (spec .ai/specs/training-videos.md section 9.12) - the voices a how-to video can be spoken in, and which one an agency has chosen. The catalogue is config
 * (training-videos.auto.voices); the choice is agencies.training_video_voice, with training-videos.auto.default_voice as the default - nothing is hard-coded.
 */
class TrainingVoices
{
    /** @return array<string, array{key: string, label: string, provider: string, model: string, speaker: ?int, about: string, listed: bool, installed: bool}> */
    public function catalogue(): array
    {
        $out = [];
        foreach ((array) config('training-videos.auto.voices', []) as $key => $v) {
            $provider = (string) ($v['provider'] ?? 'piper');
            $out[$key] = ['key' => (string) $key, 'label' => (string) $v['label'], 'provider' => $provider, 'model' => (string) $v['model'], 'speaker' => $v['speaker'] ?? null,
                'about' => (string) ($v['about'] ?? ''), 'listed' => (bool) ($v['listed'] ?? true), 'installed' => $this->installed($provider, (string) $v['model'])];
        }

        return $out;
    }

    /** Is the engine (and, for Piper, the voice model) on this server? */
    private function installed(string $provider, string $model): bool
    {
        if ($provider === 'kokoro') {
            $c = (array) config('training-videos.auto.tts.kokoro');

            return is_file((string) ($c['python'] ?? '')) && is_file((string) ($c['model'] ?? '')) && is_file((string) ($c['voices'] ?? ''));
        }
        $dir = rtrim((string) config('training-videos.auto.tts.piper.data_dir'), '/');

        return is_file($dir . '/' . $model . '.onnx');
    }

    /** The voices the picker offers (Piper's are kept out: Johan rejected them, they remain only as the last-resort fallback). */
    public function listed(): array
    {
        return array_filter($this->catalogue(), fn ($v) => $v['listed']);
    }

    public function fallbackKey(): string
    {
        $k = (string) config('training-videos.auto.fallback_voice', 'jenny');

        return isset(config('training-videos.auto.voices', [])[$k]) ? $k : $this->defaultKey();
    }

    public function find(?string $key): ?array
    {
        return $key ? ($this->catalogue()[$key] ?? null) : null;
    }

    public function defaultKey(): string
    {
        $k = (string) config('training-videos.auto.default_voice', 'jenny');

        return isset(config('training-videos.auto.voices', [])[$k]) ? $k : (string) array_key_first((array) config('training-videos.auto.voices', [])) ;
    }

    /** The voice key an agency has chosen (or the default when none, or when its choice is no longer on offer). */
    public function keyFor(?Agency $agency): string
    {
        $k = $agency?->training_video_voice;

        return $k && $this->find($k) ? $k : $this->defaultKey();
    }

    public function choose(Agency $agency, string $key): void
    {
        if (! $this->find($key)) {
            throw new \InvalidArgumentException("Unknown voice '{$key}'.");
        }
        $agency->forceFill(['training_video_voice' => $key])->save();
    }

    /** What a PiperTtsProvider needs for this voice key (the box-level paths plus the model and speaker). */
    public function piperConfig(?string $key): array
    {
        $v = $this->find($key ?: $this->defaultKey()) ?? $this->find($this->defaultKey());

        return array_merge((array) config('training-videos.auto.tts.piper'), ['voice' => $v['model'], 'speaker' => $v['speaker'], 'key' => $v['key']]);
    }

    /** What a KokoroTtsProvider needs for this voice key. */
    public function kokoroConfig(string $key): array
    {
        return array_merge((array) config('training-videos.auto.tts.kokoro'), ['voice' => $key, 'key' => $key]);
    }

    /** Label for a stored tts_voice ("piper:cori", or an older "piper:en_GB-jenny_dioco-medium"). */
    public function labelFor(?string $stored): string
    {
        $k = preg_replace('/^(piper|kokoro):/', '', (string) $stored);
        if ($v = $this->find($k)) {
            return $v['label'];
        }
        foreach ($this->catalogue() as $v) {
            if ($v['provider'] === 'piper' && $v['model'] === $k && $v['speaker'] === null) {
                return $v['label'];
            }
        }

        return (string) $stored;
    }
}
