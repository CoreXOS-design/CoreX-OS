<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use Illuminate\Support\Facades\Log;

/**
 * ES-5 — Editable-at-Signing value projector.
 *
 * Problem this closes (see .ai/audits/es5-editable-at-signing-fix-2026-06-10.md):
 * values a recipient types into an editable field at signing time are posted
 * to the server (web_template_data['signing_field_values']) but were NEVER
 * projected back into the document HTML. The signing view serves the stored
 * merged_html / expanded body verbatim, and the filed PDF flattens
 * signed_paginated_html — whose `innerHTML` does not serialise live <input>
 * values. Result: every edited value was silently dropped from the document
 * the next signer saw AND from the final signed artifact.
 *
 * The projector is the single place that writes saved values back onto their
 * surfaces. It runs at two points:
 *   1. SigningController::show()      — over the EXPANDED body, so each signer
 *      sees prior signers' edits and their own field pre-fills.
 *   2. SigningController::completeWeb()— over signed_paginated_html (the filed
 *      artifact) and the canonical merged_html, so edits survive the flatten.
 *
 * SHARED KEYING — no parallel scheme. Values are keyed by the rendered
 * `data-field` attribute, which for a per-recipient clone is the mangled
 * `{name}__r{role_index}` form that RoleBlockExpansionService stamps
 * (mirroring SignatureRequest::role_identity / data-recipient-identity and the
 * §20.10 per-recipient loop). seller_1 → `seller_address__r1`, seller_2 →
 * `seller_address__r2`, so two recipients editing the "same" logical field
 * never collide. Non-cloned agent/legacy fields keep their plain `data-field`.
 *
 * Fail-open: any parse error returns the original HTML unchanged (parity with
 * SigningSurfaceResolver — never make a document worse than it was).
 */
class SigningFieldValueProjector
{
    /**
     * Project saved signing-time field values onto their `[data-field]`
     * surfaces.
     *
     * @param string $bodyHtml          Document body HTML (no <html>/<body> wrapper).
     * @param array  $valuesByFieldKey  Map of rendered data-field key → value.
     *                                  Value may be a scalar OR the B3 payload
     *                                  shape ['value' => ..., 'identity' => ...,
     *                                  'original_field' => ...].
     * @param bool   $bakeInputsToText  When true, an editable <input> carrying
     *                                  a stored value is replaced by a static
     *                                  <span> with the value as text — used for
     *                                  the filed artifact so the flattened PDF
     *                                  shows text, not an empty input box. When
     *                                  false (live signing render) inputs are
     *                                  left untouched; only data-field spans are
     *                                  filled, and the client re-derives any
     *                                  editable inputs from that text.
     */
    public function project(string $bodyHtml, array $valuesByFieldKey, bool $bakeInputsToText = false): string
    {
        if (trim($bodyHtml) === '' || empty($valuesByFieldKey)) {
            return $bodyHtml;
        }

        // Reduce every entry to a scalar string value, keyed by field key.
        $flat = [];
        foreach ($valuesByFieldKey as $key => $raw) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_array($raw)) {
                $val = $raw['value'] ?? '';
            } else {
                $val = $raw;
            }
            // Scalars only — never coerce arrays/objects into a node.
            if (is_array($val) || is_object($val)) {
                continue;
            }
            $flat[$key] = (string) $val;
        }
        if (empty($flat)) {
            return $bodyHtml;
        }

        try {
            $dom = new \DOMDocument();
            @$dom->loadHTML(
                '<?xml encoding="utf-8"?>' . $bodyHtml,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
            );
            $xpath = new \DOMXPath($dom);

            foreach ($xpath->query('//*[@data-field]') as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                $key = $node->getAttribute('data-field');
                if ($key === '' || !array_key_exists($key, $flat)) {
                    continue;
                }
                $value = $flat[$key];

                if (strtolower($node->nodeName) === 'input') {
                    // Live editable input. At completion (bakeInputsToText) the
                    // input is frozen to a text span so the flattened PDF shows
                    // the value. During live signing we set the value attribute
                    // so a re-served input is pre-filled, but never remove the
                    // input (the signer is still editing).
                    if ($bakeInputsToText) {
                        $this->replaceInputWithSpan($dom, $node, $value);
                    } else {
                        $node->setAttribute('value', $value);
                    }
                    continue;
                }

                // Span / other text-bearing element — replace its text content
                // while preserving the element and its attributes.
                while ($node->firstChild !== null) {
                    $node->removeChild($node->firstChild);
                }
                if ($value !== '') {
                    $node->appendChild($dom->createTextNode($value));
                }
            }

            $result = $dom->saveHTML();
            return trim((string) preg_replace('/^<\?xml encoding="utf-8"\?>/', '', $result));
        } catch (\Throwable $e) {
            Log::warning('SIGNING_FIELD_PROJECT_FAILED', ['error' => $e->getMessage()]);
            return $bodyHtml;
        }
    }

    /**
     * Replace an editable <input data-field> with a static <span> carrying the
     * value as text. Preserves the field's identity attributes so the surface
     * is still addressable/auditable, drops only the input chrome.
     */
    private function replaceInputWithSpan(\DOMDocument $dom, \DOMElement $input, string $value): void
    {
        $parent = $input->parentNode;
        if ($parent === null) {
            return;
        }

        $span = $dom->createElement('span');
        foreach (iterator_to_array($input->attributes) as $attr) {
            $name = strtolower($attr->name);
            // Drop input-only attributes; keep data-* identity + class so the
            // baked surface is indistinguishable from a server-rendered span.
            if (in_array($name, ['value', 'type', 'name', 'placeholder', 'data-viewer-editable'], true)) {
                continue;
            }
            $span->setAttribute($attr->name, $attr->value);
        }
        $span->setAttribute('data-field-baked', '1');
        if ($value !== '') {
            $span->appendChild($dom->createTextNode($value));
        }
        $parent->replaceChild($span, $input);
    }
}
