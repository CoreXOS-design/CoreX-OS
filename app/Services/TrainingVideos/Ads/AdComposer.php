<?php

namespace App\Services\TrainingVideos\Ads;

use App\Services\TrainingVideos\Auto\TrainingScriptRepository;
use App\Services\TrainingVideos\Auto\TrainingSessionService;
use App\Services\TrainingVideos\Auto\VideoAssembler;
use App\Services\TrainingVideos\Tts\BatchTtsProvider;
use App\Services\TrainingVideos\Tts\TtsManager;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * AT-525 / AT-527 (spec 9.16.1 / 9.16.2) - makes the files of ONE ad: speaks the hook, every shot, the brand line and the call to action in the chosen voice; records the shots once
 * at 1920x1080 as a user of the training agency only (invented data); then, per format, cuts each shot from the recording, crops and pushes in on its target rectangle, joins the
 * cards, burns the captions inside the safe zone and mixes the voice (and an approved music track, when there is one). Output: an mp4 and a poster jpg per format.
 */
class AdComposer
{
    public const FPS = 25;

    public function __construct(
        private readonly TtsManager $tts,
        private readonly TrainingScriptRepository $scripts,
        private readonly TrainingSessionService $sessions,
        private readonly AdCards $cards,
        private readonly AdCaptions $captions,
        private readonly VideoAssembler $assembler,
    ) {
    }

    /**
     * @param  array<string,mixed>  $r  the (validated) ad request
     * @param  callable(string,int):void  $progress  (stage, percent)
     * @param  callable|null  $recorder  tests: replaces the headless recorder (job array -> timeline array)
     * @return array<string,array{mp4:string,poster:string,seconds:float,width:int,height:int,size:int}> by format, absolute paths inside the work dir (the caller moves them)
     */
    public function render(array $r, string $voiceKey, string $work, callable $progress, ?callable $recorder = null): array
    {
        File::ensureDirectoryExists($work);
        $script = $r['script'];
        $brand = (array) $r['brand'];
        $beats = array_values((array) $script['beats']);
        $formats = array_values((array) $r['formats']);

        // 1. speak every line (one engine run for engines with a heavy start-up)
        $progress('Speaking the lines', 8);
        $lines = ['hook' => (string) $script['hook']['text']];
        foreach ($beats as $i => $b) {
            $lines['beat' . $i] = (string) $b['benefit'];
        }
        $proof = trim((string) ($script['proof']['text'] ?? ''));
        if ($proof !== '') {
            $lines['proof'] = $proof;
        }
        $cta = (array) ($script['cta'] ?? []);
        $sub = trim((string) ($cta['sub'] ?? ''));
        $lines['end'] = trim((string) ($cta['line'] ?? 'Join CoreX')) . '.' . (str_word_count($sub) > 0 && str_word_count($sub) <= 10 ? ' ' . $sub : '');
        $voice = $this->tts->provider($voiceKey);
        $clips = [];
        $jobs = [];
        foreach ($lines as $k => $text) {
            $jobs[$k] = ['text' => $text, 'out' => $work . '/say-' . $k . '.wav'];
        }
        if ($voice instanceof BatchTtsProvider) {
            $secs = $voice->synthesizeMany(array_values($jobs));
            foreach (array_keys($jobs) as $n => $k) {
                $clips[$k] = ['file' => $jobs[$k]['out'], 'seconds' => $secs[$n]];
            }
        } else {
            foreach ($jobs as $k => $j) {
                $clips[$k] = ['file' => $j['out'], 'seconds' => $voice->synthesize($j['text'], $j['out'])];
            }
        }

        // 2. the composed timeline (seconds): hook, shots, brand line, end card
        $segs = [];
        $segs[] = ['type' => 'card', 'id' => 'hook', 'dur' => max((float) ($script['hook']['seconds'] ?? 3.0), $clips['hook']['seconds'] + 0.6), 'say' => 'hook', 'say_at' => 0.2];
        foreach ($beats as $i => $b) {
            $segs[] = ['type' => 'beat', 'i' => $i, 'dur' => max((float) $b['seconds'], $clips['beat' . $i]['seconds'] + 0.4), 'say' => 'beat' . $i, 'say_at' => 0.2, 'caption' => (string) $b['benefit'], 'zoom' => (float) ($b['zoom']['scale'] ?? 1.0)];
        }
        if ($proof !== '') {
            $segs[] = ['type' => 'card', 'id' => 'proof', 'dur' => max((float) ($script['proof']['seconds'] ?? 2.5), $clips['proof']['seconds'] + 0.5), 'say' => 'proof', 'say_at' => 0.2];
        }
        $segs[] = ['type' => 'card', 'id' => 'end', 'dur' => max(3.5, min(4.0, $clips['end']['seconds'] + 0.7)), 'say' => 'end', 'say_at' => 0.2];
        $t = 0.0;
        foreach ($segs as &$s) {
            $s['start'] = $t;
            $t += $s['dur'];
        }
        unset($s);
        $total = $t;
        $len = (int) $r['length'];
        if ($total > $len * 1.15) {
            throw new \RuntimeException('The ad came to ' . round($total) . ' seconds, over the ' . $len . ' seconds chosen. Remove a shot or shorten the words and try again.');
        }

        // 3. record the shots once, at 1920x1080, in the training agency only
        $progress('Filming the screen', 20);
        $timeline = $this->record($beats, $segs, $work, $recorder);
        $raw = $work . '/raw.mp4';
        $rawSeconds = $this->assembler->probe($raw)['duration'];
        $scale = ($rawSeconds > 0 && ($timeline['total'] ?? 0) > 0) ? $rawSeconds / (float) $timeline['total'] : 1.0;

        // 4. the cards, at every format's size
        $progress('Drawing the cards', 50);
        $brand['logo_file'] = $this->logoFile($brand);
        $cardDefs = [['id' => 'hook', 'html' => $this->cards->hookHtml($brand, (string) $script['hook']['text'], $script['hook']['accent'] ?? null)]];
        if ($proof !== '') {
            $cardDefs[] = ['id' => 'proof', 'html' => $this->cards->lineHtml($brand, $proof)];
        }
        $cardDefs[] = ['id' => 'end', 'html' => $this->cards->endHtml($brand, $cta)];
        $pngs = $this->cards->render($cardDefs, $formats, $work . '/cards');

        // 5. the voice track (once), music under it when an approved track is configured
        $progress('Mixing the voice', 58);
        $audio = $this->mixAudio($segs, $clips, $total, $work, $r['music'] ?? null);

        // 6. one mp4 + poster per format
        $out = [];
        foreach ($formats as $n => $f) {
            $progress('Making the ' . $f . ' video', 62 + (int) round(35 * $n / max(1, count($formats))));
            $out[$f] = $this->composeFormat($f, $segs, $timeline, $scale, $raw, $pngs[$f], $audio, $total, $work);
        }
        $progress('Done', 98);

        return $out;
    }

