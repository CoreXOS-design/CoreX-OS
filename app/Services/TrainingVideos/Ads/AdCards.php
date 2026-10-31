<?php

namespace App\Services\TrainingVideos\Ads;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * AT-525 / AT-527 (spec 9.16.1) - the full-screen cards of an ad (the opening question, the brand line, the end card) as PNGs at each format's size, drawn by headless Chromium from
 * HTML in the brand's colours and the CoreX font (Inter, resources/fonts/inter). Content stays inside the format's safe zone (AdFormat). The brand is whatever the request carries -
 * nothing here is hard-coded to one agency or to CoreX's colours.
 */
class AdCards
{
    /**
     * @param  array<string,mixed>  $brand
     * @param  array<int,array{id:string,html:\Closure,transparent?:bool}>  $cards  by card id: 'hook', 'proof', 'end', 'badge' (a transparent overlay)
     * @param  array<int,string>  $formats
     * @return array<string,array<string,string>> format => card id => png path
     */
    public function render(array $cards, array $formats, string $dir): array
    {
        File::ensureDirectoryExists($dir);
        $items = [];
        $out = [];
        foreach ($formats as $f) {
            $shape = AdFormat::get($f);
            foreach ($cards as $c) {
                $html = $dir . "/{$c['id']}-{$shape['key']}.html";
                $png = $dir . "/{$c['id']}-{$shape['key']}.png";
                file_put_contents($html, $c['html']($shape));
                $items[] = ['html' => $html, 'out' => $png, 'width' => $shape['w'], 'height' => $shape['h'], 'transparent' => ! empty($c['transparent'])];
                $out[$f][$c['id']] = $png;
            }
        }
        $job = $dir . '/cards.json';
        file_put_contents($job, json_encode(['chromium' => config('training-videos.auto.recorder.chromium'), 'items' => $items]));
        $p = new Process([(string) config('training-videos.auto.recorder.node', 'node'), base_path('scripts/ad-cards.mjs'), $job], base_path());
        $p->setTimeout(180);
        $p->run();
        if (! $p->isSuccessful()) {
            throw new \RuntimeException('The ad cards could not be drawn: ' . trim(substr($p->getErrorOutput() . $p->getOutput(), -300)));
        }

        return $out;
    }

