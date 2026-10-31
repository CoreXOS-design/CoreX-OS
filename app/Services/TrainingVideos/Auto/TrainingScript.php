<?php

namespace App\Services\TrainingVideos\Auto;

/**
 * AT-525 - one screen's recording script (spec .ai/specs/training-videos.md section 9). Plain data, written by Claude from the spec + code and approved by Johan
 * (`approved => true`); only approved scripts are generated automatically. Files live in resources/training/scripts/<key>.php and return an array:
 *
 *   key        screen key, also the file name (kebab-case)
 *   title      the video's title (what the agent sees in the library)
 *   route      the named route the "Watch how" button lives on (null = library only)
 *   url        the path to open (relative to the base URL); a route with parameters needs this
 *   tour       OPTIONAL driver.js tour key this script explains - a changed tour flags the video out of date
 *   specs      OPTIONAL list of .ai/specs/*.md files the "why" came from - a changed spec flags it out of date
 *   audience   OPTIONAL list of roles that may see it (empty = every signed-in user)
 *   related    OPTIONAL list of other screen keys ("watch next")
 *   login_as   training-agency user to record as (default 'agent')
 *   approved   bool - Johan's sign-off
 *   intro, outro   the spoken first and last lines (a title card is drawn for each)
 *   narrated   OPTIONAL bool - the screen also offers the narrated guided tour ("Guide me", AT-526). Default false.
 *   steps[]    selector (CSS, optional), action (see ACTIONS), value (type/goto), narration (what to do), why (what it feeds elsewhere - REQUIRED: the point of the video), hold (extra seconds)
 *              AT-526 (tour only, the video ignores them): target (CSS, the element the agent acts on when it differs from selector), done (see DONE_KINDS), wait_for (CSS that must be on screen
 *              before the step starts)
 */
final class TrainingScript
{
    /** What a step may do. highlight = spotlight the element; click; type = type `value` into it; select = choose option `value` in a <select>; scroll = bring it into view; wait = just pause; goto = open `value`. */
    public const ACTIONS = ['highlight', 'click', 'type', 'select', 'scroll', 'wait', 'goto', 'press'];
    public const NEEDS_SELECTOR = ['highlight', 'click', 'type', 'select', 'scroll'];

    /**
     * AT-526 - how the narrated tour knows the agent did a step (spec section 9.15). `none` = just read it (a "Got it" button); `click` = the target was clicked;
     * `input` = the field holds a valid value (typed + left, or already right and confirmed); `change` = the list was changed; `appear:<css>` = that element is on screen;
     * `url:<path>` = the page moved to that path. Default per action in defaultDone().
     */
    public const DONE_KINDS = ['none', 'click', 'input', 'change', 'appear', 'url'];
    /** Actions only the video performs: the tour skips them unless the step names a `done`. */
    public const VIDEO_ONLY_ACTIONS = ['wait', 'goto', 'press'];

    /** @param array<string,mixed> $data */
    public function __construct(public readonly array $data, public readonly string $path = '')
    {
    }

    public function key(): string { return (string) ($this->data['key'] ?? ''); }
    public function title(): string { return (string) ($this->data['title'] ?? ''); }
    public function route(): ?string { return $this->data['route'] ?? null; }
    public function url(): string { return (string) ($this->data['url'] ?? ''); }
    public function tourKey(): ?string { return $this->data['tour'] ?? null; }
    /** @return array<int,string> */
    public function specs(): array { return array_values((array) ($this->data['specs'] ?? [])); }
    /** @return array<int,string> */
    public function audience(): array { return array_values((array) ($this->data['audience'] ?? [])); }
    /** @return array<int,string> */
    public function related(): array { return array_values((array) ($this->data['related'] ?? [])); }
    public function loginAs(): string { return (string) ($this->data['login_as'] ?? 'agent'); }
    public function approved(): bool { return (bool) ($this->data['approved'] ?? false); }
    public function narrated(): bool { return (bool) ($this->data['narrated'] ?? false); }
    public function intro(): string { return (string) ($this->data['intro'] ?? ''); }
    public function outro(): string { return (string) ($this->data['outro'] ?? ''); }
    /** @return array<int,array<string,mixed>> */
    public function steps(): array { return array_values((array) ($this->data['steps'] ?? [])); }

    /** What is SAID for a step: what to do, then why it matters. */
    public static function spoken(array $step): string
    {
        return trim(trim((string) ($step['narration'] ?? '')) . ' ' . trim((string) ($step['why'] ?? '')));
    }

    /** Every line that will be spoken, in order: intro, each step, outro. @return array<int,string> */
    public function spokenLines(): array
    {
        $lines = [];
        if ($this->intro() !== '') {
            $lines[] = $this->intro();
        }
        foreach ($this->steps() as $s) {
            $lines[] = self::spoken($s);
        }
        if ($this->outro() !== '') {
            $lines[] = $this->outro();
        }

        return $lines;
    }

    /** What the tour waits for when a step does not say: the action's natural finish. */
    public static function defaultDone(array $step): string
    {
        return match ((string) ($step['action'] ?? 'highlight')) {
            'click' => 'click',
            'type' => 'input',
            'select' => 'change',
            default => 'none',
        };
    }

    /** The step's done-condition as [kind, argument] - e.g. ['appear', '#panel'], ['click', null]. */
    public static function doneOf(array $step): array
    {
        $raw = trim((string) ($step['done'] ?? '')) ?: self::defaultDone($step);
        $kind = strtolower(strtok($raw, ':') ?: 'none');
        $arg = str_contains($raw, ':') ? trim(substr($raw, strpos($raw, ':') + 1)) : null;

        return [$kind, $arg === '' ? null : $arg];
    }

    /**
     * The steps the narrated tour walks, each keeping its ORIGINAL number n (1-based; the audio clips are numbered the same way: 0 intro, 1..N steps, N+1 outro).
     * Video-only steps (wait / goto / press) are left out unless they carry their own done-condition.
     *
     * @return array<int,array<string,mixed>>
     */
    public function tourSteps(): array
    {
        $out = [];
        foreach ($this->steps() as $i => $step) {
            $action = (string) ($step['action'] ?? 'highlight');
            if (in_array($action, self::VIDEO_ONLY_ACTIONS, true) && trim((string) ($step['done'] ?? '')) === '') {
                continue;
            }
            [$kind, $arg] = self::doneOf($step);
            $out[] = [
                'n' => $i + 1,
                'selector' => (string) ($step['selector'] ?? ''),
                'target' => (string) ($step['target'] ?? ''),
                'action' => $action,
                'value' => isset($step['value']) ? (string) $step['value'] : null,
                'narration' => trim((string) ($step['narration'] ?? '')),
                'why' => trim((string) ($step['why'] ?? '')),
                'done' => $arg === null ? $kind : $kind . ':' . $arg,
                'wait_for' => (string) ($step['wait_for'] ?? ''),
            ];
        }

        return $out;
    }
}
