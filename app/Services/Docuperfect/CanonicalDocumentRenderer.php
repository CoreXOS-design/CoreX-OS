<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use Illuminate\Support\Facades\Log;

/**
 * ESIGN-WETINK Phase 1a — the ONE renderer.
 *
 * Composes the signing document to its canonical, FULLY-EXPANDED, VIEWER-AGNOSTIC
 * HTML exactly once (at finalise/send = v0). This is the artifact the wet-ink
 * doctrine requires: every surface DISPLAYS it verbatim; nothing re-runs the
 * expansion pipeline at display time (that re-render-per-surface is the defect
 * class behind the "combined on one screen, per-seller on another" divergence).
 *
 * VIEWER-AGNOSTIC on purpose: no currentViewer editability stamp, no per-token
 * add-condition wiring. Those are per-viewer DISPLAY overlays applied on top of
 * the stored artifact at show()-time — they are NOT part of the canonical
 * document. Keeping them out is what makes the artifact identical for everyone.
 *
 * Spec: .ai/specs/ESIGN-WETINK.md §2 (canonical pipeline), §1 I1/I2.
 *
 * NOTE (Phase 1a scope): this composes + is intended for storage as
 * web_template_data['canonical_html']. Rewiring show()/templatePages()/sign()/PDF
 * to SERVE it (and moving the per-viewer affordances to display) is Phase 1b;
 * baking ink into it by data-recipient-identity is Phase 1c. This class is the
 * single-renderer spine those phases build on.
 */
class CanonicalDocumentRenderer
{
    /**
     * Compose the canonical, fully-expanded, viewer-agnostic document HTML.
     * Returns '' when the template has no web body to compose.
     */
    public function compose(SignatureTemplate $template): string
    {
        $document = $template->document;
        if (! $document) {
            return '';
        }
        $webData = $document->web_template_data ?? [];
        $html = (string) ($webData['merged_html'] ?? '');
        if (trim($html) === '') {
            return '';
        }

        $docTemplate = $document->template;

        // 1) Surface normalisation (make every web template signable).
        $html = SignatureSurfaceNormalizer::normalize($html);

        // 2) Letterhead — resolve to the CURRENT agency once, at compose time.
        $html = LetterheadRefresher::refresh($html);

        // 3) Insertable blocks — VIEWER-AGNOSTIC context (structural slots only,
        //    no per-token recipient add-condition wiring; that is a display
        //    overlay in Phase 1b).
        $blocksMeta = $docTemplate?->insertable_blocks ?? [];
        $html = app(InsertableBlockRenderer::class)->renderInDocument(
            $html,
            $template,
            is_array($blocksMeta) ? $blocksMeta : [],
            InsertableBlockRenderer::CONTEXT_AGENT_PREPARATION,
            null,
            null,
        );

        // 4) Role-block expansion — ONCE, against the REAL recipient set, with
        //    NO currentViewer (so no per-viewer editability stamp is baked). The
        //    output is fully expanded + identity-stamped (data-recipient-identity)
        //    so every same-party recipient has a STABLE, distinct instance for
        //    ink to attach to (the un-expanded merged_html could not — see the
        //    ESIGN-WETINK gap audit finding (b)).
        $recipients = SignatureRequest::where('signature_template_id', $template->id)
            ->orderBy('signing_order')
            ->get();
        $fieldMappings = is_array($docTemplate?->field_mappings ?? null)
            ? $docTemplate->field_mappings
            : [];

        $html = app(RoleBlockExpansionService::class)->expandWithLooping(
            $docTemplate,
            $html,
            $recipients,
            null, // viewer-agnostic
            $fieldMappings,
        );

        return $html;
    }

