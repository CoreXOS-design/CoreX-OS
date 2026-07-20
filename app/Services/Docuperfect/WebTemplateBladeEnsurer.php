<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use App\Http\Controllers\Docuperfect\TemplateController;
use App\Models\Docuperfect\Template;
use Illuminate\Support\Facades\Log;

/**
 * Blank-preview regression fix (BUILD_STANDARD §3 — prevent-or-absorb).
 *
 * A web/CDS template renders via a GENERATED blade file
 * `resources/views/docuperfect/web-templates/cds/template-<id>.blade.php`, written on
 * save by `TemplateController::generateCdsBladeView()`. That file is an untracked,
 * generated artifact — if it goes missing (env cleanup, redeploy, live→QA sync), the
 * wizard preview renders nothing / throws `View [...] not found` and the pane goes blank.
 *
 * This service guarantees a web-template preview can NEVER blank on a missing artifact:
 *   1. ensure() — regenerate the blade on-demand from the template's STORED data
 *      (the same generation the save path runs), then it is renderable.
 *   2. renderOrFallback() — if regeneration OR render still fails, return a best-effort
 *      body from the stored HTML with a clear notice — never a silent blank.
 */
class WebTemplateBladeEnsurer
{
    /**
     * Ensure the template's generated blade file exists (regenerating from stored data
     * if missing) and return the blade_view name to render.
     */
    public function ensure(Template $template): string
    {
        $bladeView = (string) ($template->blade_view ?? '');
        if ($bladeView !== '' && $this->bladeFileExists($bladeView)) {
            return $bladeView;
        }
        return $this->regenerate($template);
    }

    /**
     * Force-regenerate the blade file from the template's stored data (editor_state
     * tagged_html + field_mappings + cds_json), reusing the EXACT save-path generator.
     * Persists blade_view. Returns the blade_view name. Also used to restore templates
     * whose generated blade went missing.
     */
    public function regenerate(Template $template): string
    {
        $editor   = $this->asArray($template->editor_state);
        $cds      = $this->asArray($template->cds_json);
        $mappings = $this->asArray($template->field_mappings);
        $tagged   = is_string($editor['tagged_html'] ?? null) && trim($editor['tagged_html']) !== ''
            ? $editor['tagged_html'] : null;
        $tags     = $this->asArray($editor['tags'] ?? []);

        $bladeView = app(TemplateController::class)->generateCdsBladeView(
            $cds,
            $mappings,
            (int) $template->id,
            (string) $template->name,
            $template->signing_parties,
            $tagged,
            $tags,
        );

        if ($bladeView !== (string) ($template->blade_view ?? '')) {
            $template->update(['blade_view' => $bladeView]);
        }

        return $bladeView;
    }

    /**
     * Render a web template's blade to HTML, guaranteeing it never comes back blank:
     * ensure the blade exists (regenerate if missing), render it; on ANY failure fall
     * back to best-effort HTML from stored data with a clear notice.
     */
    public function renderOrFallback(Template $template, array $viewData): string
    {
        try {
            $bladeView = $this->ensure($template);
            return view($bladeView, $viewData)->render();
        } catch (\Throwable $e) {
            Log::warning('WebTemplateBladeEnsurer: blade render failed — using stored-HTML fallback', [
                'template_id' => $template->id ?? null,
                'blade_view'  => $template->blade_view ?? null,
                'error'       => $e->getMessage(),
            ]);
            return $this->fallbackHtml($template);
        }
    }

    /** Best-effort, never-blank HTML from stored data when the blade cannot render. */
    public function fallbackHtml(Template $template): string
    {
        $editor = $this->asArray($template->editor_state);
        $body   = '';

        // 1) the builder's saved DOM (raw, tag-spans intact) — the best available body.
        if (is_string($editor['tagged_html'] ?? null) && trim($editor['tagged_html']) !== '') {
            $body = $editor['tagged_html'];
        } else {
            // 2) re-render from cds_json via the CDS renderer.
            try {
                $cds = $this->asArray($template->cds_json);
                if (!empty($cds)) {
                    $body = app(CdsRendererService::class)->render($cds);
                }
            } catch (\Throwable $inner) {
                $body = '';
            }
        }

        $notice = '<div style="background:#fff8e1;border:1px solid #f0c36d;padding:10px 14px;margin:0 0 12px;border-radius:6px;font-size:13px;color:#7a5900;">'
                . 'Preview shown from the saved document body — the formatted view could not be generated. Re-save the template to rebuild it.'
                . '</div>';

        if (trim($body) === '') {
            return $notice . '<div style="padding:16px;color:#666;">This template has no renderable stored content. Open it in the builder and save to rebuild its preview.</div>';
        }

        return $notice . $body;
    }

    /**
     * True only when the generated blade file is physically on disk. Deliberately does
     * NOT trust View::exists() — the file-view finder can cache a resolved path, so a
     * file deleted after first resolution would falsely report present, which is exactly
     * the missing-artifact case this fix exists for.
     */
    private function bladeFileExists(string $bladeView): bool
    {
        $path = resource_path('views/' . str_replace('.', '/', $bladeView) . '.blade.php');
        return is_file($path);
    }

    private function asArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
