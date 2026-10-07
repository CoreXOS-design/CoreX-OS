<?php

namespace App\Services\Rentals;

use App\Models\Docuperfect\Template;
use App\Models\RentalLeaseTemplate;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/leases.md §15.12.4 (Build L0). The one class that decides whether a template may be used by
 * an agency for the lease process, so a template with no owning agency (the built-in, shared kind) and
 * every other agency's template can never reach another agency.
 *
 * A template is usable by $agencyId only when ALL hold:
 *   - its `agency_id` is not null and EQUALS the acting agency (an ownerless/shared template is refused
 *     for every agency — that is the whole point; `agency_id = null` is the leaking value);
 *   - it is an e-sign template and is not archived;
 *   - (when a field map is supplied) the map carries what the process needs — rent, start date, a
 *     tenant name, a landlord name, and an end date unless the lease is month-to-month (§15.12.3);
 *   - it has signing places for the three roles: agent, tenant and landlord.
 *
 * Called at four points so no single path can be bypassed: the set-up page's picker, the set-up
 * page's store/update, LeaseSigningLauncher::missing() and ::launch() (the last two arrive with L3a).
 *
 * A template that is not the acting agency's own gets ONE generic message — the same wording a missing
 * template id gets — so a forged id reveals nothing about another agency's templates.
 */
class LeaseAgreementTemplateGuard
{
    public const NOT_AVAILABLE = 'That lease agreement is not available to your agency.';
    public const SHARED = 'This is a shared template. Your agency needs its own copy of its lease agreement before it can be used here.';
    public const ARCHIVED = 'This template is archived.';
    public const NOT_ESIGN = 'This document is not an e-sign document.';

    public const STATE_READY = 'ready';
    public const STATE_NEEDS_MAP = 'needs_map';
    public const STATE_NOT_USABLE = 'not_usable';

    /** Role tokens a template may use for who signs, mapped to the three roles the lease process needs. */
    private const ROLE_ALIASES = [
        'agent' => 'agent',
        'landlord' => 'landlord', 'lessor' => 'landlord', 'owner_party' => 'landlord', 'owner' => 'landlord',
        'tenant' => 'tenant', 'lessee' => 'tenant', 'acquiring_party' => 'tenant',
    ];

    public const REQUIRED_ROLES = ['agent', 'tenant', 'landlord'];

    /**
     * Every reason $template cannot be used by $agencyId for the lease process; [] = usable.
     * Pass the row's field map (an empty array for "no map yet") to include the map check; omit it to
     * check the template alone — the set-up page does that before a map exists.
     *
     * @param array<string,mixed>|null $fieldMap
     * @return array<int, string>
     */
    public function problemsFor(Template $template, int $agencyId, ?array $fieldMap = null): array
    {
        $refusals = $this->refusalsFor($template, $agencyId);
        if ($refusals !== []) {
            return $refusals;
        }

        $problems = [];
        if ($fieldMap !== null) {
            array_push($problems, ...$this->mapProblems($fieldMap));
        }

        $roles = $this->signingRolesFor($template);
        foreach (self::REQUIRED_ROLES as $role) {
            if (! in_array($role, $roles, true)) {
                $problems[] = "No signing place for the {$role}.";
            }
        }

        return $problems;
    }

    /**
     * The hard refusals only — the template is not this agency's own (or has no owner), is archived, or
     * is not an e-sign document. A template that fails these can never be selected, stored or launched;
     * the softer problems (field map, signing places) let a row be saved but not used.
     *
     * @return array<int, string>
     */
    public function refusalsFor(Template $template, int $agencyId): array
    {
        if ($template->agency_id === null) {
            return [self::SHARED];
        }
        if ((int) $template->agency_id !== $agencyId) {
            return [self::NOT_AVAILABLE];
        }

        $refusals = [];
        if ($template->archived_at !== null) {
            $refusals[] = self::ARCHIVED;
        }
        if (! $template->is_esign) {
            $refusals[] = self::NOT_ESIGN;
        }

        return $refusals;
    }

    /**
     * @param array<string,mixed>|null $fieldMap
     * @throws ValidationException when problemsFor() is not empty
     */
    public function assertUsable(Template $template, int $agencyId, ?array $fieldMap = null): void
    {
        $problems = $this->problemsFor($template, $agencyId, $fieldMap);
        if ($problems !== []) {
            throw ValidationException::withMessages(['template' => $problems]);
        }
    }

