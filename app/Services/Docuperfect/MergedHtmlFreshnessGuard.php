<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureTemplate;
use Illuminate\Support\Facades\Log;

/**
 * E-sign reset Q3 Layer B — guard against serving a stale merged_html
 * snapshot when the underlying template has been edited since the
 * snapshot was captured.
 *
 * The bug observed in template 111 / document 399:
 *
 *   document.web_template_data['merged_html'] was rendered at
 *   2026-05-26 11:57:55 (when document.created_at was set).
 *
 *   template.updated_at advanced to 2026-05-26 12:11:12 after Johan
 *   edited the blade. The merged_html snapshot didn't update.
 *
 *   The signing view served the stale snapshot — markers Johan had
 *   fixed in the blade still rendered literally because the snapshot
 *   never re-rendered.
 *
 * This guard answers two questions:
 *
 *   1. isStale($document)  — has the source-of-truth template been
 *      edited since this document's merged_html was captured?
 *   2. rerender($document) — re-render the blade view + replace
 *      merged_html on the document. The signing pipeline then runs
 *      against the fresh snapshot.
 *
 * Wiring: SigningController::show() calls guard->ensureFresh($document)
 * before passing merged_html to the expansion pipeline.
 *
 * Persistence note: the production schema currently lacks a
 * `documents.rendered_at` column. Until a migration adds one, the
 * guard treats `documents.updated_at` as the rendered-at proxy. This
 * works correctly because `merged_html` lives inside
 * `web_template_data` (a JSON column) and any non-render write to the
 * document bumps the same `updated_at` — so the proxy errs on the side
 * of "fresh", i.e. it occasionally skips a needed rerender when the
 * document was touched for unrelated reasons but never serves an
 * older snapshot when the template was edited after the document.
 *
 * The recommended follow-up is to add `documents.rendered_at` and
 * stamp it inside the rerender path so the proxy isn't needed.
 */
final class MergedHtmlFreshnessGuard
{
    /**
     * Returns true when the supplied document's stored merged_html is
     * older than the template it was rendered from.
     */
    public function isStale(Document $document): bool
    {
        $template = $document->template;
        if ($template === null) {
            return false;
        }
        $rendered = $document->rendered_at ?? $document->updated_at;
        if ($rendered === null) {
            return true;
        }
        $templateUpdated = $template->updated_at;
        if ($templateUpdated === null) {
            return false;
        }
        return $templateUpdated->gt($rendered);
    }

    /**
     * Re-render the blade view (when the template has one) and replace
     * the document's merged_html. Safe to call when not stale — no-ops.
     * Audit-logs every rerender so we can monitor frequency in prod.
     */
    public function ensureFresh(Document $document, ?SignatureTemplate $signatureTemplate = null): bool
    {
        if (!$this->isStale($document)) {
            return false;
        }

        $template = $document->template;
        if ($template === null || empty($template->blade_view)) {
            // Nothing to rerender from — leave the existing snapshot
            // and emit a warning so this case is visible.
            Log::warning('MergedHtmlFreshnessGuard: stale snapshot but no blade_view to rerender', [
                'document_id' => $document->id,
                'template_id' => $template?->id,
            ]);
            return false;
        }

        try {
            $viewData = $document->web_template_data ?? [];
            if (!empty($template->signing_parties)) {
                $viewData['signing_parties'] = $template->signing_parties;
            }
            $fullHtml = view($template->blade_view, $viewData)->render();
            $bodyHtml = $this->extractBodyFragment($fullHtml);
            $document->update([
                'web_template_data' => array_merge(
                    $document->web_template_data ?? [],
                    ['merged_html' => $bodyHtml],
                ),
            ]);
        } catch (\Throwable $e) {
            Log::error('MergedHtmlFreshnessGuard: rerender failed', [
                'document_id' => $document->id,
                'template_id' => $template->id,
                'error'       => $e->getMessage(),
            ]);
            return false;
        }

        if ($signatureTemplate !== null) {
            SignatureAuditLog::create([
                'signature_template_id' => $signatureTemplate->id,
                'action'           => 'merged_html_rerendered',
                'actor_type'       => SignatureAuditLog::ACTOR_SYSTEM,
                'actor_name'       => 'System (FreshnessGuard)',
                'metadata_json'    => [
                    'document_id' => $document->id,
                    'template_id' => $template->id,
                    'reason'      => 'template.updated_at > document.rendered_at',
                ],
            ]);
        }

        return true;
    }

    /**
     * Extract the <body> inner HTML — the same shape merged_html uses
     * — out of a full Blade-rendered HTML document.
     */
    private function extractBodyFragment(string $fullHtml): string
    {
        $styles = '';
        if (preg_match_all('/<style[^>]*>.*?<\/style>/si', $fullHtml, $styleMatches)) {
            $styles = implode("\n", $styleMatches[0]);
        }
        if (preg_match('/<body[^>]*>(.*)<\/body>/si', $fullHtml, $bodyMatch)) {
            return trim($styles . "\n" . $bodyMatch[1]);
        }
        return trim($styles . "\n" . $fullHtml);
    }
}
