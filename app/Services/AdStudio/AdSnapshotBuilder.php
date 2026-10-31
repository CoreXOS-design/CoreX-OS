<?php

namespace App\Services\AdStudio;

use App\Services\TrainingVideos\Auto\TrainingScript;

/**
 * AT-527 - freezes everything an ad is made from (spec .ai/specs/training-videos.md 9.21.3): the headline, the shots with their screen selectors resolved from
 * the training script AT THIS MOMENT, the overrides, voice, length, formats, post text and the whole brand, plus the AdRenderRequest of 9.16.1 / 9.16.2. The render
 * reads only this, so editing a script, the brand or a training script later never changes an ad that was already generated.
 */
class AdSnapshotBuilder
{
    /**
     * @param  array<string,mixed>  $draft  the editor's draft (AdScriptValidator::check shape), already checked
     * @param  array<string,mixed>  $brand  AdBrand::current()
     * @return array<string,mixed>
     */
    public function build(array $draft, AdScript $script, TrainingScript $training, array $brand): array
    {
        $question = trim((string) $draft['question']);
        $plain = AdScriptValidator::stripAccent($question);
        $accent = AdScriptValidator::accentWord($question);
        $lim = (array) config('ad-studio.limits');
        $wps = (float) $lim['words_per_second'];
        $steps = $training->steps();

        $joinLine = trim((string) ($draft['cta']['join_line'] ?? '')) ?: (string) ($brand['join_line'] ?? 'Join CoreX');
        $ctaLine = trim((string) ($draft['cta']['cta_line'] ?? '')) ?: (string) ($brand['cta_line'] ?? '');

        $shots = [];
        $beats = [];
        foreach (array_values((array) $draft['shots']) as $s) {
            $hasStep = isset($s['step']) && $s['step'] !== '' && $s['step'] !== null;
            $step = $hasStep ? ($steps[(int) $s['step']] ?? []) : [];
            $selector = $hasStep ? (string) ($step['selector'] ?? '') : (string) ($s['selector'] ?? '');
            $action = (string) ($s['action'] ?? 'highlight');
            $zoom = round((float) ($s['zoom'] ?? 1.0), 2);
            $caption = trim((string) $s['caption']);
            $shots[] = ['caption' => $caption, 'step' => $hasStep ? (int) $s['step'] : null, 'selector' => $selector, 'action' => $action, 'zoom' => $zoom,
                'proof' => (bool) ($s['proof'] ?? false), 'hold' => (float) ($s['hold'] ?? 0)];
            $beats[] = [
                'benefit' => $caption,
                'screen' => $hasStep ? ['script_key' => $training->key(), 'step' => (int) $s['step']] : ['route' => $training->route(), 'selector' => $selector, 'action' => $action],
                'zoom' => ['selector' => $selector ?: null, 'scale' => $zoom],
                'seconds' => (float) max(2, min(6, round(str_word_count($caption) / $wps + (float) $lim['line_pad_seconds'] + (float) ($s['hold'] ?? 0), 1))),
            ];
        }

        $post = (array) ($draft['post'] ?? []);
        $caption = AdPostText::caption((string) ($post['caption'] ?? ''), $plain, $brand, $ctaLine);
        $hashtags = AdPostText::hashtags($post['hashtags'] ?? []);
        $voice = ($draft['voice'] ?? '') !== '' ? (string) $draft['voice'] : null;
        $length = (int) $draft['length'];
        $formats = array_values((array) $draft['formats']);

        $request = [
            'ad_ref' => null,   // the video_ads.id, filled in when the row is saved
            'length' => $length,
            'formats' => $formats,
            'voice' => $voice,
            'captions' => true,
            'music' => null,
            'script' => [
                'hook' => ['text' => $plain, 'accent' => $accent, 'seconds' => (float) max(3, min(4, ceil(str_word_count($plain) / $wps)))],
                'beats' => $beats,
                'proof' => ['text' => (string) ($brand['tagline'] ?? ''), 'seconds' => 2.5],
                'cta' => ['line' => $joinLine, 'follow' => 'Follow us', 'sub' => $ctaLine],
            ],
            'brand' => [
                'name' => 'CoreX',
                'logo' => AdBrand::logoPath($brand),
                'colours' => ['primary' => $brand['colours']['primary'] ?? null, 'accent' => $brand['colours']['accent'] ?? null, 'text' => $brand['colours']['text'] ?? null,
                    'background' => $brand['colours']['secondary'] ?? null],
                'cta_url' => $brand['website'] ?? null, 'website' => $brand['website'] ?? null, 'contact_email' => $brand['email'] ?? null, 'phone' => $brand['phone'] ?? null,
                'socials' => array_filter((array) ($brand['social'] ?? []), fn ($v) => trim((string) $v) !== ''),
                'tagline' => $brand['tagline'] ?? null, 'join_line' => $joinLine, 'cta_line' => $ctaLine,
            ],
        ];

        return [
            'version' => 1,
            'feature' => $script->key(),
            'feature_title' => $script->title(),
            'question' => ['text' => $plain, 'raw' => $question, 'accent' => $accent],
            'shots' => $shots,
            'cta' => ['join_line' => $joinLine, 'cta_line' => $ctaLine, 'join_override' => trim((string) ($draft['cta']['join_line'] ?? '')), 'cta_override' => trim((string) ($draft['cta']['cta_line'] ?? ''))],
            'voice' => $voice,
            'length' => $length,
            'formats' => $formats,
            'post' => ['caption' => $caption, 'hashtags' => $hashtags, 'template' => (string) ($post['caption'] ?? '')],
            'brand' => $brand,
            'footage' => ['screen_key' => $training->key(), 'url' => $training->url(), 'login_as' => $training->loginAs()],
            'request' => $request,
        ];
    }

    /**
     * The editor draft a snapshot was made from - used by Duplicate. The frozen shots keep their step index and selector.
     *
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    public function toDraft(array $snapshot): array
    {
        return [
            'feature' => $snapshot['feature'],
            'question' => $snapshot['question']['raw'] ?? $snapshot['question']['text'],
            'shots' => array_map(fn ($s) => ['caption' => $s['caption'], 'step' => $s['step'], 'selector' => $s['selector'] ?? '', 'action' => $s['action'], 'zoom' => $s['zoom'],
                'proof' => (bool) ($s['proof'] ?? false), 'hold' => $s['hold'] ?? 0], (array) $snapshot['shots']),
            'cta' => ['join_line' => $snapshot['cta']['join_override'] ?? '', 'cta_line' => $snapshot['cta']['cta_override'] ?? ''],
            'length' => $snapshot['length'],
            'formats' => $snapshot['formats'],
            'voice' => $snapshot['voice'],
            'post' => ['caption' => $snapshot['post']['template'] ?? $snapshot['post']['caption'], 'hashtags' => $snapshot['post']['hashtags']],
        ];
    }
}