    /**
     * What the field map still has to carry before the agreement is linkable (§15.12.3), from the
     * registry's `link_requirements`. Reads the map through the reader's own normaliser, so the string
     * shorthand and the object form are both understood.
     *
     * @param array<string,mixed> $fieldMap
     * @return array<int, string>
     */
    public function mapProblems(array $fieldMap): array
    {
        $registry = (array) config('lease-agreement-fields.fields', []);
        $mapped = app(LeaseAgreementValuesReader::class)->normaliseMap($fieldMap);
        $req = (array) config('lease-agreement-fields.link_requirements', []);
        $label = fn (string $key): string => mb_strtolower((string) ($registry[$key]['label'] ?? $key));
        $problems = [];

        foreach ((array) ($req['all'] ?? []) as $key) {
            if (! isset($mapped[$key])) {
                $problems[] = 'Map the ' . $label($key) . ' field.';
            }
        }
        foreach ((array) ($req['any_of'] ?? []) as $group) {
            if (array_intersect((array) $group, array_keys($mapped)) === []) {
                $problems[] = 'Map the ' . $label((string) ($group[0] ?? '')) . ' field.';
            }
        }
        if (! empty($req['end_date_unless_month_to_month']) && ! isset($mapped['end_date']) && empty($fieldMap['_meta']['month_to_month'])) {
            $problems[] = 'Map the end date field, or tick that this lease is month-to-month.';
        }

        return $problems;
    }

    /**
     * ready | needs_map | not_usable, with the reasons — what the set-up page shows as the row's chip.
     *
     * @return array{state: string, problems: array<int,string>}
     */
    public function statusFor(RentalLeaseTemplate $row, int $agencyId): array
    {
        $template = $row->template;
        if (! $template) {
            return ['state' => self::STATE_NOT_USABLE, 'problems' => ['The source document could not be found.']];
        }

        $templateProblems = $this->problemsFor($template, $agencyId);
        if ($templateProblems !== []) {
            return ['state' => self::STATE_NOT_USABLE, 'problems' => $templateProblems];
        }

        $mapProblems = $this->mapProblems((array) ($row->field_map ?? []));

        return $mapProblems === []
            ? ['state' => self::STATE_READY, 'problems' => []]
            : ['state' => self::STATE_NEEDS_MAP, 'problems' => $mapProblems];
    }

    /**
     * Run the check for a row and keep the result on it (validated_at / validation_problems), so the
     * set-up page can show it. Returns the problems ([] = ready).
     *
     * @return array<int, string>
     */
    public function recordCheck(RentalLeaseTemplate $row, int $agencyId): array
    {
        $status = $this->statusFor($row, $agencyId);

        $row->forceFill([
            'validated_at' => now(),
            'validation_problems' => $status['problems'] === [] ? null : $status['problems'],
        ])->save();

        return $status['problems'];
    }

    /**
     * The agency's linked, usable lease agreement for the lease process (the default when it has
     * several), or null when it has none — the real starting state for every agency until it sets one
     * up (R3). Checked live every time; the stored result is only for display.
     */
    public function linkedFor(int $agencyId, string $category = RentalLeaseTemplate::CATEGORY_RESIDENTIAL): ?RentalLeaseTemplate
    {
        $rows = RentalLeaseTemplate::query()
            ->where('agency_id', $agencyId)
            ->where('category', $category)
            ->where('is_active', true)
            ->with('template')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if ($this->statusFor($row, $agencyId)['state'] === self::STATE_READY) {
                return $row;
            }
        }

