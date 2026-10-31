<?php

namespace App\Services\AdStudio;

use App\Jobs\AdStudio\GenerateVideoAdJob;
use App\Models\User;
use App\Models\VideoAd;
use App\Services\TrainingVideos\Auto\TrainingScriptRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AT-527 - the Ad Studio's one orchestrator (spec .ai/specs/training-videos.md 9.21.3): the editor's starting draft, Generate (check -> freeze -> next "Ad N" ->
 * queue), Duplicate, Try again, and syncing a render's progress back onto the row. All rendering is behind AdRenderGateway.
 */
class VideoAdService
{
    public function __construct(
        private readonly AdScriptRepository $ads,
        private readonly TrainingScriptRepository $training,
        private readonly AdScriptValidator $validator,
        private readonly AdSnapshotBuilder $snapshots,
        private readonly AdRenderGateway $gateway,
    ) {
    }

    /** The editor's starting point for a feature: the script we wrote, question variant a (or b). @return array<string,mixed> */
    public function starterDraft(AdScript $script, string $variant = 'a'): array
    {
        $brand = AdBrand::current();
        $d = $script->defaults();
        $questions = $script->questions();
        $post = $script->post();

        return [
            'feature' => $script->key(),
            'question' => $questions[$variant] ?? (string) reset($questions),
            'shots' => array_map(fn ($s) => ['caption' => (string) $s['caption'], 'step' => $s['step'] ?? null, 'selector' => (string) ($s['selector'] ?? ''), 'action' => (string) ($s['action'] ?? 'highlight'),
                'zoom' => (float) ($s['zoom'] ?? 1.0), 'proof' => (bool) ($s['proof'] ?? false), 'hold' => (float) ($s['hold'] ?? 0)], $script->shots()),
            'cta' => ['join_line' => '', 'cta_line' => ''],
            'length' => (int) ($d['length'] ?? 30),
            'formats' => array_values((array) ($d['formats'] ?? ['9:16', '1:1'])),
            'voice' => $d['voice'] ?? null,
            'post' => ['caption' => (string) ($post['caption'] ?? ''), 'hashtags' => array_values((array) ($post['hashtags'] ?? []))],
        ];
    }

    /** Clean what the editor posted into the draft shape. @param array<string,mixed> $in @return array<string,mixed> */
    public function normalise(array $in): array
    {
        $shots = [];
        foreach (array_values((array) ($in['shots'] ?? [])) as $s) {
            $step = $s['step'] ?? null;
            $shots[] = ['caption' => trim((string) ($s['caption'] ?? '')), 'step' => ($step === '' || $step === null) ? null : (int) $step, 'selector' => trim((string) ($s['selector'] ?? '')),
                'action' => (string) ($s['action'] ?? 'highlight'), 'zoom' => (float) ($s['zoom'] ?? 1.0), 'proof' => ! empty($s['proof']), 'hold' => (float) ($s['hold'] ?? 0)];
        }
        $hashtags = $in['post']['hashtags'] ?? [];

        return [
            'feature' => (string) ($in['feature'] ?? ''),
            'question' => trim((string) ($in['question'] ?? '')),
            'shots' => $shots,
            'cta' => ['join_line' => trim((string) ($in['cta']['join_line'] ?? '')), 'cta_line' => trim((string) ($in['cta']['cta_line'] ?? ''))],
            'length' => (int) ($in['length'] ?? 0),
            'formats' => array_values(array_filter((array) ($in['formats'] ?? []))),
            'voice' => ($in['voice'] ?? '') === '' ? null : (string) $in['voice'],
            'post' => ['caption' => (string) ($in['post']['caption'] ?? ''), 'hashtags' => AdPostText::hashtags(is_array($hashtags) ? $hashtags : (string) $hashtags)],
        ];
    }