    private function head(array $brand, array $shape, bool $transparent = false): string
    {
        $c = (array) ($brand['colours'] ?? []);
        $bg1 = $c['primary'] ?? '#0b2a4a';
        $bg2 = $c['secondary'] ?? $c['background'] ?? '#0e5a7a';
        $text = $c['text'] ?? '#ffffff';
        $font = 'file://' . base_path('resources/fonts/inter');
        $top = $shape['content_top'] * 100;
        $bottom = (1 - $shape['content_bottom']) * 100;
        $side = 6;

        return "<!doctype html><html><head><meta charset=\"utf-8\"><style>
@font-face{font-family:'Inter';font-weight:700;src:url('{$font}/Inter-700.ttf')}
@font-face{font-family:'Inter';font-weight:600;src:url('{$font}/Inter-600.ttf')}
@font-face{font-family:'Inter';font-weight:400;src:url('{$font}/Inter-400.ttf')}
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:{$shape['w']}px;height:{$shape['h']}px;overflow:hidden}
body{font-family:'Inter',sans-serif;color:{$text};background:" . ($transparent ? 'transparent' : "linear-gradient(145deg,{$bg1},{$bg2})") . ";}
.safe{position:absolute;left:{$side}%;right:{$side}%;top:{$top}%;bottom:{$bottom}%;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:" . round($shape['h'] * 0.025) . "px}
</style></head><body>";
    }

    /** The wordmark: the brand's logo image when it has a non-default one, else its name drawn in the brand colours (first word in the text colour, the rest in the accent). */
    private function mark(array $brand, int $size): string
    {
        $accent = $brand['colours']['accent'] ?? '#33c4e0';
        $logo = (string) ($brand['logo_file'] ?? '');
        if ($logo !== '' && is_file($logo)) {
            return '<img src="file://' . $logo . '" style="height:' . $size . 'px;max-width:80%;object-fit:contain">';
        }
        $parts = preg_split('/\s+/', trim((string) ($brand['name'] ?? 'CoreX OS')), 2);

        return '<div style="font-weight:700;font-size:' . $size . 'px;letter-spacing:-0.04em;line-height:1">' . e($parts[0]) . (isset($parts[1]) ? ' <span style="color:' . e($accent) . '">' . e($parts[1]) . '</span>' : '') . '</div>';
    }

    /** The cyan circle in the top-right corner (inside the safe zone). A demo / example number always carries the word "Example" (spec 9.16.5). */
    private function badgeInner(array $brand, array $shape, array $badge): string
    {
        $a = $brand['colours']['accent'] ?? '#33c4e0';
        $d = (int) round($shape['w'] * 0.21);
        $top = (int) round($shape['h'] * max(0.05, $shape['content_top'] - 0.005));
        $right = (int) round($shape['w'] * 0.08);
        $label = ! empty($badge['example']) ? '<div style="font-size:' . (int) round($d * 0.13) . 'px;font-weight:700;letter-spacing:.08em;text-transform:uppercase">Example</div>' : '';
        $text = mb_strtoupper(mb_substr(trim((string) ($badge['text'] ?? '')), 0, 14));

        return '<div style="position:absolute;top:' . $top . 'px;right:' . $right . 'px;width:' . $d . 'px;height:' . $d . 'px;border-radius:50%;background:' . e($a) . ';color:#0b2a4a;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;line-height:1.05;box-shadow:0 8px 30px rgba(0,0,0,.35)">'
            . $label . '<div style="font-size:' . (int) round($d * 0.2) . 'px;font-weight:700;max-width:88%">' . e($text) . '</div></div>';
    }

    /** The badge alone on a transparent card: laid over every shot so a demo number is labelled wherever it shows. */
    public function badgeHtml(array $brand, array $badge): \Closure
    {
        return fn (array $shape) => $this->head($brand, $shape, true) . $this->badgeInner($brand, $shape, $badge) . '</body></html>';
    }

    /** The opening question: capitals, one word in the accent colour. */
    public function hookHtml(array $brand, string $text, ?string $accent, ?string $sub = null, ?array $badge = null): \Closure
    {
        return function (array $shape) use ($brand, $text, $accent, $sub, $badge) {
            $a = $brand['colours']['accent'] ?? '#33c4e0';
            $size = (int) round($shape['w'] * ($shape['w'] > $shape['h'] ? 0.058 : 0.098));
            $safe = e(mb_strtoupper($text));
            if ($accent !== null && $accent !== '') {
                $safe = preg_replace('/' . preg_quote(e(mb_strtoupper($accent)), '/') . '/u', '<span style="color:' . e($a) . '">' . e(mb_strtoupper($accent)) . '</span>', $safe, 1);
            }

            $subHtml = trim((string) $sub) !== '' ? '<div style="font-weight:600;font-size:' . (int) round($size * 0.42) . 'px;line-height:1.2;opacity:.95;max-width:92%">' . e((string) $sub) . '</div>' : '';

            return $this->head($brand, $shape) . '<div class="safe"><div style="font-weight:700;font-size:' . $size . 'px;line-height:1.12;letter-spacing:-0.02em">' . $safe . '</div>' . $subHtml . '</div>'
                . '<div style="position:absolute;left:0;right:0;top:' . round($shape['h'] * ($shape['content_top'] - 0.07)) . 'px;display:flex;justify-content:center;opacity:.9">' . $this->mark($brand, (int) round($shape['w'] * 0.04)) . '</div>'
                . ($badge ? $this->badgeInner($brand, $shape, $badge) : '') . '</body></html>';
        };
    }

    /** The brand line on its own card ("HFC runs on CoreX, and we keep expanding it"). */
    public function lineHtml(array $brand, string $text): \Closure
    {
        return function (array $shape) use ($brand, $text) {
            $size = (int) round($shape['w'] * ($shape['w'] > $shape['h'] ? 0.04 : 0.07));

            return $this->head($brand, $shape) . '<div class="safe">' . $this->mark($brand, (int) round($shape['w'] * 0.07)) . '<div style="font-weight:600;font-size:' . $size . 'px;line-height:1.2;max-width:92%">' . e($text) . '</div></div></body></html>';
        };
    }

    /** The end card: logo, join line, call to action, website / email / phone, "Follow us" + the socials that are filled in, the tagline. */
    public function endHtml(array $brand, array $cta): \Closure
    {
        return function (array $shape) use ($brand, $cta) {
            $a = $brand['colours']['accent'] ?? '#33c4e0';
            $w = $shape['w'];
            $big = (int) round($w * ($w > $shape['h'] ? 0.05 : 0.105));
            $mid = (int) round($w * ($w > $shape['h'] ? 0.026 : 0.047));
            $small = (int) round($w * ($w > $shape['h'] ? 0.019 : 0.037));
            $contact = array_filter([(string) ($brand['website'] ?? ''), (string) ($brand['contact_email'] ?? ''), (string) ($brand['phone'] ?? '')]);
            $socials = [];
            foreach ((array) ($brand['socials'] ?? []) as $name => $url) {
                if (trim((string) $url) !== '') {
                    $socials[] = ucfirst((string) $name) . ' ' . preg_replace('#^https?://(www\.)?#', '', rtrim((string) $url, '/'));
                }
            }
            $html = $this->head($brand, $shape) . '<div class="safe">' . $this->mark($brand, (int) round($w * 0.085))
                . '<div style="font-weight:700;font-size:' . $big . 'px;line-height:1.05;color:' . e($a) . '">' . e((string) ($cta['line'] ?? 'Join CoreX')) . '</div>';
            if (trim((string) ($cta['sub'] ?? '')) !== '') {
                // the call to action as the cyan pill button with an arrow (the look of Andre's ads, spec 9.16.5)
                $html .= '<div style="display:inline-flex;align-items:center;gap:' . (int) round($mid * 0.5) . 'px;background:' . e($a) . ';color:#0b2a4a;font-weight:700;font-size:' . $mid . 'px;line-height:1.1;padding:' . (int) round($mid * 0.5) . 'px ' . (int) round($mid * 1.0) . 'px;border-radius:999px;max-width:92%">'
                    . e((string) $cta['sub']) . ' <span style="font-size:' . (int) round($mid * 1.1) . 'px">&rarr;</span></div>';
            }
            $html .= '<div style="font-weight:600;font-size:' . $mid . 'px;line-height:1.5">' . implode('<br>', array_map('e', $contact)) . '</div>';
            if ($socials) {
                $html .= '<div style="font-size:' . $small . 'px;line-height:1.5;opacity:.95"><strong style="font-size:' . $mid . 'px">' . e((string) ($cta['follow'] ?? 'Follow us')) . '</strong><br>' . implode('<br>', array_map('e', $socials)) . '</div>';
            } else {
                $html .= '<div style="font-weight:700;font-size:' . $mid . 'px">' . e((string) ($cta['follow'] ?? 'Follow us')) . '</div>';
            }
            if (trim((string) ($brand['tagline'] ?? '')) !== '') {
                $html .= '<div style="font-size:' . $small . 'px;opacity:.8;max-width:90%">' . e((string) $brand['tagline']) . '</div>';
            }

            return $html . '</div></body></html>';
        };
    }
}