    /**
     * ESIGN-WETINK Phase 1b — THE display read for EVERY surface that shows the
     * document (recipient ceremony, agent sign, marker setup, agent review,
     * amendment review, print/PDF preview).
     *
     * Returns the stored `canonical_html` when present (the immutable post-send
     * artifact — the frozen truth every party signed). When absent — a document
     * still being PREPARED (pre-send: the agent is on the setup/markers/sign
     * screens and no canonical has been composed yet) — it composes ONE fresh via
     * the exact same pipeline (`compose()` → normalize → letterhead → insertable →
     * expandWithLooping), WITHOUT storing.
     *
     * Why no store on the pre-send path: during preparation `merged_html` is still
     * changing (the agent is editing fields), so a stored snapshot would go stale
     * between screens. Composing fresh each load means EVERY prep surface renders
     * from the same current inputs through the same code — so setup, sign, review
     * and the eventual ceremony are BYTE-IDENTICAL by construction, not by
     * coincidence. That is the whole point of the wet-ink "one document" rule: the
     * seller domicilium (and every other role-block) can no longer compose one way
     * on the markers screen and another way in the ceremony, because there is now
     * exactly ONE composition path and all surfaces call it.
     *
     * Returns '' when the template has no composable web body (caller keeps its
     * page-image/PDF path).
     */
    public function forDisplay(SignatureTemplate $template): string
    {
        $document = $template->document;
        if (! $document) {
            return '';
        }
        $webData = $document->web_template_data ?? [];
        $stored  = (string) ($webData['canonical_html'] ?? '');
        $version = (int) ($webData['canonical_version'] ?? 0);

        // Ink baked (version >= 1) → the stored canonical is the accumulated source
        // of truth (every prior party's signatures/initials/fills are composed into
        // it); return it verbatim so the agent-review and every later party see the
        // exact accumulated document.
        if (trim($stored) !== '' && $version >= 1) {
            return $stored;
        }

        // NOT yet baked (version 0 / no ink, or never composed) → RE-COMPOSE fresh so
        // the structure always reflects the CURRENT pipeline (per-recipient
        // attestation split, uniform ink, etc.). A stored v0 can be stale — composed
        // before a structural fix landed — and because nothing is baked into it yet,
        // re-composing loses nothing and keeps every surface (setup, sign, ceremony,
        // AGENT-REVIEW) on the one current spine. This is why the review must call
        // forDisplay: it renders the same accumulated/current canonical, never an
        // outdated snapshot.
        return $this->compose($template);
    }

    /**
     * AT-324/AT-325 / doc-452 — render the captured PAGE-BREAK INITIALS for a
     * post-signing display surface (agent review/approval, and the signed PDF).
     *
     * Page-break initials are a PAGINATION-time artifact: they live at each page
     * boundary of the paginated DOM and are therefore ABSENT from the un-paginated
     * canonical_html that review()/the PDF render. So the ink captured for them
     * (stored in web_template_data['signed_initials'], keyed
     * "{recipientKey}-init-{page}") had nowhere to show — the prior recipient's
     * initials simply vanished from the rendered document.
     *
     * This returns a labelled block of every captured initial image, attributed to
     * the recipient who signed it (name resolved via partyProgress()'s canonical
     * keys — so a 2nd co-seller's initials are credited to the right person).
     * Returns '' when nothing was captured. Callers APPEND it to the display HTML
     * so captured initials always show; the stored canonical is never mutated.
     */
    public function renderCapturedInitials(SignatureTemplate $template): string
    {
        $document = $template->document;
        if (! $document) {
            return '';
        }
        $signed = $document->web_template_data['signed_initials'] ?? [];
        if (! is_array($signed) || $signed === []) {
            return '';
        }

        $progress = $template->partyProgress();

        // signed_initials shape: { baseRole: { "{recipientKey}-init-{n}": dataUri } }.
        // Regroup by the recipientKey embedded in the leaf key so N same-role
        // signers stay distinct and attributed to their own name.
        $byRecipient = [];
        foreach ($signed as $group) {
            if (! is_array($group)) {
                continue;
            }
            foreach ($group as $key => $dataUri) {
                if (! is_string($dataUri) || strpos($dataUri, 'data:image') !== 0) {
                    continue;
                }
                if (preg_match('/^(.*)-init-(\d+)$/', (string) $key, $mm)) {
                    $recipientKey = $mm[1];
                    $page = (int) $mm[2] + 1;
                } else {
                    $recipientKey = (string) $key;
                    $page = null;
                }
                $byRecipient[$recipientKey][] = ['img' => $dataUri, 'page' => $page];
            }
        }
        if ($byRecipient === []) {
            return '';
        }

        $rows = '';
        foreach ($byRecipient as $recipientKey => $items) {
            $name = $progress[$recipientKey]['name']
                ?? ucfirst(str_replace('_', ' ', (string) $recipientKey));
            $imgs = '';
            foreach ($items as $it) {
                $pageLabel = $it['page'] !== null ? ('Page ' . $it['page']) : '';
                $imgs .= '<div style="display:inline-flex;flex-direction:column;align-items:center;margin:0 10px 8px 0;">'
                    . '<img src="' . e($it['img']) . '" alt="initial" style="width:64px;height:32px;object-fit:contain;border:1px solid #cbd5e1;border-radius:2px;background:#fff;" />'
                    . '<span style="font-size:8px;color:#94a3b8;margin-top:2px;">' . e($pageLabel) . '</span>'
                    . '</div>';
            }
            $rows .= '<div style="margin:6px 0;">'
                . '<div style="font-size:11px;font-weight:600;color:#334155;margin-bottom:4px;">' . e($name) . '</div>'
                . '<div style="display:flex;flex-wrap:wrap;align-items:flex-end;">' . $imgs . '</div>'
                . '</div>';
        }

        return '<div class="corex-captured-initials" style="margin-top:24px;padding:16px;border:1px solid #e2e8f0;border-radius:4px;background:#f8fafc;page-break-inside:avoid;">'
            . '<div style="font-size:12px;font-weight:700;color:#1e293b;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:10px;">Initials captured</div>'
            . $rows
            . '</div>';
    }

