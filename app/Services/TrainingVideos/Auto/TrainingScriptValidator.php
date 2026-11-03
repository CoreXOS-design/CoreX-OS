<?php

namespace App\Services\TrainingVideos\Auto;

use Illuminate\Support\Facades\Route;

/**
 * AT-525 - a script is checked BEFORE anything is recorded: a bad selector action, a step with no "why", a missing route or a video that would run past the
 * two-minute cap is a plain sentence, not a half-made video.
 */
class TrainingScriptValidator
{
    /** @return array<int,string> problems (empty = fine) */
    public function problems(TrainingScript $s): array
    {
        $p = [];
        if (! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $s->key())) {
            $p[] = 'The key must be kebab-case (letters, numbers, dashes).';
        }
        if ($s->path !== '' && basename($s->path, '.php') !== $s->key()) {
            $p[] = 'The file name must match the key (' . $s->key() . '.php).';
        }
        if ($s->title() === '') {
            $p[] = 'A title is required.';
        }
        if ($s->url() === '' || ! str_starts_with($s->url(), '/')) {
            $p[] = 'url must be a path starting with / (the screen to open).';
        }
        if ($s->route() !== null && ! Route::has($s->route())) {
            $p[] = "The route '{$s->route()}' does not exist.";
        }
        if ($s->tourKey() !== null && \App\Support\Tours\TourRegistry::find($s->tourKey()) === null) {
            $p[] = "The tour '{$s->tourKey()}' does not exist.";
        }
        foreach ($s->specs() as $spec) {
            if (! is_file(base_path($spec))) {
                $p[] = "The spec file '{$spec}' does not exist.";
            }
        }
        if ($s->steps() === []) {
            $p[] = 'At least one step is required.';
        }
        foreach ($s->steps() as $i => $step) {
            $n = $i + 1;
            $action = (string) ($step['action'] ?? '');
            if (! in_array($action, TrainingScript::ACTIONS, true)) {
                $p[] = "Step {$n}: action '{$action}' is not one of " . implode(', ', TrainingScript::ACTIONS) . '.';
            }
            if (in_array($action, TrainingScript::NEEDS_SELECTOR, true) && trim((string) ($step['selector'] ?? '')) === '') {
                $p[] = "Step {$n}: '{$action}' needs a selector.";
            }
            if (in_array($action, ['type', 'select', 'goto', 'press'], true) && trim((string) ($step['value'] ?? '')) === '') {
                $p[] = "Step {$n}: '{$action}' needs a value.";
            }
            if (trim((string) ($step['narration'] ?? '')) === '') {
                $p[] = "Step {$n}: say what to do (narration).";
            }
            // AT-526: the narrated tour's extra fields
            if (trim((string) ($step['done'] ?? '')) !== '') {
                [$kind, $arg] = TrainingScript::doneOf($step);
                if (! in_array($kind, TrainingScript::DONE_KINDS, true)) {
                    $p[] = "Step {$n}: done '" . $step['done'] . "' is not one of " . implode(', ', TrainingScript::DONE_KINDS) . '.';
                } elseif (in_array($kind, ['appear', 'url'], true) && $arg === null) {
                    $p[] = "Step {$n}: done '{$kind}' needs something after the colon (e.g. {$kind}:" . ($kind === 'url' ? '/corex/diary' : '#panel') . ').';
                } elseif ($kind === 'click' && trim((string) ($step['selector'] ?? '')) === '' && trim((string) ($step['target'] ?? '')) === '') {
                    $p[] = "Step {$n}: done 'click' needs a selector or a target to click.";
                }
            }
            if (trim((string) ($step['why'] ?? '')) === '') {
                $p[] = "Step {$n}: say WHY it matters - what this feeds elsewhere (why). That is the point of the video.";
            }
        }
        foreach ($this->plainWordProblems($s) as $line) {
            $p[] = $line;
        }
        $estimate = $this->estimateSeconds($s);
        $cap = (int) config('training-videos.auto.max_seconds', 120);
        if ($estimate > $cap) {
            $p[] = 'About ' . (int) round($estimate) . ' seconds of narration - over the ' . $cap . ' second limit. Shorten the lines or split it into two screens.';
        }

        return $p;
    }

    /**
     * Johan's plain-words rule (spec section 9.13): every spoken / captioned line is in an agent's words, one idea per line. Flags a banned software word (list in
     * config training-videos.auto.plain_words.banned, printed in the spec) and any line longer than max_line_words.
     *
     * @return array<int,string>
     */
    public function plainWordProblems(TrainingScript $s): array
    {
        $p = [];
        $banned = (array) config('training-videos.auto.plain_words.banned', []);
        $max = (int) config('training-videos.auto.plain_words.max_line_words', 20);
        $lines = [];
        if ($s->intro() !== '') {
            $lines['The intro'] = $s->intro();
        }
        foreach ($s->steps() as $i => $step) {
            $lines['Step ' . ($i + 1) . ' (what to do)'] = (string) ($step['narration'] ?? '');
            $lines['Step ' . ($i + 1) . ' (why)'] = (string) ($step['why'] ?? '');
        }
        if ($s->outro() !== '') {
            $lines['The outro'] = $s->outro();
        }
        foreach ($lines as $where => $text) {
            foreach ($banned as $word => $say) {
                if (preg_match('/(?<![\w])' . preg_quote((string) $word, '/') . '(?![\w])/iu', $text)) {
                    $p[] = "{$where}: \"{$word}\" is a software word - say \"{$say}\" instead.";
                }
            }
            $n = str_word_count($text);
            if ($n > $max) {
                $p[] = "{$where}: {$n} words - keep a line to about 15 and never over {$max}. One idea per line.";
            }
        }

        return $p;
    }

    /** A first estimate from the word count (the real length is measured from the audio when the video is made). */
    public function estimateSeconds(TrainingScript $s): float
    {
        $wps = (float) config('training-videos.auto.words_per_second', 2.5);
        $pad = (float) config('training-videos.auto.step_pad_seconds', 0.6);
        $t = (float) config('training-videos.auto.intro_seconds', 3.0) + (float) config('training-videos.auto.outro_seconds', 4.0);
        foreach ($s->spokenLines() as $line) {
            $t += str_word_count($line) / $wps + $pad;
        }
        foreach ($s->steps() as $step) {
            $t += (float) ($step['hold'] ?? 0);
        }

        return $t;
    }
}