        return null;
    }

    // ── Reading a template (read-only; none of the pipeline-gated files is touched) ──────

    /**
     * Which of agent / tenant / landlord the template has a signing place for. Evidence is the union of
     * every place a template declares who signs: its own `signing_parties`, its signature zones, a CDS
     * signature section, the signature includes in its blade source, and signature entries in its
     * `fields_json`. Tokens are normalised (lessor → landlord, lessee → tenant, owner_party →
     * landlord, acquiring_party → tenant; a numbered `tenant_2` is `tenant`).
     *
     * @return array<int, string>
     */
    public function signingRolesFor(Template $template): array
    {
        $tokens = [];

        foreach ((array) ($template->signing_parties ?? []) as $party) {
            if (is_string($party)) {
                $tokens[] = $party;
            }
        }

        foreach ($template->signatureZones()->get() as $zone) {
            foreach ((array) ($zone->assigned_parties ?? []) as $party) {
                if (is_string($party)) {
                    $tokens[] = $party;
                }
            }
        }

        $this->collectCdsSignatureRoles((array) ($template->cds_json ?? []), $tokens);

        foreach ((array) ($template->fields_json ?? []) as $field) {
            if (is_array($field) && in_array(($field['type'] ?? null), ['signature', 'initial'], true)) {
                foreach (['assignedTo', 'party', 'role'] as $key) {
                    if (isset($field[$key]) && is_string($field[$key])) {
                        $tokens[] = $field[$key];
                    }
                }
            }
        }

        $source = $this->bladeSource($template);
        if ($source !== null) {
            if (preg_match_all('/signature-line[\'"]\s*,\s*\[\s*[\'"]party[\'"]\s*=>\s*[\'"]([a-z_0-9]+)[\'"]/i', $source, $m)) {
                array_push($tokens, ...$m[1]);
            }
            if (preg_match_all('/data-marker-party="([a-z_0-9]+)"/i', $source, $m)) {
                array_push($tokens, ...$m[1]);
            }
            if (preg_match_all('/signature-block[\'"]\s*,\s*\[\s*[\'"]parties[\'"]\s*=>\s*\[([^\]]*)\]/i', $source, $blocks)) {
                foreach ($blocks[1] as $list) {
                    if (preg_match_all('/[\'"]([A-Za-z_0-9 ]+)[\'"]/', $list, $m)) {
                        array_push($tokens, ...$m[1]);
                    }
                }
            }
        }

        $roles = [];
        foreach ($tokens as $token) {
            $role = self::ROLE_ALIASES[preg_replace('/_\d+$/', '', mb_strtolower(trim($token)))] ?? null;
            if ($role !== null) {
                $roles[$role] = true;
            }
        }

        return array_values(array_intersect(self::REQUIRED_ROLES, array_keys($roles)));
    }

    /**
     * The template's own field names — what the field-map dropdown offers: `field_name` entries of
     * `fields_json`, the fields of a CDS template, and the `data-field` names printed in a web
     * template's blade source. Sorted, unique, no empty names.
     *
     * @return array<int, string>
     */
    public function fieldNamesFor(Template $template): array
    {
        $names = [];

        foreach ((array) ($template->fields_json ?? []) as $field) {
            if (is_array($field) && ! empty($field['field_name']) && is_string($field['field_name'])) {
                $names[] = $field['field_name'];
            }
        }

        $this->collectCdsFieldNames((array) ($template->cds_json ?? []), $names);

        $source = $this->bladeSource($template);
        if ($source !== null && preg_match_all('/data-field="([^"{}$]+)"/', $source, $m)) {
            array_push($names, ...$m[1]);
        }

        $names = array_values(array_unique(array_filter(array_map('trim', $names), fn ($n) => $n !== '')));
        sort($names);

        return $names;
    }

    /** @param array<int,string> $tokens */
    private function collectCdsSignatureRoles(array $cds, array &$tokens): void
    {
        foreach ((array) ($cds['sections'] ?? []) as $section) {
            if (! is_array($section) || ! in_array(($section['type'] ?? ''), ['signature_section', 'inline_signature'], true)) {
                continue;
            }
            foreach ((array) ($section['parties'] ?? []) as $party) {
                if (is_array($party) && isset($party['role']) && is_string($party['role'])) {
                    $tokens[] = $party['role'];
                }
            }
        }
    }

    /** @param array<int,string> $names */
    private function collectCdsFieldNames(array $cds, array &$names): void
    {
        array_walk_recursive($cds, function ($value, $key) use (&$names) {
            if ($key === 'field_name' && is_string($value)) {
                $names[] = $value;
            }
        });
    }

    /** The blade source of a web/CDS template, or null when it has none (or it cannot be read). */
    private function bladeSource(Template $template): ?string
    {
        if (! $template->blade_view || ! is_string($template->blade_view)) {
            return null;
        }

        try {
            $path = view()->getFinder()->find($template->blade_view);
        } catch (\Throwable) {
            return null;
        }

        $source = is_file($path) ? @file_get_contents($path) : false;

        return $source === false ? null : $source;
    }
}
