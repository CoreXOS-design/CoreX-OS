<?php

namespace App\Services\TrainingVideos\Auto;

use Symfony\Component\Process\Process;

/**
 * AT-525 (spec section 9.5) - turns the raw screen recording + the measured voice clips into the finished MP4: the clips are placed at the step start times the
 * recorder reported (sync by construction), H.264 + AAC (CRF 26, faststart) like AT-509's compressor, captions burned in AND written as a .vtt sidecar,
 * a poster frame, and the 120-second cap enforced on the real length.
 */
class VideoAssembler
{
    /**
     * @param  array<int,array{file:string,start:float,end:float,text:string}>  $cues  one per spoken line, in order
     * @return array{duration:float,width:int,height:int,size:int,mp4:string,poster:string,vtt:string}
     */
    public function assemble(string $rawVideo, array $cues, string $outBase): array
    {
        $ffmpeg = (string) config('training-videos.ffmpeg', 'ffmpeg');
        $srt = $outBase . '.srt';
        $vtt = $outBase . '.vtt';
        file_put_contents($srt, $this->subtitles($cues, false));
        file_put_contents($vtt, $this->subtitles($cues, true));

        $args = [$ffmpeg, '-y', '-v', 'error', '-i', $rawVideo];
        $filters = [];
        foreach ($cues as $i => $c) {
            $args[] = '-i';
            $args[] = $c['file'];
            $ms = (int) round($c['start'] * 1000);
            $filters[] = '[' . ($i + 1) . ':a]adelay=' . $ms . '|' . $ms . '[a' . $i . ']';
        }
        $n = count($cues);
        $mix = implode('', array_map(fn ($i) => "[a{$i}]", range(0, max(0, $n - 1))));
        $filters[] = $n > 1 ? $mix . 'amix=inputs=' . $n . ':normalize=0:duration=longest[aout]' : '[a0]anull[aout]';
        $style = "FontName=DejaVu Sans,FontSize=18,PrimaryColour=&H00FFFFFF,OutlineColour=&H80000000,BorderStyle=3,Outline=1,Shadow=0,MarginV=24";
        $vf = "subtitles='" . str_replace(["\\", ":", "'"], ["\\\\", "\\:", "\\'"], $srt) . "':force_style='" . $style . "'";
        $mp4 = $outBase . '.mp4';
        array_push($args, '-filter_complex', implode(';', $filters), '-map', '0:v', '-map', '[aout]', '-vf', $vf,
            '-c:v', 'libx264', '-preset', 'medium', '-crf', '26', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '96k', '-movflags', '+faststart', '-shortest', $mp4);
        $this->run($args, 'assemble');

        $info = $this->probe($mp4);
        $poster = $outBase . '.jpg';
        $this->run([$ffmpeg, '-y', '-v', 'error', '-ss', (string) min(3, max(0.5, $info['duration'] / 3)), '-i', $mp4, '-frames:v', '1', '-q:v', '3', $poster], 'poster');

        $cap = (int) config('training-videos.auto.max_seconds', 120);
        if ($info['duration'] > $cap + 2) {
            throw new \RuntimeException('The finished video is ' . (int) round($info['duration']) . ' seconds - over the ' . $cap . ' second limit. Shorten the script.');
        }

        return $info + ['mp4' => $mp4, 'poster' => $poster, 'vtt' => $vtt];
    }

    /** @return array{duration:float,width:int,height:int,size:int} */
    public function probe(string $file): array
    {
        $p = new Process([(string) config('training-videos.ffprobe', 'ffprobe'), '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=width,height:format=duration,size', '-of', 'json', $file]);
        $p->setTimeout(60);
        $p->run();
        $j = json_decode($p->getOutput(), true) ?: [];

        return ['duration' => (float) ($j['format']['duration'] ?? 0), 'width' => (int) ($j['streams'][0]['width'] ?? 0), 'height' => (int) ($j['streams'][0]['height'] ?? 0), 'size' => (int) ($j['format']['size'] ?? 0)];
    }

    /** SRT (comma) or WebVTT (dot), the line wrapped so it fits under the picture. */
    public function subtitles(array $cues, bool $vtt): string
    {
        $out = $vtt ? "WEBVTT\n\n" : '';
        foreach ($cues as $i => $c) {
            $out .= (! $vtt ? ($i + 1) . "\n" : '') . $this->stamp($c['start'], $vtt) . ' --> ' . $this->stamp($c['end'], $vtt) . "\n" . wordwrap(trim($c['text']), 52, "\n", true) . "\n\n";
        }

        return $out;
    }

    private function stamp(float $s, bool $vtt): string
    {
        $ms = (int) round($s * 1000);

        return sprintf('%02d:%02d:%02d%s%03d', intdiv($ms, 3600000), intdiv($ms % 3600000, 60000), intdiv($ms % 60000, 1000), $vtt ? '.' : ',', $ms % 1000);
    }

    private function run(array $cmd, string $what): void
    {
        $p = new Process($cmd);
        $p->setTimeout(600);
        $p->run();
        if (! $p->isSuccessful()) {
            throw new \RuntimeException("ffmpeg ({$what}) failed: " . trim(substr($p->getErrorOutput(), -400)));
        }
    }
}
