<?php

namespace App\Services\TrainingVideos\Ads;

use App\Models\AdRender;
use App\Services\TrainingVideos\Auto\TrainingScriptRepository;

/**
 * AT-525 / AT-527 (spec 9.16.1, 9.16.2) - the renderer's own check of an ad request, BEFORE anything is rendered. Ad Studio checks wording and limits live in its editor; this
 * re-check wins on screen steps and selectors, and it is the only place that knows the render budget. Problems are plain sentences ("Shot 3: ...") the editor can show as they are.
 */
class AdRequestValidator
{
    public const LENGTHS = [15, 30, 60];
    /** software / internal words (9.13) plus the marketing rules (9.16): never "build / building / built / developing" about CoreX; no price or discount promise. */
    private const NEVER = [
        '/\b(build|builds|building|built|developing|developed|develop)\b/i' => 'never say CoreX is being built - say "HFC runs on CoreX, and we keep expanding it"',
        '/\b(discount|cheapest|lowest price|guarantee|guaranteed|\d+\s?%\s?off)\b/i' => 'no price or discount promises',
    ];

    public function __construct(private readonly TrainingScriptRepository $scripts)
    {
    }

    /** @param array<string,mixed> $r @return array<int,string> */
    public function problems(array $r): array
    {
        $p = [];
        if (! in_array((int) ($r['length'] ?? 0), self::LENGTHS, true)) {
            $p[] = 'The length must be 15, 30 or 60 seconds.';
        }
        $formats = (array) ($r['formats'] ?? []);
        if ($formats === [] || array_diff($formats, AdRender::FORMATS) !== []) {
            $p[] = 'Choose one or more formats: ' . implode(', ', AdRender::FORMATS) . '.';
        }
        foreach (['name', 'website', 'contact_email', 'join_line'] as $k) {
            if (trim((string) ($r['brand'][$k] ?? '')) === '') {
                $p[] = "The brand is missing its {$k}.";
            }
        }
        $script = (array) ($r['script'] ?? []);
        $hook = (array) ($script['hook'] ?? []);
        $hookText = trim((string) ($hook['text'] ?? ''));
        if ($hookText === '') {
            $p[] = 'The opening question is empty.';
        } else {
            if (str_word_count($hookText) > 12) {
                $p[] = 'The opening question is over 12 words - it must fit about 3-4 seconds.';
            }
            if (($acc = trim((string) ($hook['accent'] ?? ''))) !== '' && stripos($hookText, $acc) === false) {
                $p[] = 'The accent word "' . $acc . '" is not in the opening question.';
            }
        }
        // optional look fields (spec 9.16.5): never a reason to refuse a request, only a limit to keep
        $sub = trim((string) ($hook['sub'] ?? ''));
        if ($sub !== '' && str_word_count($sub) > 10) {
            $p[] = 'The line under the question is over 10 words.';
        }
        $badgeText = trim((string) ($hook['badge']['text'] ?? ''));
        if ($badgeText !== '' && mb_strlen($badgeText) > 14) {
            $p[] = 'The circle in the corner holds at most 14 letters.';
        }
        $beats = array_values((array) ($script['beats'] ?? []));
        if ($beats === [] || count($beats) > 8) {
            $p[] = 'An ad needs 1 to 8 shots.';
        }
        $lines = ['The opening question' => $hookText, 'The line under the question' => $sub, 'The circle in the corner' => $badgeText];
        foreach ($beats as $i => $b) {
            $n = $i + 1;
            $text = trim((string) ($b['benefit'] ?? ''));
            if ($text === '') {
                $p[] = "Shot {$n}: the words on screen are empty.";
            }
            if (str_word_count($text) > 15) {
                $p[] = "Shot {$n}: over 15 words - keep it short enough to read in a few seconds.";
            }
            $sec = (float) ($b['seconds'] ?? 0);
            if ($sec < 2 || $sec > 6) {
                $p[] = "Shot {$n}: each shot is 2 to 6 seconds.";
            }
            $z = (float) ($b['zoom']['scale'] ?? 1.0);
            if ($z < 1.0 || $z > 2.5) {
                $p[] = "Shot {$n}: the zoom must be between 1.0 and 2.5.";
            }
            array_push($p, ...$this->screenProblems($n, (array) ($b['screen'] ?? [])));
            $lines["Shot {$n}"] = $text;
        }
        foreach (['proof' => 'The brand line'] as $k => $label) {
            if (($t = trim((string) ($script[$k]['text'] ?? ''))) !== '') {
                $lines[$label] = $t;
            }
        }
        $lines['The call to action'] = trim((string) ($script['cta']['line'] ?? '') . ' ' . (string) ($script['cta']['sub'] ?? ''));
        foreach ($lines as $where => $text) {
            foreach (self::NEVER as $re => $why) {
                if (preg_match($re, $text)) {
                    $p[] = "{$where}: {$why}.";
                }
            }
            foreach ((array) config('training-videos.auto.plain_words.banned', []) as $word => $say) {
                if ($text !== '' && preg_match('/(?<![\w])' . preg_quote((string) $word, '/') . '(?![\w])/iu', $text)) {
                    $p[] = "{$where}: \"{$word}\" is a software word - say \"{$say}\" instead.";
                }
            }
        }
        // the whole ad has to fit the chosen length
        $est = $this->estimateSeconds($r);
        $len = (int) ($r['length'] ?? 0);
        if ($len > 0 && $est > $len * 1.1) {
            $p[] = 'The ad would run about ' . (int) round($est) . ' seconds - over the ' . $len . ' seconds chosen. Remove a shot or shorten the words.';
        }

        return $p;
    }

    /** @param array<string,mixed> $screen @return array<int,string> */
    private function screenProblems(int $n, array $screen): array
    {
        if (isset($screen['script_key'])) {
            $s = $this->scripts->find((string) $screen['script_key']);
            if (! $s) {
                return ["Shot {$n}: the screen '{$screen['script_key']}' is not a known training script."];
            }
            $idx = (int) ($screen['step'] ?? -1);
            if ($idx < 0 || ! isset($s->steps()[$idx])) {
                return ["Shot {$n}: that screen has no step number " . ($idx + 1) . '.'];
            }
            if (! $s->approved()) {
                return ["Shot {$n}: the training script '{$s->key()}' is not approved yet."];
            }

            return [];
        }
        if (trim((string) ($screen['route'] ?? '')) === '' && trim((string) ($screen['url'] ?? '')) === '' || trim((string) ($screen['selector'] ?? '')) === '') {
            return ["Shot {$n}: say which screen step to show."];
        }

        return [];
    }

    /** Seconds the ad will take: hook + each shot (its own seconds or its speech, whichever is longer) + the brand line + the 3.5 s end card. */
    public function estimateSeconds(array $r): float
    {
        $wps = (float) config('training-videos.auto.words_per_second', 2.5);
        $s = (array) ($r['script'] ?? []);
        $t = max(3.0, str_word_count((string) ($s['hook']['text'] ?? '')) / $wps + 0.6);
        foreach ((array) ($s['beats'] ?? []) as $b) {
            $t += max((float) ($b['seconds'] ?? 0), str_word_count((string) ($b['benefit'] ?? '')) / $wps + 0.6);
        }
        if (trim((string) ($s['proof']['text'] ?? '')) !== '') {
            $t += max((float) ($s['proof']['seconds'] ?? 2.5), str_word_count((string) $s['proof']['text']) / $wps + 0.6);
        }

        return $t + 3.5;
    }
}
