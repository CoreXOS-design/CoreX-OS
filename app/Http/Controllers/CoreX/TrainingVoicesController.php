<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Jobs\TrainingVideos\MakeVoiceSampleJob;
use App\Models\TrainingPlatformVoice;
use App\Services\TrainingVideos\Tts\TrainingVoices;
use App\Services\TrainingVideos\Tts\VoiceSamples;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * AT-525 (spec .ai/specs/training-videos.md section 9.20) - the CoreX voices: the SYSTEM OWNER makes a voice with Chatterbox from a consented reference clip, names it, and may
 * pick one as the platform default. Every agency can then choose it in its own picker. The reference clip stays on the private disk; nobody else can see or download it.
 * System-owner only (the existing `owner_only` middleware, and every action aborts unless isOwnerRole()).
 *
 * ONE system voice (spec 9.24): this page is where the system owner picks THE voice every how-to video, narrated-tour clip and ad is spoken in - a CoreX voice made here or a Kokoro
 * one. Agencies do not choose. Changing it queues the regeneration of everything in the background and keeps the old videos playing until each new one is ready.
 */
class TrainingVoicesController extends Controller
{
    public function __construct(private readonly TrainingVoices $voices, private readonly VoiceSamples $samples, private readonly \App\Services\TrainingVideos\Tts\SystemVoiceChange $change)
    {
    }

    private function owner(Request $request): void
    {
        abort_unless($request->user() && $request->user()->isOwnerRole(), 403, 'Only the system owner makes CoreX voices.');
    }

    public function index(Request $request)
    {
        $this->owner($request);
        $platform = [];
        foreach ($this->voices->platformVoices() as $pv) {
            if (! $this->samples->exists($pv->voice_key) && \Illuminate\Support\Facades\Cache::add('tv-sample-queued:' . $pv->voice_key, 1, 3600)) {
                MakeVoiceSampleJob::dispatch($pv->voice_key);   // a CoreX voice's sample takes a minute or two: made in the background, once
            }
            $platform[] = ['voice' => $pv, 'sample' => $this->samples->exists($pv->voice_key), 'current' => $pv->voice_key === $this->voices->systemKey()];
        }
        $kokoro = [];
        foreach ($this->voices->listed() as $key => $v) {
            if ($v['provider'] === 'kokoro') {
                $kokoro[] = $v + ['sample' => $v['installed'] && $this->samples->exists($key), 'current' => $key === $this->voices->systemKey()];
            }
        }

        return view('corex.training-library.voices', ['rows' => $platform, 'kokoro' => $kokoro, 'engineReady' => $this->voices->engineInstalled('chatterbox'), 'sampleText' => (string) config('training-videos.auto.sample_text'),
            'system' => $this->voices->find($this->voices->systemKey()), 'progress' => $this->change->progress()]);
    }