    /**
     * ESIGN-WETINK Phase 1b — resolve the canonical artifact for a display
     * surface. Returns the stored `canonical_html` when present (the wet-ink
     * happy path: composed once at send, served verbatim everywhere). When
     * absent — a pre-1a document, or a send that pre-dated this build — it is
     * composed on the fly AND back-filled to storage so the next surface reads
     * it directly and every surface for the rest of this document's life sees
     * the identical artifact.
     *
     * Returns '' when the template has no composable web body (caller falls
     * back to its legacy path — canonical serve never regresses a template
     * that cannot be composed, e.g. a pure page-image PDF template).
     */
    public function resolveOrCompose(SignatureTemplate $template): string
    {
        $document = $template->document;
        if (! $document) {
            return '';
        }
        $webData  = $document->web_template_data ?? [];
        $existing = (string) ($webData['canonical_html'] ?? '');
        if (trim($existing) !== '') {
            return $existing;
        }
        // Back-fill: compose once, persist, return. Non-fatal on failure.
        $html = $this->compose($template);
        if ($html === '') {
            return '';
        }
        try {
            $webData['canonical_html'] = $html;
            $webData['canonical_version'] ??= 0;
            $document->update(['web_template_data' => $webData]);
        } catch (\Throwable $e) {
            Log::warning('CanonicalDocumentRenderer::resolveOrCompose back-fill store failed (non-fatal)', [
                'template_id' => $template->id,
                'error'       => $e->getMessage(),
            ]);
        }
        return $html;
    }

    /**
     * Compose and persist the canonical artifact (v0) onto the document as
     * web_template_data['canonical_html']. Non-fatal — never blocks the send.
     */
    public function composeAndStore(SignatureTemplate $template): void
    {
        try {
            $html = $this->compose($template);
            if ($html === '') {
                return;
            }
            $document = $template->document;
            $webData = $document->web_template_data ?? [];
            $webData['canonical_html'] = $html;
            $webData['canonical_version'] = 0; // v0 — agent-prepared (ESIGN-WETINK I4)
            $document->update(['web_template_data' => $webData]);
        } catch (\Throwable $e) {
            Log::warning('CanonicalDocumentRenderer::composeAndStore failed (non-fatal)', [
                'template_id' => $template->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
