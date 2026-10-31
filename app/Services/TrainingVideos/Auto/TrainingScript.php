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
 *   steps[]    selector (CSS, optional), action (see ACTIONS), value (type/goto), narration (what to do), why (what it feeds elsewhere - REQUIRED: the point of the video), hold (extra seconds)
 */
final class TrainingScript
{
    /** What a step may do. highlight = spotlight the element; click; type = type `value` into it; select = choose option `value` in a <select>; scroll = bring it into view; wait = just pause; goto = open `value`. */
    public const ACTIONS = ['highlight', 'click', 'type', 'select', 'scroll', 'wait', 'goto', 'press'];
    public const NEEDS_SELECTOR = ['highlight', 'click', 'type', 'select', 'scroll'];

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
}
