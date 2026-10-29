<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Demo;

use App\Http\Controllers\Docuperfect\TemplateController;
use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\Demo\Contexts\DemoContext;
use App\Services\Docuperfect\Demo\Contexts\DemoContextResolver;
use App\Services\Rentals\LeaseAgreementTemplateGuard;
use Carbon\Carbon;

/**
 * .ai/specs/esign-template-demo-preview.md §3 — believable sample values for every blank of a template, and where each one came from:
 *   bound        the blank is linked to a named field / lease value and the sample knows that thing
 *   known        the exact merge-field name is one the sample (or the document's DemoContext) supplies - filled, not flagged
 *   guessed      matched by the blank's name or label (flagged amber in the demo)
 *   placeholder  "Sample: <label>" because nothing matched (flagged amber)
 * Pure: reads the template and config('template-demo-sample'), writes nothing, never throws on odd data.
 */
final class TemplateDemoSample
{
    public const BOUND = 'bound';
    public const KNOWN = 'known';
    public const GUESSED = 'guessed';
    public const PLACEHOLDER = 'placeholder';

    /**
     * @param  array<string,mixed>  $ctx  TemplateReadinessReport::context($template)
     * @return array{values: array<string,string>, fields: list<array<string,mixed>>, recipients_by_role: array<string,list<array<string,string>>>, frame: array<string,string>, context: array<string,mixed>}
     */
    public function build(Template $t, array $ctx): array
    {
        $cfg = (array) config('template-demo-sample', []);
        $context = app(DemoContextResolver::class)->for($t);
        $start = Carbon::now()->addMonthNoOverflow()->startOfMonth();
        $dates = [
            'lease_start' => $start->format('j F Y'),
            'lease_end' => $start->copy()->addMonths((int) ($cfg['lease']['months'] ?? 12))->subDay()->format('j F Y'),
            'property_lease_end_date' => $start->copy()->addMonths((int) ($cfg['lease']['months'] ?? 12))->subDay()->format('j F Y'),
        ];

        // Blanks with their binding (the same variable name the compiled page uses).
        $nfIds = [];
        foreach ((array) ($ctx['mappings'] ?? []) as $m) {
            if (is_array($m) && ! empty($m['namedFieldId'])) {
                $nfIds[] = (int) $m['namedFieldId'];
            }
        }
        $namedFields = $nfIds === [] ? collect() : NamedField::withoutGlobalScopes()->whereIn('id', array_unique($nfIds))->get()->keyBy('id');

        $rows = [];
        foreach ((array) ($ctx['tags'] ?? []) as $i => $tag) {
            if (in_array((string) ($tag['type'] ?? ''), ['signature', 'initial'], true)) {
                continue;
            }
            $m = $ctx['mappings'][$tag['id'] ?? ''] ?? null;
            if (! is_array($m)) {
                continue;
            }
            $var = $this->varName($m, $namedFields);
            if ($var === null || $var === '') {
                continue;
            }
            $label = trim((string) ($m['label'] ?? $m['manualLabel'] ?? '')) ?: $this->humanise($var);
            $nf = ! empty($m['namedFieldId']) ? ($namedFields[(int) $m['namedFieldId']] ?? null) : null;
            $binding = $nf ? 'Named field: ' . $nf->name : (($m['mappingType'] ?? '') === 'manual' ? 'Typed by hand while preparing' : (! empty($m['fieldGroupId']) ? 'A field group' : 'Linked'));
            $rows[$var] ??= ['position' => $i + 1, 'var' => $var, 'label' => $label, 'binding' => $binding, 'bound' => $nf !== null];
        }
        // Names the page uses that no blank row explains (e.g. a stale page file, or a lease value): still filled, still listed.
        try {
            foreach (app(LeaseAgreementTemplateGuard::class)->fieldNamesFor($t) as $name) {
                $rows[$name] ??= ['position' => 9999, 'var' => $name, 'label' => $this->humanise($name), 'binding' => 'On the page', 'bound' => false];
            }
        } catch (\Throwable) {
            // the page source could not be read: the rows from the template's own blanks are still shown
        }

        $values = [];
        $fields = [];
        foreach ($rows as $var => $r) {
            [$value, $source] = $this->resolve($var, $r['label'], (bool) $r['bound'], $cfg, $dates, $context);
            $values[$var] = $value;
            $fields[] = $r + ['value' => $value, 'source' => $source];
        }
        // The context's own values for names the page uses that no blank row lists (still filled).
        foreach ($context->vars() as $name => $value) {
            $values[$name] ??= $value;
        }
        // The frame tokens' variables (TemplateController::frameMergeFieldsToBlade) and the lease dates are always supplied.
        foreach (['property_address', 'lessee_last_name', 'seller_last_name', 'lease_start'] as $frameVar) {
            $values[$frameVar] ??= (string) ($dates[$frameVar] ?? ($cfg['vars'][$frameVar] ?? ''));
        }

        $people = (array) ($cfg['people'] ?? []);
        $recipients = [];
        foreach (['landlord', 'tenant', 'agent', 'seller', 'buyer'] as $role) {
            if (isset($people[$role])) {
                $recipients[$role] = [['name' => (string) $people[$role]['name'], 'email' => (string) $people[$role]['email']]];
            }
        }

        usort($fields, fn ($a, $b) => [$a['position'], $a['var']] <=> [$b['position'], $b['var']]);

        return [
            'context' => ['key' => $context->key(), 'label' => $context->label(), 'document_context' => $context->documentContext($t), 'parties' => $context->parties()],
            'values' => $values,
            'fields' => $fields,
            'recipients_by_role' => $recipients,
            'frame' => [
                'PROPERTY' => (string) ($cfg['vars']['property_address'] ?? ''),
                'SURNAME' => (string) ($cfg['vars']['lessee_last_name'] ?? ''),
                'YEAR' => $start->format('Y'),
                'PAGE' => '1',
            ],
        ];
    }

