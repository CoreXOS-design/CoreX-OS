<?php

namespace App\Services\TrainingVideos\Auto;

use App\Models\TrainingVideo;
use App\Services\TrainingVideos\Tts\TtsManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * AT-525 (spec section 9.5) - the whole pipeline for ONE screen script: validate -> speak every line and measure it -> sign in as the training user -> record
 * (scripts/training-video-record.mjs, each step held as long as its voice clip) -> assemble (VideoAssembler) -> store -> TrainingVideo row. A failure at any
 * stage leaves the PREVIOUS video playing and writes the reason on the row; nothing half-made is ever shown.
 */
class TrainingVideoGenerator
{
    public function __construct(
        private readonly TrainingScriptValidator $validator,
        private readonly TtsManager $tts,
        private readonly TrainingSessionService $sessions,
        private readonly VideoAssembler $assembler,
        private readonly SourceHasher $hasher,
    ) {
    }

    /**
     * @param  callable|null  $recorder  fn (array $job): array — runs the recording and returns its timeline (tests swap this for a fake; default = the node recorder)
     */
    public function generate(TrainingScript $script, ?int $byUserId = null, ?callable $recorder = null): TrainingVideo
    {
        $problems = $this->validator->problems($script);
        if ($problems !== []) {
            throw new \InvalidArgumentException("The script '{$script->key()}' cannot be recorded yet:\n - " . implode("\n - ", $problems));
        }

        $video = TrainingVideo::withTrashed()->where('source', TrainingVideo::SOURCE_GENERATED)->where('screen_key', $script->key())->first()
            ?? TrainingVideo::create(['source' => TrainingVideo::SOURCE_GENERATED, 'screen_key' => $script->key(), 'title' => $script->title(), 'status' => TrainingVideo::STATUS_PROCESSING, 'generated_by' => $byUserId]);
        $hadFile = $video->file_name !== null;
        $video->forceFill(['processing_started_at' => now(), 'failure_reason' => null, 'progress_percent' => 0, 'generated_by' => $byUserId ?? $video->generated_by]);
        if (! $hadFile) {
            $video->status = TrainingVideo::STATUS_PROCESSING;
        }
        $video->save();

        $work = rtrim((string) config('training-videos.auto.work_dir'), '/') . '/' . $script->key() . '-' . now()->format('YmdHis');
        File::ensureDirectoryExists($work);
        $session = null;
        try {
            $voice = $this->tts->provider();
            $user = $this->sessions->userFor($script->loginAs());
            $pad = (float) config('training-videos.auto.step_pad_seconds', 0.6);

            // 1. speak every line and measure it
            $clips = [];
            foreach ($script->spokenLines() as $n => $line) {
                $wav = $work . '/line-' . $n . '.wav';
                $clips[] = ['file' => $wav, 'text' => $line, 'seconds' => $voice->synthesize($line, $wav)];
            }
            $video->update(['progress_percent' => 25]);

            // 2. the recorder's job: each step held as long as its clip + padding (sync by construction)
            $introClip = $script->intro() !== '' ? array_shift($clips) : null;
            $outroClip = $script->outro() !== '' ? array_pop($clips) : null;
            $steps = [];
            foreach ($script->steps() as $i => $s) {
                $steps[] = ['selector' => $s['selector'] ?? null, 'action' => $s['action'], 'value' => $s['value'] ?? null,
                    'seconds' => round($clips[$i]['seconds'] + $pad + 0.8 + (float) ($s['hold'] ?? 0), 2)];
            }
            $session = $this->sessions->issue($user);
            $job = [
                'baseUrl' => (string) (config('training-videos.auto.recorder.base_url') ?: config('app.url')), 'url' => $script->url(),
                'cookie' => ['name' => $session['name'], 'value' => $session['value']], 'chromium' => config('training-videos.auto.recorder.chromium'),
                'viewport' => ['width' => (int) config('training-videos.auto.recorder.width', 1280), 'height' => (int) config('training-videos.auto.recorder.height', 720)],
                'out' => $work . '/raw.mp4', 'timeline' => $work . '/timeline.json',
                'intro' => $introClip ? ['title' => $script->title(), 'subtitle' => 'A CoreX how-to', 'seconds' => round(max((float) config('training-videos.auto.intro_seconds', 3), $introClip['seconds'] + 1.0), 2)] : null,
                'outro' => $outroClip ? ['title' => 'That is it', 'subtitle' => $this->relatedLine($script), 'seconds' => round(max((float) config('training-videos.auto.outro_seconds', 4), $outroClip['seconds'] + 1.0), 2)] : null,
                'steps' => $steps, 'probe' => false,
            ];
            $timeline = $recorder ? $recorder($job) : $this->runRecorder($job);
            $video->update(['progress_percent' => 70]);

            // 3. place every voice clip at the moment its step started
            $cues = [];
            if ($introClip && ! empty($timeline['intro'])) {
                $cues[] = ['file' => $introClip['file'], 'start' => $timeline['intro']['start'] + 0.3, 'end' => $timeline['intro']['start'] + 0.3 + $introClip['seconds'], 'text' => $introClip['text']];
            }
            foreach ($script->steps() as $i => $s) {
                $t = $timeline['steps'][$i] ?? null;
                if ($t) {
                    $start = $t['start'] + 0.6;   // the picture settles, then the voice
                    $cues[] = ['file' => $clips[$i]['file'], 'start' => $start, 'end' => $start + $clips[$i]['seconds'], 'text' => $clips[$i]['text']];
                }
            }
            if ($outroClip && ! empty($timeline['outro'])) {
                $cues[] = ['file' => $outroClip['file'], 'start' => $timeline['outro']['start'] + 0.3, 'end' => $timeline['outro']['start'] + 0.3 + $outroClip['seconds'], 'text' => $outroClip['text']];
            }
            $base = $work . '/final';
            $made = $this->assembler->assemble($job['out'], $cues, $base);

            // 4. store (private disk) and switch the row over; the old files go only now
            $fileName = 'gen-' . Str::limit($script->key(), 40, '') . '-' . Str::lower(Str::random(8));
            $disk = Storage::disk((string) config('training-videos.auto.disk', 'local'));
            $folder = (string) config('training-videos.auto.folder', 'training-videos-auto');
            $disk->putFileAs($folder, new \Illuminate\Http\File($made['mp4']), $fileName . '.mp4');
            $disk->putFileAs($folder, new \Illuminate\Http\File($made['poster']), $fileName . '.jpg');
            $disk->putFileAs($folder, new \Illuminate\Http\File($made['vtt']), $fileName . '.vtt');
            $old = $video->file_name;
            $chapters = [];
            foreach ($script->steps() as $i => $s) {
                if (isset($timeline['steps'][$i])) {
                    $chapters[] = ['at' => (int) round($timeline['steps'][$i]['start']), 'title' => mb_substr((string) $s['narration'], 0, 80)];
                }
            }
            $video->forceFill([
                'title' => $script->title(), 'status' => TrainingVideo::STATUS_READY, 'failure_reason' => null, 'file_name' => $fileName, 'captions_file' => $fileName . '.vtt',
                'duration_seconds' => (int) round($made['duration']), 'width' => $made['width'], 'height' => $made['height'], 'size_bytes' => $made['size'],
                'source_hash' => $this->hasher->hash($script), 'generated_at' => now(), 'tts_voice' => $voice->name(), 'chapters' => $chapters,
                'processing_started_at' => null, 'progress_percent' => 100,
            ])->save();
            if ($old) {
                foreach (['mp4', 'jpg', 'vtt'] as $ext) {
                    $disk->delete($folder . '/' . $old . '.' . $ext);
                }
            }
        } catch (\Throwable $e) {
            $video->forceFill(['failure_reason' => mb_substr($e->getMessage(), 0, 240), 'processing_started_at' => null, 'progress_percent' => null]);
            if (! $hadFile) {
                $video->status = TrainingVideo::STATUS_FAILED;
            }
            $video->save();
            throw $e;
        } finally {
            if ($session) {
                $this->sessions->revoke($session['session_id']);
            }
            File::deleteDirectory($work);
        }

        return $video->fresh();
    }