    /** Runs the recorder for the shots and returns its timeline (with each shot's target rectangle and when its action had settled). */
    private function record(array $beats, array $segs, string $work, ?callable $recorder): array
    {
        $steps = [];
        $loginAs = 'agent';
        $firstUrl = null;
        $durs = array_column(array_filter($segs, fn ($s) => $s['type'] === 'beat'), 'dur');
        foreach ($beats as $i => $b) {
            $sc = (array) $b['screen'];
            if (isset($sc['script_key'])) {
                $ts = $this->scripts->find((string) $sc['script_key']);
                $st = (array) $ts->steps()[(int) $sc['step']];
                $url = $ts->url();
                $loginAs = $i === 0 ? $ts->loginAs() : $loginAs;
                $steps[] = ['selector' => $st['selector'] ?? null, 'action' => $st['action'] ?? 'highlight', 'value' => $st['value'] ?? null, 'wait_for' => $st['wait_for'] ?? null,
                    'url' => $url, 'rect_selector' => $b['zoom']['selector'] ?? ($st['selector'] ?? null), 'seconds' => round($durs[$i] + 3.0, 2)];
            } else {
                $url = (string) ($sc['url'] ?? '');
                $steps[] = ['selector' => $sc['selector'], 'action' => $sc['action'] ?? 'highlight', 'value' => $sc['value'] ?? null, 'wait_for' => $sc['wait_for'] ?? null,
                    'url' => $url, 'rect_selector' => $b['zoom']['selector'] ?? $sc['selector'], 'seconds' => round($durs[$i] + 3.0, 2)];
            }
            $firstUrl ??= $url;
        }
        $user = $this->sessions->userFor($loginAs);
        $session = $this->sessions->issue($user);
        try {
            $job = [
                'ad' => true, 'baseUrl' => (string) (config('training-videos.auto.recorder.base_url') ?: config('app.url')), 'url' => $firstUrl,
                'cookie' => ['name' => $session['name'], 'value' => $session['value']], 'chromium' => config('training-videos.auto.recorder.chromium'),
                'viewport' => ['width' => 1920, 'height' => 1080], 'out' => $work . '/raw.mp4', 'timeline' => $work . '/timeline.json', 'intro' => null, 'outro' => null, 'steps' => $steps, 'probe' => false,
            ];

            return $recorder ? $recorder($job) : $this->runRecorder($job);
        } finally {
            $this->sessions->revoke($session['session_id']);
        }
    }