    /**
     * Check, freeze, save as the next "Ad N" and queue the render.
     *
     * @param  array<string,mixed>  $draft  a normalised draft
     * @throws ValidationException with the plain-sentence problems
     */
    public function generate(array $draft, User $by): VideoAd
    {
        $result = $this->validator->check($draft);
        if ($result['problems'] !== []) {
            throw ValidationException::withMessages(['draft' => $result['problems']]);
        }
        $script = $this->ads->find($draft['feature']);
        $training = $this->training->find($script->screen());
        $snapshot = $this->snapshots->build($draft, $script, $training, AdBrand::current());

        $ad = $this->saveNext($script->key(), fn (int $n) => [
            'feature_key' => $script->key(), 'ad_number' => $n, 'name' => 'Ad ' . $n, 'snapshot' => $snapshot, 'length_seconds' => $snapshot['length'], 'formats' => $snapshot['formats'],
            'voice_key' => $snapshot['voice'], 'status' => VideoAd::STATUS_QUEUED, 'progress_percent' => 0, 'post_caption' => $snapshot['post']['caption'],
            'post_hashtags' => $snapshot['post']['hashtags'], 'generated_by' => $by->id,
        ]);
        GenerateVideoAdJob::dispatch($ad->id);

        return $ad;
    }

    /** Re-send the SAME frozen request (Try again). */
    public function retry(VideoAd $ad): void
    {
        $ad->forceFill(['status' => VideoAd::STATUS_QUEUED, 'progress_percent' => 0, 'stage' => null, 'failure_reason' => null, 'render_batch_id' => null])->save();
        GenerateVideoAdJob::dispatch($ad->id);
    }

    /** Called by the job: hand the frozen request to the renderer. A failure is recorded on the row, never thrown at the user. */
    public function queueRender(VideoAd $ad): void
    {
        $request = (array) data_get($ad->snapshot, 'request');
        $request['ad_ref'] = $ad->id;
        try {
            $batch = $this->gateway->queue($request);
            $ad->forceFill(['render_batch_id' => $batch, 'status' => VideoAd::STATUS_RENDERING, 'progress_percent' => 1, 'stage' => 'voice', 'failure_reason' => null])->save();
        } catch (\Throwable $e) {
            $ad->forceFill(['status' => VideoAd::STATUS_FAILED, 'progress_percent' => null, 'stage' => null, 'failure_reason' => mb_substr($e->getMessage(), 0, 240)])->save();
        }
    }

    /** Copy the renderer's progress / result onto the row. Cheap; called by the progress endpoint and the pages. */
    public function sync(VideoAd $ad): VideoAd
    {
        if ($ad->status !== VideoAd::STATUS_RENDERING || ! $ad->render_batch_id) {
            return $ad;
        }
        try {
            $s = $this->gateway->status($ad->render_batch_id);
        } catch (\Throwable $e) {
            return $ad;   // try again on the next poll
        }
        if ($s['state'] === 'ready' && $s['files'] !== []) {
            $ad->forceFill(['status' => VideoAd::STATUS_READY, 'progress_percent' => 100, 'stage' => null, 'files' => $s['files'], 'generated_at' => now(), 'failure_reason' => null])->save();
        } elseif ($s['state'] === 'failed') {
            $ad->forceFill(['status' => VideoAd::STATUS_FAILED, 'progress_percent' => null, 'stage' => null, 'failure_reason' => mb_substr((string) ($s['failure'] ?? 'The video could not be made.'), 0, 240)])->save();
        } else {
            $ad->forceFill(['progress_percent' => max(1, min(99, (int) $s['percent'])), 'stage' => $s['stage']])->save();
        }

        return $ad;
    }

    /**
     * Save the next "Ad N" for a feature. Two owners pressing Generate at the same moment would pick the same number: the unique key refuses the second, which
     * simply takes the next number.
     */
    private function saveNext(string $featureKey, callable $attrs): VideoAd
    {
        for ($try = 0; $try < 5; $try++) {
            try {
                return DB::transaction(fn () => VideoAd::create($attrs(VideoAd::nextNumber($featureKey))));
            } catch (QueryException $e) {
                if ($try === 4 || ! str_contains($e->getMessage(), 'Duplicate')) {
                    throw $e;
                }
            }
        }
        throw new \RuntimeException('Could not number the ad.');
    }
}
