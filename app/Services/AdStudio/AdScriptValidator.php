<?php

namespace App\Services\AdStudio;

use App\Services\TrainingVideos\Auto\TrainingScriptRepository;
use App\Services\TrainingVideos\Tts\TrainingVoices;

/**
 * AT-527 - the live check behind the script editor and the gate on Generate (spec .ai/specs/training-videos.md 9.21.2). Takes the editor's draft and returns
 * plain sentences: plain words and banned words (the 9.13 list), line length, the "evaluation" rule, the build-words rule, no price promises, an approved
 * feature script, shots that point at a real screen step, and an estimated length that fits the length picked.
 *
 * Draft: ['feature', 'question' (accent word between asterisks), 'shots' => [['caption','step'|'selector','action','zoom','proof','hold']], 'cta' => ['join_line','cta_line'],
 *         'length', 'formats' => [], 'voice', 'post' => ['caption','hashtags'=>[]]]
 */
class AdScriptValidator
{
    public function __construct(
        private readonly AdScriptRepository $ads,
        private readonly TrainingScriptRepository $training,
        private readonly TrainingVoices $voices,
    ) {
    }

    /** @param array<string,mixed> $draft @return array{problems: array<int,string>, seconds: float, length: int} */
    public function check(array $draft): array
    {
        $p = [];
        $lim = (array) config('ad-studio.limits');
        $length = (int) ($draft['length'] ?? 0);

        $script = $this->ads->find((string) ($draft['feature'] ?? ''));
        if (! $script) {
            $p[] = 'Pick a feature that has an ad script.';
        } elseif (! $script->approved()) {
            $p[] = 'The ad script for ' . $script->title() . ' is not approved yet. Johan approves it first.';
        }
        $training = $script ? $this->training->find($script->screen()) : null;
        if ($script && ! $training) {
            $p[] = "The screen this ad films ('{$script->screen()}') has no training script.";
        }

        // Question
        $question = trim((string) ($draft['question'] ?? ''));
        $plainQuestion = self::stripAccent($question);
        if ($plainQuestion === '') {
            $p[] = 'Write the question - the headline.';
        } else {
            if (! preg_match('/\*[^*\s][^*]*\*/u', $question)) {
                $p[] = 'Mark one word of the question in the cyan colour by putting it between asterisks, like *guessing*.';
            } elseif (preg_match_all('/\*[^*]+\*/u', $question) > 1) {
                $p[] = 'Mark only ONE word of the question in cyan.';
            }
            $words = str_word_count($plainQuestion);
            if ($words > $lim['question_words'] || mb_strlen($plainQuestion) > $lim['question_chars']) {
                $p[] = "The question is too long ({$words} words). Keep it to {$lim['question_words']} words and {$lim['question_chars']} letters so it is said in about 3 seconds.";
            }
            array_push($p, ...$this->wording('The question', $plainQuestion));
        }

        // Sub-line (short punchy white text under the headline) and the stat badge (a cyan circle: a real sourced number, or an example labelled as such)
        $subline = trim((string) ($draft['subline'] ?? ''));
        if ($subline !== '') {
            if (str_word_count($subline) > $lim['subline_words'] || mb_strlen($subline) > $lim['subline_chars']) {
                $p[] = "The sub-line is too long. Keep it to {$lim['subline_words']} words and {$lim['subline_chars']} letters.";
            }
            array_push($p, ...$this->wording('The sub-line', $subline));
        }
        $badge = (array) ($draft['badge'] ?? []);
        $badgeText = trim((string) ($badge['text'] ?? ''));
        if ($badgeText !== '') {
            if (mb_strlen($badgeText) > $lim['badge_chars']) {
                $p[] = "The stat badge is too long (keep it to {$lim['badge_chars']} letters, like 3 in 10).";
            }
            if (empty($badge['example']) && trim((string) ($badge['source'] ?? '')) === '') {
                $p[] = 'A stat badge must be a real number with its source written in, or be ticked as an example. Demo numbers are always labelled as an example.';
            }
        }

        // Shots
        $shots = array_values((array) ($draft['shots'] ?? []));
        if (count($shots) < $lim['shots_min']) {
            $p[] = 'Add at least one solution shot.';
        }
        if (count($shots) > $lim['shots_max']) {
            $p[] = "Too many shots - keep it to {$lim['shots_max']}.";
        }
        $stepCount = $training ? count($training->steps()) : 0;
        $extra = 0.0;
        foreach ($shots as $i => $s) {
            $n = $i + 1;
            $caption = trim((string) ($s['caption'] ?? ''));
            if ($caption === '') {
                $p[] = "Shot {$n}: write the words shown on the screen.";
            } else {
                $w = str_word_count($caption);
                if ($w > $lim['caption_words'] || mb_strlen($caption) > $lim['caption_chars']) {
                    $p[] = "Shot {$n}: {$w} words is too long to read inside the safe area. Keep it to {$lim['caption_words']} words and {$lim['caption_chars']} letters.";
                }
                array_push($p, ...$this->wording("Shot {$n}", $caption));
            }
            if (! in_array((string) ($s['action'] ?? 'highlight'), ['highlight', 'click'], true)) {
                $p[] = "Shot {$n}: the action must be highlight or click.";
            }
            $zoom = (float) ($s['zoom'] ?? 1.0);
            if ($zoom < $lim['zoom_min'] || $zoom > $lim['zoom_max']) {
                $p[] = "Shot {$n}: zoom must be between {$lim['zoom_min']} and {$lim['zoom_max']}.";
            }
            $hasStep = isset($s['step']) && $s['step'] !== '' && $s['step'] !== null;
            if ($hasStep && ($training && ((int) $s['step'] < 0 || (int) $s['step'] >= $stepCount))) {
                $p[] = "Shot {$n}: that screen step does not exist.";
            } elseif (! $hasStep && trim((string) ($s['selector'] ?? '')) === '') {
                $p[] = "Shot {$n}: choose the screen step it shows.";
            }
            $extra += (float) ($s['hold'] ?? 0);
        }

        // Call to action overrides (blank = the brand setting)
        foreach (['join_line' => 'The join line', 'cta_line' => 'The call to action'] as $k => $label) {
            $v = trim((string) ($draft['cta'][$k] ?? ''));
            if ($v !== '') {
                array_push($p, ...$this->wording($label, $v));
            }
        }

        // Length, formats, voice
        if (! in_array($length, (array) config('ad-studio.lengths'), true)) {
            $p[] = 'Pick a length of ' . implode(', ', (array) config('ad-studio.lengths')) . ' seconds.';
        }
        $formats = array_values((array) ($draft['formats'] ?? []));
        if ($formats === [] || array_diff($formats, array_keys((array) config('ad-studio.formats')))) {
            $p[] = 'Pick at least one format.';
        }
        $voice = $draft['voice'] ?? null;
        if ($voice !== null && $voice !== '' && ! $this->voices->find((string) $voice)) {
            $p[] = 'That voice is not available.';
        }

        // Post text
        $post = (array) ($draft['post'] ?? []);
        $caption = (string) ($post['caption'] ?? '');
        if (mb_strlen($caption) > $lim['post_caption_chars']) {
            $p[] = 'The post text is too long (over ' . $lim['post_caption_chars'] . ' letters).';
        }
        array_push($p, ...$this->wording('The post text', $caption, false));
        if (count((array) ($post['hashtags'] ?? [])) > $lim['hashtags_max']) {
            $p[] = 'Too many hashtags - keep it to ' . $lim['hashtags_max'] . '.';
        }

        $seconds = $this->estimateSeconds($plainQuestion, $shots, $extra, $subline);
        if ($length > 0 && in_array($length, (array) config('ad-studio.lengths'), true) && $seconds > $length) {
            $p[] = 'About ' . (int) ceil($seconds) . " seconds of video - over the {$length} second length. Shorten a line, remove a shot or pick a longer length.";
        }

        return ['problems' => array_values(array_unique($p)), 'seconds' => round($seconds, 1), 'length' => $length];
    }

