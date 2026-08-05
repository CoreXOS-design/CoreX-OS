<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\DocumentClauseStrikethrough;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * WET-INK SELECTION edit (Johan 2026-08-05, correct UX) — the agent HIGHLIGHTS the exact word / phrase /
 * clause anywhere in the rendered document (no clause numbers). The frontend sends the selected text plus a
 * little context (prefix/suffix) to disambiguate duplicates; this service locates that EXACT span in
 * merged_html and authors, INLINE where it sits:
 *
 *   <span class="change-inline" data-strikethrough-applied="1" data-change-id>
 *       <del class="change-del">{selected}</del> <ins class="change-ins">{replacement}</ins>
 *   </span>
 *   + a right-margin INITIAL BLOCK with one slot per signing party (wet-ink margin initials).
 *
 * The struck old text STAYS visible (wet-ink marked-up contract). Uses cc1's shared classes so styling is
 * one visual language and cc1's body-diff DEFERS (data-strikethrough-applied). data-change-id =
 * sha1(prefix|selected|replacement)[:12]. Every change is captured in web_template_data['pending_body_changes'].
 */
final class SelectionEditService
{
    public function __construct(private SignatureService $signatureService)
    {
    }

    public function strikeSelection(
        SignatureTemplate $template,
        string $selected,
        string $prefix,
        string $suffix,
        string $replacement,
        ?User $actor
    ): array {
        $document = $template->document;
        if (! $document) {
            return ['ok' => false, 'error' => 'Document not found.'];
        }
        $selected = trim($selected);
        if ($selected === '') {
            return ['ok' => false, 'error' => 'Highlight the text you want to change first.'];
        }
        if (trim($replacement) === '') {
            return ['ok' => false, 'error' => 'Enter the replacement text.'];
        }

        $wtd  = is_array($document->web_template_data) ? $document->web_template_data : [];
        $html = (string) ($wtd['merged_html'] ?? '');
        if (trim($html) === '') {
            return ['ok' => false, 'error' => 'Document has no editable body.'];
        }

        $dom = new \DOMDocument();
        if (! @$dom->loadHTML(
            '<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        )) {
            return ['ok' => false, 'error' => 'Could not parse the document body.'];
        }
        $xpath = new \DOMXPath($dom);

        // Locate the selected span: find the text node + offset whose text equals $selected AND whose
        // surrounding text best matches the sent context. Single-text-node selections (the common case:
        // a word/phrase inside a paragraph). Returns [textNode, offset] or null.
        $hit = $this->locate($dom, $selected, $prefix, $suffix);
        if ($hit === null) {
            return ['ok' => false, 'error' => 'Could not locate the highlighted text — try selecting it again.'];
        }
        [$node, $offset] = $hit;

        $changeId = substr(sha1($this->norm($prefix) . '|' . $selected . '|' . $replacement), 0, 12);

        $result = DB::transaction(function () use ($template, $document, $dom, $node, $offset, $selected, $replacement, $changeId, $actor, $wtd) {
            $this->authorInlineStrike($dom, $node, $offset, $selected, $replacement, $changeId, $template);

            DocumentClauseStrikethrough::create([
                'signature_template_id' => $template->id,
                'agency_id'             => $actor?->effectiveAgencyId(),
                'clause_ref'            => 'selection',           // selection-based; no printed clause ref
                'clause_original_text'  => $selected,
                'proposed_by_user_id'   => $actor?->id,
                'amendment_id'          => 0,
                'status'                => DocumentClauseStrikethrough::STATUS_PROPOSED,
            ]);

            $wtd['merged_html'] = $this->innerHtml($dom, $xpath = new \DOMXPath($dom));
            $changes = $wtd['pending_body_changes'] ?? [];
            $changes[] = [
                'change_id' => $changeId,
                'mode'      => 'selection',
                'old'       => $selected,
                'new'       => $replacement,
                'actor_id'  => $actor?->id,
                'actor_name'=> $actor?->name,
                'at'        => now()->toIso8601String(),
            ];
            $wtd['pending_body_changes'] = $changes;
            $wtd['amendment_render']     = true;
            $wtd['canonical_version']    = 0; // recompose so the strike shows on serve
            $document->update(['web_template_data' => $wtd]);

            return ['ok' => true, 'change_id' => $changeId, 'old' => $selected];
        });

        return $result;
    }

    /** @return array{0: \DOMText, 1: int}|null */
    private function locate(\DOMDocument $dom, string $selected, string $prefix, string $suffix): ?array
    {
        $xpath = new \DOMXPath($dom);
        $textNodes = $xpath->query('//text()');
        if ($textNodes === false) {
            return null;
        }

        $best = null;
        $bestScore = -1;
        $normSuffix = $this->norm($suffix);
        $normPrefix = $this->norm($prefix);

        foreach ($textNodes as $tn) {
            if (! $tn instanceof \DOMText) {
                continue;
            }
            $t = $tn->nodeValue ?? '';
            $from = 0;
            while (($pos = mb_strpos($t, $selected, $from)) !== false) {
                // Skip if this node is already inside an authored change mark.
                if ($this->insideChangeMark($tn)) {
                    $from = $pos + 1;
                    continue;
                }
                // Score by context match within the same node (cheap + robust for single-node selections).
                $before = $this->norm(mb_substr($t, max(0, $pos - 40), min(40, $pos)));
                $after  = $this->norm(mb_substr($t, $pos + mb_strlen($selected), 40));
                $score = 0;
                if ($normPrefix !== '' && str_ends_with($before, mb_substr($normPrefix, -20))) $score += 2;
                if ($normSuffix !== '' && str_starts_with($after, mb_substr($normSuffix, 0, 20))) $score += 2;
                if ($normPrefix === '' && $normSuffix === '') $score += 1; // no context → first match
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = [$tn, $pos];
                }
                $from = $pos + 1;
            }
        }
        return $best;
    }

