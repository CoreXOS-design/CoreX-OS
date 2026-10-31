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
        // CoreX voices: the system owner's Chatterbox voices (section 9.20), the same for every agency. First in the picker.
        $platform = [];
        foreach ($this->platformVoices() as $pv) {
            $platform[$pv->voice_key] = ['key' => $pv->voice_key, 'label' => $pv->name, 'provider' => 'chatterbox', 'model' => 'chatterbox', 'speaker' => null,
                'about' => (string) ($pv->about ?: 'A CoreX voice.'), 'listed' => true, 'installed' => $this->installed('chatterbox', 'chatterbox'), 'is_default' => (bool) $pv->is_default];
        }

        return $platform + $out;
    }

    public function engineInstalled(string $provider): bool
    {
        return $this->installed($provider, $provider);
    }

    /** Is the engine (and, for Piper, the voice model) on this server? */
    private function installed(string $provider, string $model): bool
    {
        if ($provider === 'chatterbox') {
            $c = (array) config('training-videos.auto.tts.chatterbox');

            return is_file((string) ($c['python'] ?? '')) && is_file((string) ($c['script'] ?? ''));
        }
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

    /** The platform's active CoreX voices (none when the table is not migrated yet). */
    public function platformVoices()
    {
        return \Illuminate\Support\Facades\Schema::hasTable('training_platform_voices')
            ? \App\Models\TrainingPlatformVoice::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get()
            : collect();
    }

    /** The platform default: the CoreX voice the system owner picked, else the configured default (Kokoro). Every agency uses it unless its admin chooses another. */
    public function defaultKey(): string
    {
        $pd = $this->platformVoices()->firstWhere('is_default', true);
        if ($pd) {
            return $pd->voice_key;
        }
        $k = (string) config('training-videos.auto.default_voice', 'bf_emma');

        return isset(config('training-videos.auto.voices', [])[$k]) ? $k : (string) array_key_first((array) config('training-videos.auto.voices', []));
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

    /** What a ChatterboxTtsProvider needs for a CoreX voice key: the engine paths plus the (private) reference clip. */
    public function chatterboxConfig(string $key): array
    {
        $pv = \App\Models\TrainingPlatformVoice::query()->where('voice_key', $key)->firstOrFail();

        return array_merge((array) config('training-videos.auto.tts.chatterbox'), ['key' => $key, 'reference' => \Illuminate\Support\Facades\Storage::disk((string) config('training-videos.auto.disk', 'local'))->path($pv->reference_file)]);
    }

    /** Label for a stored tts_voice ("piper:cori", or an older "piper:en_GB-jenny_dioco-medium"). */
    public function labelFor(?string $stored): string
    {
        $k = preg_replace('/^(piper|kokoro|chatterbox):/', '', (string) $stored);
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