    /** First estimate: spoken words / words a second + a pause per line + the end card. */
    public function estimateSeconds(string $plainQuestion, array $shots, float $extra = 0.0, string $sub = ''): float
    {
        $lim = (array) config('ad-studio.limits');
        $t = (float) $lim['end_card_seconds'] + $extra;
        $lines = array_merge([$plainQuestion, $sub], array_map(fn ($s) => (string) ($s['caption'] ?? ''), $shots));
        foreach ($lines as $line) {
            if (trim((string) $line) === '') {
                continue;
            }
            $t += str_word_count($line) / (float) $lim['words_per_second'] + (float) $lim['line_pad_seconds'];
        }

        return $t;
    }

    public static function stripAccent(string $q): string
    {
        return trim(str_replace('*', '', $q));
    }

    /** The word between asterisks, or null. */
    public static function accentWord(string $q): ?string
    {
        return preg_match('/\*([^*]+)\*/u', $q, $m) ? trim($m[1]) : null;
    }

    /**
     * Wording rules for one line. $lineLimit=false skips the 20-word limit (the post text is a paragraph, not a spoken line).
     *
     * @return array<int,string>
     */
    private function wording(string $where, string $text, bool $lineLimit = true): array
    {
        $p = [];
        $banned = (array) config('training-videos.auto.plain_words.banned', []);
        foreach ($banned as $word => $say) {
            if (preg_match('/(?<![\w])' . preg_quote((string) $word, '/') . '(?![\w])/iu', $text)) {
                $p[] = "{$where}: \"{$word}\" is a software word - say \"{$say}\" instead.";
            }
        }
        foreach ((array) config('ad-studio.banned_build_words') as $word) {
            if (preg_match('/(?<![\w])' . preg_quote((string) $word, '/') . '(?![\w])/iu', $text)) {
                $p[] = "{$where}: never say \"{$word}\" about CoreX. Say \"HFC runs on CoreX, and we keep expanding it\".";
            }
        }
        foreach ((array) config('ad-studio.banned_promises') as $phrase) {
            if (mb_stripos($text, (string) $phrase) !== false) {
                $p[] = "{$where}: no price or discount promises (\"{$phrase}\").";
            }
        }
        if ($lineLimit) {
            $max = (int) config('training-videos.auto.plain_words.max_line_words', 20);
            $n = str_word_count($text);
            if ($n > $max) {
                $p[] = "{$where}: {$n} words - one idea per line, never over {$max}.";
            }
        }

        return $p;
    }
}
