<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\DocumentClauseStrikethrough;
use App\Models\Docuperfect\DocumentCondition;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * WET-INK clause editing (cc6 flow half) — esign-returned-doc-edit-flow.md §4.1, Johan's change-authoring:
 *
 *  SMALL change → strike the old clause text inline + write the new text right after it, IN merged_html.
 *  BIG change   → strike the clause + create an Other-Conditions entry (#N) holding the full replacement,
 *                 and stamp `data-oc-ref="N"` on the struck clause with a "See Other Conditions — clause N"
 *                 cross-reference.
 *
 * The strike-out + write-in are authored as REAL document content in merged_html and STAY VISIBLE on the
 * final wet-ink marked-up contract (never cleared). They use cc1's shared callout classes
 * (`change-del`/`change-ins`/`change-xref`) so cc1's CSS styles them as one visual language, and carry
 * `data-strikethrough-applied="1"` so cc1's auto body-diff DEFERS to them (no double-marking — cc1 §7.5).
 * Each mark carries a stable `data-change-id` (cc1 convention: sha1(clause_ref|old|new)[:12]) so a
 * per-change initial attaches via `condition_initials`.
 *
 * Every change is captured structured in `web_template_data['pending_body_changes'][]` (old→new, mode,
 * change_id, actor, at) — the audit Johan requires — and the doc is flagged `amendment_render` for cc1.
 *
 * Clause elements are located by their `data-clause-ref` anchor (the existing strikethrough/OC anchor).
 */
final class ClauseEditService
{
    public function __construct(private SignatureService $signatureService)
    {
    }

    /** SMALL change: struck old text + inline new text, authored into merged_html. */
    public function editClauseInline(SignatureTemplate $template, string $clauseRef, string $newText, ?User $actor): array
    {
        return $this->applyClauseEdit($template, $clauseRef, $newText, 'inline', $actor);
    }

    /** BIG change: strike the clause + Other-Conditions entry (#N) + data-oc-ref cross-reference. */
    public function routeClauseToOtherConditions(SignatureTemplate $template, string $clauseRef, string $replacementText, ?User $actor): array
    {
        return $this->applyClauseEdit($template, $clauseRef, $replacementText, 'reference', $actor);
    }

    /**
     * @param 'inline'|'reference' $mode
     */
    private function applyClauseEdit(SignatureTemplate $template, string $clauseRef, string $newText, string $mode, ?User $actor): array
    {
        $document = $template->document;
        if (! $document) {
            return ['ok' => false, 'error' => 'Document not found.'];
        }
        $wtd  = is_array($document->web_template_data) ? $document->web_template_data : [];
        $html = (string) ($wtd['merged_html'] ?? '');
        if (trim($html) === '') {
            return ['ok' => false, 'error' => 'Document has no editable body.'];
        }

        // Locate the clause element by its data-clause-ref anchor.
        $dom = new \DOMDocument();
        $loaded = @$dom->loadHTML(
            '<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        if (! $loaded) {
            return ['ok' => false, 'error' => 'Could not parse the document body.'];
        }
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//*[@data-clause-ref=' . $this->xpathLiteral($clauseRef) . ']');
        if ($nodes === false || $nodes->length === 0) {
            return ['ok' => false, 'error' => "Clause {$clauseRef} was not found in the document."];
        }
        /** @var \DOMElement $el */
        $el = $nodes->item(0);

        // Guard: refuse to re-strike an already-struck clause (idempotent, no double marks).
        if ($el->getAttribute('data-strikethrough-applied') === '1') {
            return ['ok' => false, 'error' => "Clause {$clauseRef} has already been amended."];
        }

        $oldText  = trim(preg_replace('/\s+/', ' ', $el->textContent) ?? '');
        $changeId = substr(sha1($clauseRef . '|' . $oldText . '|' . $newText), 0, 12);

        [$condition, $ocNumber] = [null, null];

        $result = DB::transaction(function () use ($template, $document, $dom, $el, $clauseRef, $oldText, $newText, $mode, $changeId, $actor, &$condition, &$ocNumber, $wtd, $xpath) {
            if ($mode === 'reference') {
                // Create the Other-Conditions entry holding the full replacement.
                $next = (int) DocumentCondition::query()
                    ->where('signature_template_id', $template->id)
                    ->where('block_id', 'other_conditions')
                    ->whereNull('superseded_at')
                    ->max('condition_number');
                $ocNumber  = $next + 1;
                $condition = DocumentCondition::create([
                    'signature_template_id' => $template->id,
                    'agency_id'             => $actor?->effectiveAgencyId(),
                    'block_id'              => 'other_conditions',
                    'block_purpose'         => 'other_conditions',
                    'condition_number'      => $ocNumber,
                    'content'               => sprintf('As per clause %s: %s', $clauseRef, $newText),
                    'is_override'           => true,
                    'overrides_clause_ref'  => $clauseRef,
                    'added_by_user_id'      => $actor?->id,
                    'added_via'             => 'agent_signing',
                    'source'                => 'custom',
                ]);
            }

            // Author the wet-ink markup into the clause element.
            $this->markStruck($dom, $el, $oldText, $newText, $mode, $changeId, $ocNumber);

            // Audit row (also lets a per-change initial attach + the change summary count it).
            DocumentClauseStrikethrough::create([
                'signature_template_id'    => $template->id,
                'agency_id'                => $actor?->effectiveAgencyId(),
                'clause_ref'               => $clauseRef,
                'clause_original_text'     => $oldText,
                'replacement_condition_id' => $condition?->id,
                'proposed_by_user_id'      => $actor?->id,
                'amendment_id'             => 0,
                'status'                   => DocumentClauseStrikethrough::STATUS_PROPOSED,
            ]);

            // Persist the mutated merged_html.
            $wtd['merged_html'] = $this->innerHtml($dom, $xpath);

            // Structured change capture (old→new) — Johan's audit requirement.
            $changes   = $wtd['pending_body_changes'] ?? [];
            $changes[] = [
                'change_id' => $changeId,
                'clause_ref'=> $clauseRef,
                'mode'      => $mode,               // 'inline' | 'reference'
                'old'       => $oldText,
                'new'       => $newText,
                'oc_ref'    => $ocNumber,           // null for inline
                'actor_id'  => $actor?->id,
                'actor_name'=> $actor?->name,
                'at'        => now()->toIso8601String(),
            ];
            $wtd['pending_body_changes'] = $changes;
            $wtd['amendment_render']     = true;    // cc1 field-diff flag (clause marks are content already)

            $document->update(['web_template_data' => $wtd]);

            return ['ok' => true, 'change_id' => $changeId, 'mode' => $mode, 'oc_ref' => $ocNumber, 'old' => $oldText];
        });

        return $result;
    }

    /**
     * Author the wet-ink markup into $el, replacing its children:
     *   inline    → <del …>old</del> <ins …>new</ins>
     *   reference → <del … data-oc-ref=N>old</del> <span change-xref>See Other Conditions — clause N</span>
     * cc1 classes + data-strikethrough-applied (cc1 defers) + data-change-id (initial attach).
     */
    private function markStruck(\DOMDocument $dom, \DOMElement $el, string $oldText, string $newText, string $mode, string $changeId, ?int $ocNumber): void
    {
        while ($el->firstChild) {
            $el->removeChild($el->firstChild);
        }
        $el->setAttribute('data-strikethrough-applied', '1');
        $el->setAttribute('data-change-id', $changeId);

        $del = $dom->createElement('del');
        $del->setAttribute('class', 'change-del change-clause');
        $del->setAttribute('data-change-id', $changeId);
        if ($mode === 'reference' && $ocNumber !== null) {
            $del->setAttribute('data-oc-ref', (string) $ocNumber);
        }
        $del->appendChild($dom->createTextNode($oldText));
        $el->appendChild($del);
        $el->appendChild($dom->createTextNode(' '));

        if ($mode === 'reference' && $ocNumber !== null) {
            $xref = $dom->createElement('span');
            $xref->setAttribute('class', 'change-xref');
            $xref->setAttribute('data-change-id', $changeId);
            $xref->setAttribute('data-oc-ref', (string) $ocNumber);
            $xref->appendChild($dom->createTextNode('See Other Conditions — clause ' . $ocNumber));
            $el->appendChild($xref);
        } else {
            $ins = $dom->createElement('ins');
            $ins->setAttribute('class', 'change-ins');
            $ins->setAttribute('data-change-id', $changeId);
            $ins->appendChild($dom->createTextNode($newText));
            $el->appendChild($ins);
        }
    }

    /** Serialise the inner HTML of the #__root wrapper (strip the wrapper we added). */
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

    /** XPath string literal that survives quotes in the value. */
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
}
