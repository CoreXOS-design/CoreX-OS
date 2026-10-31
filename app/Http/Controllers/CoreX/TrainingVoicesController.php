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
 */
class TrainingVoicesController extends Controller
{
    public function __construct(private readonly TrainingVoices $voices, private readonly VoiceSamples $samples)
    {
    }

    private function owner(Request $request): void
    {
        abort_unless($request->user() && $request->user()->isOwnerRole(), 403, 'Only the system owner makes CoreX voices.');
    }

    public function index(Request $request)
    {
        $this->owner($request);
        $rows = [];
        foreach ($this->voices->platformVoices() as $pv) {
            $rows[] = ['voice' => $pv, 'sample' => $this->samples->exists($pv->voice_key)];
        }

        return view('corex.training-library.voices', ['rows' => $rows, 'engineReady' => $this->voices->engineInstalled('chatterbox'), 'sampleText' => (string) config('training-videos.auto.sample_text')]);
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
            'is_default' => ! TrainingPlatformVoice::where('is_active', true)->exists(),   // the first CoreX voice becomes the platform default until another is picked
        ]);
        MakeVoiceSampleJob::dispatch($voice->voice_key);

        return redirect()->route('corex.training-library.voices')->with('success', 'The voice "' . $voice->name . '" is saved. Its sample takes a minute or two to make; Play appears when it is ready.');
    }

    public function makeDefault(Request $request, TrainingPlatformVoice $voice)
    {
        $this->owner($request);
        abort_unless($voice->is_active, 404);
        DB::transaction(function () use ($voice) {
            TrainingPlatformVoice::query()->where('is_default', true)->update(['is_default' => false]);
            $voice->forceFill(['is_default' => true])->save();
        });

        return back()->with('success', '"' . $voice->name . '" is now the platform default. Every agency uses it unless its admin chooses another voice.');
    }

    /** Archive (never hard delete): the voice leaves every picker; an agency that had it falls back to the platform default; the reference clip stays on the private disk. */
    public function archive(Request $request, TrainingPlatformVoice $voice)
    {
        $this->owner($request);
        $voice->forceFill(['is_active' => false, 'is_default' => false])->save();
        $voice->delete();

        return back()->with('success', '"' . $voice->name . '" is archived and no longer offered.');
    }
}