    private function runRecorder(array $job): array
    {
        file_put_contents(dirname($job['out']) . '/job.json', json_encode($job));
        $p = new Process([(string) config('training-videos.auto.recorder.node', 'node'), (string) config('training-videos.auto.recorder.script'), dirname($job['out']) . '/job.json'], base_path());
        $p->setTimeout((float) config('training-videos.auto.recorder.timeout', 600));
        $p->run();
        if (! $p->isSuccessful() || ! is_file($job['timeline'])) {
            $why = $p->getExitCode() === 3 ? 'the training user could not sign in' : trim(substr($p->getErrorOutput() . ' ' . $p->getOutput(), -300));
            throw new \RuntimeException('The screen could not be filmed: ' . $why);
        }
        $t = json_decode((string) file_get_contents($job['timeline']), true);
        $missing = array_filter($t['steps'] ?? [], fn ($s) => ! ($s['found'] ?? true));
        if ($missing) {
            throw new \RuntimeException('The screen no longer matches: shot(s) ' . implode(', ', array_map(fn ($s) => $s['i'] + 1, $missing)) . ' could not find their element on the screen. Pick another screen step.');
        }

        return $t;
    }

    /** The logo image to draw: the brand's own file, unless it is CoreX's default wordmark SVG (which is drawn as text so it reads on a dark card). */
    private function logoFile(array $brand): ?string
    {
        $logo = (string) ($brand['logo'] ?? '');
        if ($logo === '' || basename($logo) === 'corex-os-logo.svg') {
            return null;
        }
        $abs = str_starts_with($logo, '/') ? $logo : public_path($logo);

        return is_file($abs) ? $abs : null;
    }

    private function mixAudio(array $segs, array $clips, float $total, string $work, ?string $musicId): string
    {
        $args = ['ffmpeg', '-y', '-v', 'error'];
        $filters = [];
        $n = 0;
        foreach ($segs as $s) {
            $c = $clips[$s['say']];
            $args[] = '-i';
            $args[] = $c['file'];
            $ms = (int) round(($s['start'] + $s['say_at']) * 1000);
            $filters[] = "[{$n}:a]adelay={$ms}|{$ms}[a{$n}]";
            $n++;
        }
        $mix = implode('', array_map(fn ($i) => "[a{$i}]", range(0, $n - 1)));
        $music = $this->musicFile($musicId);
        if ($music) {
            $args[] = '-stream_loop';
            $args[] = '-1';
            $args[] = '-i';
            $args[] = $music;
            $filters[] = $mix . 'amix=inputs=' . $n . ':normalize=0:duration=longest[voice]';
            $filters[] = "[{$n}:a]volume=0.12,afade=t=out:st=" . max(0, round($total - 1.2, 2)) . ':d=1.2[bed]';
            $filters[] = '[voice][bed]amix=inputs=2:normalize=0:duration=first[aout]';
        } else {
            $filters[] = $n > 1 ? $mix . 'amix=inputs=' . $n . ':normalize=0:duration=longest[aout]' : '[a0]anull[aout]';
        }
        $wav = $work . '/audio.wav';
        array_push($args, '-filter_complex', implode(';', $filters), '-map', '[aout]', '-t', (string) round($total, 2), '-ar', '48000', '-ac', '2', $wav);
        $this->run($args, 'mix the voice');

        return $wav;
    }

    /** An approved music track on the data volume (spec 9.16 / 9.21.6), or null. No track is used unless one is configured AND its file exists. */
    private function musicFile(?string $id): ?string
    {
        if (! $id) {
            return null;
        }
        $f = rtrim((string) config('training-videos.ads.music_dir'), '/') . '/' . basename($id);

        return is_file($f) ? $f : null;
    }

