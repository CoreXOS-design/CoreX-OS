<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Demo;

use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\Readiness\TemplateReadinessReport;
use App\Services\Docuperfect\WebTemplateBladeEnsurer;
use App\Support\Docuperfect\DocumentAgencyContext;
use Illuminate\Support\Facades\View;

/**
 * .ai/specs/esign-template-demo-preview.md §2 / §4 — renders ONE template with sample data for the demo view. READ ONLY: it never writes the
 * page file (a template whose page file is missing is shown from its saved text instead), never creates a document, flow, contact or file.
 * The page frame, letterhead and blanks come from the template's own compiled page (the same call the signing flow's renderer makes);
 * the paginator is the signing link's, run in the viewer's browser by the demo screen.
 */
final class TemplateDemoRenderer
{
    private const SIGNER_ALIASES = [
        'lessor' => 'owner_party', 'landlord' => 'owner_party', 'owner' => 'owner_party', 'owner_party' => 'owner_party', 'seller' => 'owner_party',
        'lessee' => 'acquiring_party', 'tenant' => 'acquiring_party', 'acquiring_party' => 'acquiring_party', 'buyer' => 'acquiring_party', 'purchaser' => 'acquiring_party',
        'agent' => 'agent',
    ];

    public function __construct(
        private readonly TemplateReadinessReport $readiness,
        private readonly TemplateDemoSample $sampler,
        private readonly WebTemplateBladeEnsurer $ensurer,
    ) {
    }

    /**
     * @return array{kind: string, html: string, fields: list<array<string,mixed>>, places: list<array<string,mixed>>, signers: list<array<string,mixed>>, frame: array<string,mixed>, notes: list<string>, pdf_pages: int, parties: list<array<string,string>>, counts: array<string,int>}
     */
    public function render(Template $t): array
    {
        $ctx = $this->readiness->context($t);
        $places = $this->places($ctx);
        $signers = $this->signers($ctx, $places);
        $parties = [];
        foreach ($ctx['declared'] as $canon => $label) {
            $parties[] = ['role' => (string) $label, 'label' => ucfirst(str_replace('_', ' ', (string) $label))];
        }
        $base = ['places' => $places, 'signers' => $signers, 'parties' => $parties, 'notes' => []];

        if ((string) $t->render_type === 'pdf') {
            return $base + [
                'kind' => 'pdf', 'html' => '', 'fields' => [], 'pdf_pages' => (int) $t->page_count,
                'frame' => ['header' => [], 'footer' => [], 'display' => (string) ($t->header_display ?: 'first_page')],
                'counts' => ['fields' => 0, 'empty' => 0, 'guessed' => 0, 'missing' => 0],
            ];
        }

        $sample = $this->sampler->build($t, $ctx);
        $viewData = $sample['values'] + [
            'recipients_by_role' => $sample['recipients_by_role'],
            'document_context' => 'rental',
        ];
        if (! empty($t->signing_parties)) {
            $viewData['signing_parties'] = $t->signing_parties;
        }

        $notes = [];
        $pageFileExists = is_string($t->blade_view) && $t->blade_view !== '' && View::exists($t->blade_view);
        if ($pageFileExists) {
            $html = DocumentAgencyContext::with($t->agency_id ? (int) $t->agency_id : null, null, fn () => $this->ensurer->renderOrFallback($t, $viewData));
        } else {
            $html = $this->ensurer->fallbackHtml($t);
            $notes[] = 'This template\'s page file has not been built yet, so the saved text is shown without the blanks filled. Open the template in the builder and Save to build it.';
        }

        [$html, $fields, $counts] = $this->annotate($html, $sample['fields']);

        $base['notes'] = $notes;

        return $base + [
            'kind' => 'web', 'html' => $html, 'fields' => $fields, 'pdf_pages' => 0, 'counts' => $counts,
            'frame' => $this->frame($ctx, $sample['frame'], $t),
        ];
    }

