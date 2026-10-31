<?php

namespace App\Services\TrainingVideos\Ads;

use App\Jobs\TrainingVideos\RenderAdJob;
use App\Models\AdRender;
use App\Services\TrainingVideos\Tts\TrainingVoices;
use Illuminate\Support\Str;

/**
 * AT-525 / AT-527 (spec .ai/specs/training-videos.md 9.16.1 / 9.16.2) - the door Ad Studio calls: queue() takes an ad request and returns a batch id; status() says how it is going and
 * hands back the ready files. The same ad (script, voice, brand, steps, formats) is never rendered twice: a batch that already has every requested format ready is returned as it is.
 * Audio is made once per script text + voice (the voice engines cache by hash); nothing here is per agency - these are CoreX's own ads.
 */
class AdRenderer
{
    public const ENGINE = 'ad-render-1';

    public function __construct(private readonly AdRequestValidator $validator, private readonly TrainingVoices $voices)
    {
    }

    /** @param array<string,mixed> $request @throws AdRequestInvalid with the plain-sentence problems */
    public function queue(array $request): string
    {
        $problems = $this->validator->problems($request);
        if ($problems !== []) {
            throw new AdRequestInvalid($problems);
        }
        $voice = ($request['voice'] ?? null) && $this->voices->find((string) $request['voice']) ? (string) $request['voice'] : $this->voices->defaultKey();
        $hash = $this->hash($request, $voice);
        $formats = array_values((array) $request['formats']);

        // the same ad, already made: hand it back
        $done = AdRender::query()->where('source_hash', $hash)->where('status', 'ready')->whereIn('format', $formats)->get()->groupBy('batch_id')->filter(fn ($g) => $g->count() === count($formats))->keys()->first();
        if ($done) {
            return (string) $done;
        }
        $batch = (string) Str::uuid();
        foreach ($formats as $f) {
            AdRender::create(['batch_id' => $batch, 'ad_ref' => $request['ad_ref'] ?? null, 'format' => $f, 'status' => 'queued', 'stage' => 'Waiting', 'voice_key' => $voice, 'source_hash' => $hash]);
        }
        RenderAdJob::dispatch($batch, $request, $voice);

        return $batch;
    }

    /** @return array{batch_id:string,ad_ref:?int,status:string,percent:int,stage:?string,failure_reason:?string,files:array<string,array<string,mixed>>} status: queued | rendering | ready | failed */
    public function status(string $batchId): array
    {
        $rows = AdRender::query()->where('batch_id', $batchId)->get();
        if ($rows->isEmpty()) {
            return ['batch_id' => $batchId, 'ad_ref' => null, 'status' => 'failed', 'percent' => 0, 'stage' => null, 'failure_reason' => 'No such render batch.', 'files' => []];
        }
        $status = $rows->contains('status', 'failed') ? 'failed' : ($rows->every(fn ($r) => $r->status === 'ready') ? 'ready' : ($rows->contains('status', 'rendering') ? 'rendering' : 'queued'));
        $files = [];
        foreach ($rows->where('status', 'ready') as $r) {
            $files[$r->format] = ['mp4' => $r->file_name, 'poster' => $r->poster_name, 'seconds' => $r->duration_seconds, 'width' => $r->width, 'height' => $r->height, 'size' => $r->size_bytes];
        }

        return ['batch_id' => $batchId, 'ad_ref' => $rows->first()->ad_ref ? (int) $rows->first()->ad_ref : null, 'status' => $status, 'percent' => (int) round($rows->avg('progress_percent')),
            'stage' => $rows->first()->stage, 'failure_reason' => $rows->firstWhere('status', 'failed')?->failure_reason, 'files' => $files];
    }

    /** What decides "the same ad": the words and shots, the training steps they point at (their selectors), the voice, the brand, the formats, the length, the music, the engine. */
    public function hash(array $request, string $voice): string
    {
        $repo = app(\App\Services\TrainingVideos\Auto\TrainingScriptRepository::class);
        $steps = [];
        foreach ((array) ($request['script']['beats'] ?? []) as $b) {
            $sc = (array) ($b['screen'] ?? []);
            if (isset($sc['script_key']) && ($s = $repo->find((string) $sc['script_key']))) {
                $steps[] = [$s->key(), $s->steps()[(int) ($sc['step'] ?? 0)] ?? null, $s->url()];
            }
        }
        $r = $request;
        unset($r['ad_ref']);   // the same ad under another id is still the same ad

        return sha1(json_encode([self::ENGINE, $r, $voice, $steps]));
    }
}
