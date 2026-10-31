<?php

namespace App\Services\TrainingVideos\Ads;

/**
 * AT-525 / AT-527 (spec 9.16.1) - the three output shapes of an ad and where captions may sit. In 9:16 the platform's own buttons cover about the top 14%, the bottom 22% and the
 * right 12%, so captions live between 62% and 78% of the height with 6% side margins; 1:1 and 16:9 use the lower third.
 */
final class AdFormat
{
    private const SHAPES = [
        '9:16' => ['key' => '9x16', 'w' => 1080, 'h' => 1920, 'font' => 54, 'chars' => 28, 'margin_v' => 0.22, 'content_top' => 0.16, 'content_bottom' => 0.78],
        '1:1' => ['key' => '1x1', 'w' => 1080, 'h' => 1080, 'font' => 48, 'chars' => 32, 'margin_v' => 0.10, 'content_top' => 0.08, 'content_bottom' => 0.90],
        '16:9' => ['key' => '16x9', 'w' => 1920, 'h' => 1080, 'font' => 52, 'chars' => 44, 'margin_v' => 0.09, 'content_top' => 0.08, 'content_bottom' => 0.92],
    ];

    /** @return array{key:string,w:int,h:int,font:int,chars:int,margin_v:float,content_top:float,content_bottom:float} */
    public static function get(string $format): array
    {
        return self::SHAPES[$format] ?? throw new \InvalidArgumentException("Unknown ad format {$format}");
    }

    /** The crop window (in a 1920x1080 recording) for a format at a zoom: the format's aspect, full frame at 1.0, tighter as zoom grows. */
    public static function window(string $format, float $zoom, int $srcW = 1920, int $srcH = 1080): array
    {
        $s = self::get($format);
        $aspect = $s['w'] / $s['h'];
        $h = $srcH / max(1.0, $zoom);
        $w = $h * $aspect;
        if ($w > $srcW / max(1.0, $zoom)) {
            $w = $srcW / max(1.0, $zoom);
            $h = $w / $aspect;
        }

        return ['w' => (int) (floor($w / 2) * 2), 'h' => (int) (floor($h / 2) * 2)];
    }
}
