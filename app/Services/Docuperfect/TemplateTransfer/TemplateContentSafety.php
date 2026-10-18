<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\TemplateTransfer;

/**
 * A template's HTML is written into a Blade view file on the server and rendered
 * inside the signing pages, so an imported package is code-adjacent input — and a
 * package checksum is not authentication (anyone can recompute it). This scan is
 * therefore the real gate: it refuses PHP / Blade execution syntax, active HTML,
 * and role tokens that are not plain words. Spec: esign-template-transfer.md §6.
 */
final class TemplateContentSafety
{
    /** A role / party token: a plain word, e.g. owner_party, acquiring_party, agent, witness, Seller. */
    public const ROLE_TOKEN = '/^[A-Za-z][A-Za-z0-9 _\-]{0,40}$/';

    private const RULES = [
        'PHP code'                  => '/<\?/',
        'a template expression'     => '/\{\{|\{!!/',
        'a template directive'      => '/(?<!\w)@(?:php|endphp|include\w*|extends|component|endcomponent|slot|inject|each|livewire|push|prepend|stack|yield|section|endsection|use|props|aware|class|style|json|js|vite|csrf|method|dd|dump|can|cannot|auth|guest|env|production|once|lang|choice|eval)\b/i',
        'a script'                  => '/<\s*script\b/i',
        'an embedded frame/object'  => '/<\s*(?:iframe|object|embed|applet)\b/i',
        'a javascript link'         => '/javascript\s*:/i',
        'an HTML page in a link'    => '/data\s*:\s*text\/html/i',
        'an inline event handler'   => '/<[^>]*\son[a-z]+\s*=/i',
    ];

    /**
     * @return string[] plain-language findings, each naming where it was found
     */
    public static function scan(mixed $node, string $path): array
    {
        $found = [];
        $walk = function (mixed $n, string $p) use (&$walk, &$found): void {
            if (is_string($n)) {
                foreach (self::RULES as $label => $regex) {
                    if (preg_match($regex, $n)) {
                        $found[] = "Contains {$label} (in {$p}).";
                    }
                }

                return;
            }
            if (is_array($n)) {
                foreach ($n as $k => $v) {
                    $walk($v, $p . '/' . $k);
                }
            }
        };
        $walk($node, $path);

        return $found;
    }

    /**
     * Role tokens must be plain words: they are written into generated view code.
     *
     * @return string[]
     */
    public static function checkRoleTokens(array $template): array
    {
        $bad = [];
        $check = function (mixed $list, string $where) use (&$bad): void {
            foreach ((array) $list as $token) {
                if (! is_string($token) || ! preg_match(self::ROLE_TOKEN, $token)) {
                    $bad[] = "A signer role in {$where} is not a plain word.";
                    return;
                }
            }
        };

        if (isset($template['signing_parties'])) {
            $check($template['signing_parties'], 'the signing order');
        }
        foreach (['field_mappings', 'editor_state'] as $col) {
            $maps = $col === 'editor_state' ? ($template['editor_state']['mappings'] ?? []) : ($template['field_mappings'] ?? []);
            foreach ((array) $maps as $tag => $m) {
                if (! is_array($m)) {
                    continue;
                }
                foreach (['parties', 'editable_by'] as $k) {
                    if (isset($m[$k]) && is_array($m[$k])) {
                        $check($m[$k], "field {$tag}");
                    }
                }
                if (isset($m['party']) && is_string($m['party']) && ! preg_match(self::ROLE_TOKEN, $m['party'])) {
                    $bad[] = "A signer role in field {$tag} is not a plain word.";
                }
            }
        }

        return array_values(array_unique($bad));
    }
}