    private function composeFormat(string $f, array $segs, array $timeline, float $scale, string $raw, array $cards, string $audio, float $total, string $work): array
    {
        $shape = AdFormat::get($f);
        $W = $shape['w'];
        $H = $shape['h'];
        $steps = $timeline['steps'];
        $args = ['ffmpeg', '-y', '-v', 'error', '-i', $raw];
        $filters = [];
        $labels = [];
        $cues = [];
        $inIdx = 1;
        foreach ($segs as $k => $s) {
            $d = round($s['dur'], 3);
            if ($s['type'] === 'card') {
                array_push($args, '-loop', '1', '-framerate', (string) self::FPS, '-t', (string) $d, '-i', $cards[$s['id']]);
                $filters[] = "[{$inIdx}:v]scale={$W}:{$H},setsar=1,fps=" . self::FPS . ",format=yuv420p,trim=duration={$d},setpts=PTS-STARTPTS[v{$k}]";
                $inIdx++;
            } else {
                $st = $steps[$s['i']];
                $rs = max(0.0, ($st['start'] + ($st['settled'] ?? 0.8)) * $scale);
                $win = AdFormat::window($f, max(1.0, (float) $s['zoom']));
                $rect = $st['rect'] ?? null;
                $cx = $rect ? $rect['x'] + $rect['w'] / 2 : 960;
                $cy = $rect ? $rect['y'] + $rect['h'] / 2 : 540;
                $x = (int) max(0, min(1920 - $win['w'], round($cx - $win['w'] / 2)));
                $y = (int) max(0, min(1080 - $win['h'], round($cy - $win['h'] / 2)));
                $grow = 0.05;
                $filters[] = "[0:v]trim=start=" . round($rs, 3) . ":duration={$d},setpts=PTS-STARTPTS,crop={$win['w']}:{$win['h']}:{$x}:{$y},"
                    . "scale=w='{$W}*(1+{$grow}*t/{$d})':h='{$H}*(1+{$grow}*t/{$d})':eval=frame:flags=bilinear,crop={$W}:{$H},setsar=1,fps=" . self::FPS . ",format=yuv420p[v{$k}]";
                $cues[] = ['start' => $s['start'] + 0.15, 'end' => $s['start'] + $s['dur'] - 0.1, 'text' => $s['caption']];
            }
            $labels[] = "[v{$k}]";
        }
        $ass = $work . '/captions-' . $shape['key'] . '.ass';
        file_put_contents($ass, $this->captions->ass($cues, $shape));
        $esc = fn (string $v) => str_replace(["\\", ':', "'"], ["\\\\", '\\:', "\\'"], $v);
        $filters[] = implode('', $labels) . 'concat=n=' . count($labels) . ':v=1:a=0[vcat]';
        $filters[] = "[vcat]ass=filename='" . $esc($ass) . "':fontsdir='" . $esc(base_path('resources/fonts/inter')) . "'[vout]";
        array_push($args, '-i', $audio);
        $mp4 = $work . '/ad-' . $shape['key'] . '.mp4';
        array_push($args, '-filter_complex', implode(';', $filters), '-map', '[vout]', '-map', $inIdx . ':a', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '21', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', '-t', (string) round($total, 2), $mp4);
        $this->run($args, 'make the ' . $f . ' video');

        $poster = $work . '/ad-' . $shape['key'] . '.jpg';
        $first = collect($segs)->firstWhere('type', 'beat');
        $at = $first ? $first['start'] + $first['dur'] * 0.55 : 1.0;
        $this->run(['ffmpeg', '-y', '-v', 'error', '-ss', (string) round($at, 2), '-i', $mp4, '-frames:v', '1', '-q:v', '3', $poster], 'make the poster');

        return ['mp4' => $mp4, 'poster' => $poster, 'seconds' => round($total, 1), 'width' => $W, 'height' => $H, 'size' => (int) filesize($mp4)];
    }

    protected function run(array $cmd, string $what): void
    {
        $p = new Process($cmd);
        $p->setTimeout(900);
        $p->run();
        if (! $p->isSuccessful()) {
            throw new \RuntimeException("Could not {$what}: " . trim(substr($p->getErrorOutput(), -400)));
        }
    }
}
