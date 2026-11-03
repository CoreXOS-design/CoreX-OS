<?php

namespace App\Console\Commands;

use App\Models\AdRender;
use App\Services\TrainingVideos\Ads\AdRenderer;
use App\Services\TrainingVideos\Ads\AdRequestInvalid;
use Illuminate\Console\Command;

/** AT-525 / AT-527 - render a SAMPLE ad of a training script (its first steps, invented training data only) straight through the render door: for checking the renderer without Ad Studio. */
class TrainingVideosAdSample extends Command
{
    protected $signature = 'training-videos:ad-sample {screen=mic-work : the training script to film} {--length=30} {--formats=9:16,1:1,16:9} {--voice=} {--sync : render now instead of queueing} {--demo-badge : add a sub-line and an Example circle (the look of spec 9.16.5)}';
    protected $description = 'Render a sample video ad of a training script through AdRenderer (spec 9.16.1).';

    public function handle(AdRenderer $renderer): int
    {
        $screen = (string) $this->argument('screen');
        $script = app(\App\Services\TrainingVideos\Auto\TrainingScriptRepository::class)->find($screen);
        if (! $script) {
            $this->error("No training script '{$screen}'.");

            return self::FAILURE;
        }
        $copy = [
            'mic-work' => ['Still guessing which owners to phone?', 'guessing', [[0, 'See who to phone first today'], [1, 'One click shows everything we know'], [2, 'Claim it before a colleague does'], [3, 'Hear about price drops early']]],
            'book-a-viewing' => ['Still losing buyers after viewings?', 'losing', [[0, 'Every viewing in one diary'], [2, 'Name it in seconds'], [5, 'Add the buyer once'], [6, 'Never miss a viewing again']]],
        ][$screen] ?? ['Still doing this the slow way?', 'slow', [[0, 'See it all in one place'], [1, 'Done in a few clicks']]];
        $beats = [];
        foreach ($copy[2] as [$step, $benefit]) {
            $beats[] = ['benefit' => $benefit, 'screen' => ['script_key' => $screen, 'step' => $step], 'zoom' => ['scale' => 1.4], 'seconds' => 4];
        }
        $request = [
            'ad_ref' => null, 'length' => (int) $this->option('length'), 'formats' => array_map('trim', explode(',', (string) $this->option('formats'))), 'voice' => $this->option('voice') ?: null, 'captions' => true, 'music' => null,
            'script' => ['hook' => ['text' => $copy[0], 'accent' => $copy[1], 'seconds' => 3], 'beats' => $beats,
                'proof' => ['text' => 'HFC runs on CoreX, and we keep expanding it', 'seconds' => 2.5], 'cta' => ['line' => 'Join CoreX', 'follow' => 'Follow us', 'sub' => 'Run your agency on CoreX']],
            'brand' => ['name' => 'CoreX OS', 'logo' => 'images/corex-os-logo.svg', 'colours' => ['primary' => '#0b2a4a', 'secondary' => '#0e5a7a', 'accent' => '#33c4e0', 'text' => '#ffffff'],
                'website' => 'https://corexos.co.za', 'contact_email' => 'johan@corexos.co.za', 'phone' => '+27 76 618 5578', 'join_line' => 'Join CoreX', 'cta_line' => 'Run your agency on CoreX',
                'tagline' => 'HFC runs on CoreX, and we keep expanding it.', 'socials' => []],
        ];
        if ($this->option('demo-badge')) {
            $request['script']['hook']['sub'] = 'Your best leads are already on your screen';
            $request['script']['hook']['badge'] = ['text' => '3 hot leads', 'example' => true, 'source' => ''];
            $request['script']['cta']['follow'] = 'Follow us on Facebook';
        }
        if ($this->option('sync')) {
            config(['queue.default' => 'sync']);
        }
        try {
            $batch = $renderer->queue($request);
        } catch (AdRequestInvalid $e) {
            foreach ($e->problems as $p) {
                $this->error($p);
            }

            return self::FAILURE;
        }
        $s = $renderer->status($batch);
        $this->info("batch {$batch}: {$s['status']} {$s['percent']}%" . ($s['failure_reason'] ? ' - ' . $s['failure_reason'] : ''));
        foreach (AdRender::where('batch_id', $batch)->get() as $r) {
            $this->line(sprintf('  %-5s %-8s %s %s s %s KB', $r->format, $r->status, $r->file_name, $r->duration_seconds, $r->size_bytes ? round($r->size_bytes / 1024) : '-'));
        }

        return $s['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