    /** Mark each blank's span with where its value came from; find the ones that rendered empty or are missing from the page. */
    private function annotate(string $html, array $fields): array
    {
        $bySource = [];
        foreach ($fields as $f) {
            $bySource[$f['var']] = $f['source'];
        }
        $present = [];
        $emptyVars = [];
        $html = (string) preg_replace_callback(
            '/<span class="corex-field-value" data-field="([^"]*)">(.*?)<\/span>/s',
            function ($m) use ($bySource, &$present, &$emptyVars) {
                $var = html_entity_decode($m[1]);
                $present[$var] = true;
                $inner = trim(strip_tags($m[2]));
                if ($inner === '') {
                    $emptyVars[$var] = true;

                    return '<span class="corex-field-value" data-field="' . $m[1] . '" data-demo-source="empty">' . $m[2] . '</span>';
                }
                $src = $bySource[$var] ?? 'guessed';

                return '<span class="corex-field-value" data-field="' . $m[1] . '" data-demo-source="' . e($src) . '">' . $m[2] . '</span>';
            },
            $html
        );

        $counts = ['fields' => count($fields), 'empty' => 0, 'guessed' => 0, 'missing' => 0];
        foreach ($fields as &$f) {
            if (isset($emptyVars[$f['var']])) {
                $f['status'] = 'empty';
                $counts['empty']++;
            } elseif (empty($present[$f['var']]) && $f['position'] !== 9999) {
                $f['status'] = 'missing';
                $counts['missing']++;
            } else {
                $f['status'] = $f['source'];
                if ($f['source'] !== TemplateDemoSample::BOUND) {
                    $counts['guessed']++;
                }
            }
        }
        unset($f);

        return [$html, $fields, $counts];
    }

    /** The place list the readiness report reads, with each place resolved against the declared signers. */
    private function places(array $ctx): array
    {
        $out = [];
        foreach ($ctx['places'] as $p) {
            $resolved = [];
            foreach ($p['names'] as $n) {
                $canon = $this->canon($n);
                $resolved[] = ['written' => $n, 'declared' => $ctx['declared'][$canon] ?? null];
            }
            $out[] = ['kind' => $p['kind'], 'ref' => $p['ref'], 'names' => $resolved, 'ok' => $resolved !== [] && ! in_array(null, array_column($resolved, 'declared'), true)];
        }

        return $out;
    }

    /** Each declared signer with how many signature and initial places they have (0 signature places = red). */
    private function signers(array $ctx, array $places): array
    {
        $out = [];
        foreach ($ctx['declared'] as $canon => $label) {
            $sig = 0;
            $ini = 0;
            foreach ($places as $p) {
                foreach ($p['names'] as $n) {
                    if (($n['declared'] ?? null) === $label) {
                        $p['kind'] === 'initial' ? $ini++ : $sig++;
                    }
                }
            }
            $out[] = ['label' => (string) $label, 'canon' => $canon, 'signatures' => $sig, 'initials' => $ini, 'standard_block' => $ctx['block'] === \App\Support\Docuperfect\SignatureBlock::COREX];
        }

        return $out;
    }

    private function frame(array $ctx, array $tokens, Template $t): array
    {
        $fill = function (string $line) use ($tokens): string {
            return (string) preg_replace_callback('/\{([A-Za-z_]+)\}/', fn ($m) => $tokens[strtoupper($m[1])] ?? $m[0], $line);
        };
        $collect = function (string $key) use ($ctx, $fill): array {
            $lines = [];
            foreach ((array) ($ctx['cds'][$key]['lines'] ?? []) as $l) {
                $text = trim((string) ($l['text'] ?? ''));
                $right = trim((string) ($l['right'] ?? ''));
                $line = trim($text . ($right !== '' ? '  —  ' . $right : ''));
                if ($line !== '') {
                    $lines[] = $fill($line);
                }
            }

            return $lines;
        };

        return ['header' => $collect('page_header'), 'footer' => $collect('page_footer'), 'display' => (string) ($t->header_display ?: 'first_page')];
    }

    private function canon(string $token): string
    {
        $k = preg_replace('/_\d+$/', '', str_replace([' ', '-'], '_', mb_strtolower(trim($token))));

        return self::SIGNER_ALIASES[$k] ?? $k;
    }
}
