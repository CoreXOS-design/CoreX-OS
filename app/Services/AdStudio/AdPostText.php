<?php

namespace App\Services\AdStudio;

/** AT-527 - the ready-to-paste post text: a caption template with {question} {website} {cta} filled in, plus clean hashtags. */
final class AdPostText
{
    /** @param array<string,mixed> $brand */
    public static function caption(string $template, string $plainQuestion, array $brand, ?string $ctaLine = null): string
    {
        $cta = trim((string) ($ctaLine !== null && trim($ctaLine) !== '' ? $ctaLine : ($brand['cta_line'] ?? '')));

        return trim(strtr($template, [
            '{question}' => $plainQuestion,
            '{website}' => (string) ($brand['website'] ?? ''),
            '{cta}' => $cta === '' ? '' : rtrim($cta, '.:') . ':',
        ]));
    }

    /** @param array<int,string>|string $tags @return array<int,string> letters and numbers only, no '#', unique, in order */
    public static function hashtags(array|string $tags): array
    {
        if (is_string($tags)) {
            $tags = preg_split('/[\s,]+/', $tags) ?: [];
        }
        $out = [];
        foreach ($tags as $t) {
            $t = preg_replace('/[^\p{L}\p{N}_]/u', '', (string) $t);
            if ($t !== '' && ! in_array(mb_strtolower($t), array_map('mb_strtolower', $out), true)) {
                $out[] = $t;
            }
        }

        return array_slice($out, 0, (int) config('ad-studio.limits.hashtags_max', 15));
    }
}