    private function relatedLine(TrainingScript $s): string
    {
        $r = $s->related();

        return $r ? 'Watch next: ' . implode(', ', array_map(fn ($k) => str_replace('-', ' ', $k), $r)) : 'More how-to videos are in the Training library.';
    }

    /** @param array<string,mixed> $job @return array<string,mixed> the recorder's timeline */
    private function runRecorder(array $job): array
    {
        $jobFile = dirname($job['out']) . '/job.json';
        file_put_contents($jobFile, json_encode($job));
        $p = new Process([(string) config('training-videos.auto.recorder.node', 'node'), (string) config('training-videos.auto.recorder.script'), $jobFile], base_path());
        $p->setTimeout((float) config('training-videos.auto.recorder.timeout', 600));
        $p->run();
        if (! $p->isSuccessful() || ! is_file($job['timeline'])) {
            $why = $p->getExitCode() === 3 ? 'the training user could not sign in' : trim(substr($p->getErrorOutput() . ' ' . $p->getOutput(), -300));
            throw new \RuntimeException('The recorder failed: ' . $why);
        }
        $t = json_decode((string) file_get_contents($job['timeline']), true);
        $missing = array_filter($t['steps'] ?? [], fn ($s) => ! ($s['found'] ?? true));
        if ($missing) {
            throw new \RuntimeException('The screen no longer matches the script: step(s) ' . implode(', ', array_map(fn ($s) => $s['i'] + 1, $missing)) . ' could not find their element. Update the script (or the tour anchor) and regenerate.');
        }

        return $t;
    }
}