    /** @return array{0: string, 1: string} value and source */
    private function resolve(string $var, string $label, bool $bound, array $cfg, array $dates, DemoContext $context): array
    {
        // The document's own kind first: a seller's name in a mandate, a lessee's in a lease.
        $own = $context->vars();
        if (array_key_exists($var, $own)) {
            return [(string) $own[$var], $bound ? self::BOUND : self::KNOWN];
        }
        if (array_key_exists($var, (array) ($cfg['vars'] ?? []))) {
            $v = $cfg['vars'][$var] ?? ($dates[$var] ?? '');

            return [(string) ($v ?? ''), $bound ? self::BOUND : self::KNOWN];
        }
        if (isset($dates[$var])) {
            return [$dates[$var], $bound ? self::BOUND : self::KNOWN];
        }
        foreach ($context->patterns() as $regex => $value) {
            if (@preg_match($regex, $var) === 1 || @preg_match($regex, $label) === 1) {
                return [$this->dateWord((string) $value, $dates), self::GUESSED];
            }
        }
        foreach ((array) ($cfg['patterns'] ?? []) as $regex => $value) {
            if (@preg_match($regex, $var) === 1 || @preg_match($regex, $label) === 1) {
                return [$this->dateWord((string) $value, $dates), self::GUESSED];
            }
        }

        return ['Sample: ' . $label, self::PLACEHOLDER];
    }

    private function dateWord(string $value, array $dates): string
    {
        return $value === 'first of next month' ? $dates['lease_start'] : $value;
    }

    private function varName(array $mapping, $namedFields): ?string
    {
        try {
            $c = app(TemplateController::class);
            $rm = new \ReflectionMethod($c, 'deriveBladeVarName');
            $rm->setAccessible(true);

            return $rm->invoke($c, $mapping, $namedFields);
        } catch (\Throwable) {
            return null;
        }
    }

    private function humanise(string $var): string
    {
        return ucfirst(trim(str_replace('_', ' ', $var)));
    }
}