    private function insideChangeMark(\DOMNode $node): bool
    {
        for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
            if ($p instanceof \DOMElement && $p->getAttribute('data-strikethrough-applied') === '1') {
                return true;
            }
        }
        return false;
    }

    /** Split $node at the selected span and author the inline strike + margin initial block in place. */
    private function authorInlineStrike(\DOMDocument $dom, \DOMText $node, int $offset, string $selected, string $replacement, string $changeId, SignatureTemplate $template): void
    {
        $full   = $node->nodeValue ?? '';
        $before = mb_substr($full, 0, $offset);
        $after  = mb_substr($full, $offset + mb_strlen($selected));

        $parent = $node->parentNode;
        if ($parent === null) {
            return;
        }

        $frag = [];
        if ($before !== '') {
            $frag[] = $dom->createTextNode($before);
        }

        $wrap = $dom->createElement('span');
        $wrap->setAttribute('class', 'change-inline');
        $wrap->setAttribute('data-strikethrough-applied', '1');
        $wrap->setAttribute('data-change-id', $changeId);

        $del = $dom->createElement('del');
        $del->setAttribute('class', 'change-del');
        $del->setAttribute('data-change-id', $changeId);
        $del->appendChild($dom->createTextNode($selected));
        $wrap->appendChild($del);
        $wrap->appendChild($dom->createTextNode(' '));
        $ins = $dom->createElement('ins');
        $ins->setAttribute('class', 'change-ins');
        $ins->setAttribute('data-change-id', $changeId);
        $ins->appendChild($dom->createTextNode($replacement));
        $wrap->appendChild($ins);

        // Right-margin INITIAL BLOCK — one slot per signing party (wet-ink margin initials).
        $margin = $dom->createElement('span');
        $margin->setAttribute('class', 'change-margin');
        $margin->setAttribute('data-change-id', $changeId);
        $margin->setAttribute('contenteditable', 'false');
        $label = $dom->createElement('span');
        $label->setAttribute('class', 'change-margin-label');
        $label->appendChild($dom->createTextNode('Initials'));
        $margin->appendChild($label);
        foreach ($this->parties($template) as $party) {
            $slot = $dom->createElement('span');
            $slot->setAttribute('class', 'cm-slot');
            $slot->setAttribute('data-party-key', (string) ($party['key'] ?? ''));
            $slot->setAttribute('data-party-name', (string) ($party['name'] ?? ''));
            $nameSpan = $dom->createElement('span');
            $nameSpan->setAttribute('class', 'cm-name');
            $nameSpan->appendChild($dom->createTextNode((string) ($party['name'] ?? 'Party')));
            $slot->appendChild($nameSpan);
            $ink = $dom->createElement('span');
            $ink->setAttribute('class', 'cm-ink');
            $ink->appendChild($dom->createTextNode(' ▢'));   // empty slot — fills when THIS party initials
            $slot->appendChild($ink);
            $margin->appendChild($slot);
        }
        $wrap->appendChild($margin);

        $frag[] = $wrap;
        if ($after !== '') {
            $frag[] = $dom->createTextNode($after);
        }

        foreach ($frag as $n) {
            $parent->insertBefore($n, $node);
        }
        $parent->removeChild($node);
    }

    /** @return array<int, array{key:string, name:string}> */
    private function parties(SignatureTemplate $template): array
    {
        $out = [];
        foreach ($template->requests()->orderBy('signing_order')->get() as $r) {
            $out[] = [
                'key'  => method_exists($r, 'canonicalPartyKey') ? $r->canonicalPartyKey() : (string) $r->party_role,
                'name' => (string) ($r->signer_name ?: ucfirst((string) $r->party_role)),
            ];
        }
        return $out;
    }

    /**
     * Fill ONE party's margin slot for a change (wet-ink: each party initials their OWN slot, independently).
     * Marks the cm-slot[data-party-key] under the change's margin as filled + writes the party's initials as
     * their ink. Idempotent. Returns the html unchanged when the slot isn't present.
     */
    public function fillMarginSlot(string $html, string $changeId, string $partyKey, string $name): string
    {
        if (trim($html) === '' || ! str_contains($html, 'data-change-id="' . $changeId . '"')) {
            return $html;
        }
        try {
            $dom = new \DOMDocument();
            @$dom->loadHTML(
                '<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
            $xpath = new \DOMXPath($dom);
            $slots = $xpath->query(
                '//*[contains(concat(" ", normalize-space(@class), " "), " change-margin ") and @data-change-id=' . $this->xpathLiteral($changeId) . ']'
                . '//*[contains(concat(" ", normalize-space(@class), " "), " cm-slot ") and @data-party-key=' . $this->xpathLiteral($partyKey) . ']'
            );
            if ($slots === false || $slots->length === 0) {
                return $html;
            }
            foreach ($slots as $slot) {
                if (! $slot instanceof \DOMElement) {
                    continue;
                }
                $cls = $slot->getAttribute('class');
                if (! str_contains(' ' . $cls . ' ', ' cm-filled ')) {
                    $slot->setAttribute('class', trim($cls . ' cm-filled'));
                }
                foreach (iterator_to_array($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " cm-ink ")]', $slot)) as $ink) {
                    while ($ink->firstChild) {
                        $ink->removeChild($ink->firstChild);
                    }
                    $ink->appendChild($dom->createTextNode(' ' . $this->initials($name)));
                }
            }
            return $this->innerHtml($dom, $xpath);
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /** True when the change's margin has a slot for this party (a selection mark). */
    public function hasMarginSlot(string $html, string $changeId, string $partyKey): bool
    {
        if (trim($html) === '' || ! str_contains($html, 'data-change-id="' . $changeId . '"')) {
            return false;
        }
        return preg_match('/data-party-key="' . preg_quote($partyKey, '/') . '"/', $html) === 1
            && str_contains($html, 'change-margin');
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $out = '';
        foreach ($parts as $p) {
            if ($p === '') continue;
            $out .= mb_strtoupper(mb_substr($p, 0, 1));
            if (mb_strlen($out) >= 3) break;
        }
        return $out !== '' ? $out : '✓';
    }

    private function xpathLiteral(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'{$value}'";
        }
        if (! str_contains($value, '"')) {
            return "\"{$value}\"";
        }
        return "concat('" . str_replace("'", "',\"'\",'", $value) . "')";
    }

    private function innerHtml(\DOMDocument $dom, \DOMXPath $xpath): string
    {
        $root = $xpath->query('//*[@id="__root"]')->item(0);
        if (! $root instanceof \DOMElement) {
            return (string) $dom->saveHTML();
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out;
    }

    private function norm(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }
}