    /** One voice's sample (the same two sentences for every voice). A CoreX voice's sample takes a minute or two and is made in the background (MakeVoiceSampleJob), never here. */
    public function sample(Request $request, string $key)
    {
        $this->owner($request);
        $v = $this->voices->find($key);
        abort_unless($v, 404);
        abort_unless($this->samples->exists($key) || $v['provider'] !== 'chatterbox', 404);
        $path = $this->samples->ensure($key);
        abort_unless($path, 404);

        return response()->file(Storage::disk((string) config('training-videos.auto.disk', 'local'))->path($path), ['Content-Type' => 'audio/mpeg', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function store(Request $request)
    {
        $this->owner($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'about' => ['nullable', 'string', 'max:150'],
            'consent' => ['accepted'],
            'reference' => ['required', 'file', 'max:15360', 'mimes:wav,mp3,m4a,aac,ogg,oga,webm,weba,mp4,flac'],
        ], ['consent.accepted' => 'Tick the box to confirm the person in the recording agreed to their voice being used.']);

        $dir = rtrim((string) config('training-videos.auto.work_dir'), '/') . '/voice-ref-' . uniqid();
        File::ensureDirectoryExists($dir);
        try {
            $in = $dir . '/in.' . ($request->file('reference')->getClientOriginalExtension() ?: 'bin');
            $request->file('reference')->move($dir, basename($in));
            $out = $dir . '/ref.wav';
            // one clean mono 24 kHz clip, at most 20 s: all the engine needs, and nothing else of the upload is kept
            $p = new Process(['ffmpeg', '-y', '-v', 'error', '-i', $in, '-vn', '-ac', '1', '-ar', '24000', '-t', '20', $out]);
            $p->setTimeout(60);
            $p->run();
            if (! $p->isSuccessful() || ! is_file($out)) {
                return back()->withInput($request->except('reference'))->withErrors(['reference' => 'That recording could not be read. Try a wav, mp3 or m4a file, or record again.']);
            }
            $seconds = round((float) trim((string) shell_exec('ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($out))), 1);
            if ($seconds < 5) {
                return back()->withInput($request->except('reference'))->withErrors(['reference' => 'The recording is only ' . $seconds . ' seconds. Record 5 to 20 seconds of clear speech, with no background noise.']);
            }
            $file = rtrim((string) config('training-videos.auto.folder'), '/') . '/voice-refs/' . Str::uuid() . '.wav';
            Storage::disk((string) config('training-videos.auto.disk', 'local'))->put($file, (string) file_get_contents($out));
        } finally {
            File::deleteDirectory($dir);
        }

        $base = 'corex_' . (Str::slug($data['name'], '_') ?: 'voice');
        $key = $base;
        for ($i = 2; TrainingPlatformVoice::withTrashed()->where('voice_key', $key)->exists(); $i++) {
            $key = $base . '_' . $i;
        }
        $voice = TrainingPlatformVoice::create([
            'voice_key' => $key, 'name' => $data['name'], 'about' => $data['about'] ?? null, 'reference_file' => $file, 'reference_seconds' => $seconds,
            'consent_confirmed_at' => now(), 'consent_by_user_id' => $request->user()->id, 'created_by_user_id' => $request->user()->id,
        ]);
        MakeVoiceSampleJob::dispatch($voice->voice_key);

        return redirect()->route('corex.training-library.voices')->with('success', 'The voice "' . $voice->name . '" is saved. Its sample takes a minute or two to make; Play appears when it is ready. To speak every video in it, press Use as the system voice.');
    }

    /** "Use as the system voice": a CoreX voice or a Kokoro one. Queues the regeneration of everything in the background (nice 19); the old videos keep playing until each new one is ready. */
    public function useAsSystem(Request $request)
    {
        $this->owner($request);
        $data = $request->validate(['voice' => ['required', 'string', 'max:60']]);
        $v = $this->voices->find($data['voice']);
        abort_unless($v && $v['listed'] && $v['installed'], 404);
        if ($data['voice'] === $this->voices->systemKey()) {
            return back()->with('success', $v['label'] . ' is already the system voice.');
        }
        $n = $this->change->change($data['voice'], $request->user()->id);

        return back()->with('success', $v['label'] . ' is now the system voice. ' . ($n > 0
            ? $n . ' video' . ($n === 1 ? ' is' : 's are') . ' being made again in it, in the background; the current videos keep playing until each new one is ready.'
            : 'Every video already exists in this voice.'));
    }

    /** Archive (never hard delete): the voice leaves every picker; an agency that had it falls back to the platform default; the reference clip stays on the private disk. */
    public function archive(Request $request, TrainingPlatformVoice $voice)
    {
        $this->owner($request);
        if ($voice->voice_key === $this->voices->systemKey()) {
            return back()->withErrors(['voice' => 'This is the system voice. Pick another voice as the system voice first, then archive this one.']);
        }
        $voice->forceFill(['is_active' => false, 'is_default' => false])->save();
        $voice->delete();

        return back()->with('success', '"' . $voice->name . '" is archived and no longer offered.');
    }
}
