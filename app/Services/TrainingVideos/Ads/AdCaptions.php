<?php

namespace App\Services\TrainingVideos\Ads;

/**
 * AT-525 / AT-527 (spec 9.16.1) - burned-in captions for an ad as an ASS file: Inter, white with a dark outline, big, at most about two lines, kept inside the format's safe zone
 * (9:16: between 62% and 78% of the height, 6% side margins - AdFormat). Only the "shot" segments carry captions; the cards already show their words.
 */
class AdCaptions
{
    /** @param array<int,array{start:float,end:float,text:string}> $cues */
    public function ass(array $cues, array $shape): string
    {
        $w = $shape['w'];
        $h = $shape['h'];
        $side = (int) round($w * 0.06);
        $marginV = (int) round($h * $shape['margin_v']);
        $out = "[Script Info]\nScriptType: v4.00+\nPlayResX: {$w}\nPlayResY: {$h}\nWrapStyle: 2\nScaledBorderAndShadow: yes\n\n"
            . "[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n"
            . "Style: Cap,Inter,{$shape['font']},&H00FFFFFF,&H00FFFFFF,&H00000000,&H78000000,-1,0,0,0,100,100,0,0,1,5,2,2,{$side},{$side},{$marginV},1\n\n"
            . "[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
        foreach ($cues as $c) {
            $text = str_replace("\n", '\\N', wordwrap(trim($c['text']), $shape['chars'], "\n", true));
            $out .= 'Dialogue: 0,' . $this->stamp($c['start']) . ',' . $this->stamp($c['end']) . ",Cap,,0,0,0,,{$text}\n";
        }

        return $out;
    }

    private function stamp(float $s): string
    {
        $cs = (int) round($s * 100);

        return sprintf('%d:%02d:%02d.%02d', intdiv($cs, 360000), intdiv($cs % 360000, 6000), intdiv($cs % 6000, 100), $cs % 100);
    }
}
