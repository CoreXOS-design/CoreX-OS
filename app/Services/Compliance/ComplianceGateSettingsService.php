<?php

namespace App\Services\Compliance;

use App\Models\ComplianceGateChange;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AT-509 — Compliance Switches (.ai/specs/property-compliance-gate-settings.md §5).
 *
 * The ONLY code that changes whether a property marketing-compliance check is on or off for an agency.
 * Every change is written together with its append-only ComplianceGateChange row, and switching anything
 * OFF is refused unless the current disclaimer version was accepted — enforced here, not just in the modal.
 *
 * Gate keys:
 *   master            — every check at once (individual positions are kept while it is off)
 *   doc:<slug>        — a required document type; state lives on agency_document_type_compliance
 *   photos            — at least 4 photos
 *   details_complete  — required listing details
 * A missing agency_compliance_gate_settings row means ON (the behaviour every agency had before this).
 */
class ComplianceGateSettingsService
{
    public const MASTER  = 'master';
    public const PHOTOS  = 'photos';
    public const DETAILS = 'details_complete';
    public const DOC_PREFIX = 'doc:';

    private const CORE_LABELS = [
        self::MASTER  => 'All compliance checks',
        self::PHOTOS  => 'Photos',
        self::DETAILS => 'Listing details',
    ];

    /** @var array<int, array<string, bool>> per-agency core switch state, per instance */
    private array $coreCache = [];

    /** @var array<int, array<string, ComplianceGateChange>> per-agency latest change per gate, per instance */
    private array $latestCache = [];

    public function __construct(
        private AgencyComplianceDocTypeService $docTypes = new AgencyComplianceDocTypeService(),
    ) {
    }

    // ── Reads ────────────────────────────────────────────────────────────

    public function masterEnabled(int $agencyId): bool
    {
        return $this->coreStates($agencyId)[self::MASTER];
    }

    /** Photos / details switch, with the master switch applied on top. */
    public function isEnabled(int $agencyId, string $gateKey): bool
    {
        return $this->masterEnabled($agencyId) && ($this->coreStates($agencyId)[$gateKey] ?? true);
    }

    /**
     * Everything the Compliance Switches form shows, in display order.
     *
     * @return array{master: array, documents: list<array>, other_documents: list<array>, core: list<array>}
     */
    public function gatesFor(int $agencyId): array
    {
        $this->docTypes->ensureDefaults($agencyId);

        $core = $this->coreStates($agencyId);
        $required = $this->docTypes->complianceMapFor($agencyId);
        $ficaSlug = config('corex-compliance.fica_slug', 'fica');

        $documents = [];
        $other = [];
        foreach ($this->activeDocumentTypes() as $type) {
            $row = [
                'key'     => self::DOC_PREFIX . $type->slug,
                'label'   => $type->label,
                'enabled' => (bool) ($required[$type->id] ?? false),
                'explain' => $type->slug === $ficaSlug
                    ? 'Every seller or landlord linked to the property must have approved FICA.'
                    : 'A ' . $type->label . ' must be on the property Drive (or on a seller\'s contact).',
            ];
            if ($row['enabled'] || $this->latestChange($agencyId, $row['key'])) {
                $documents[] = $row;
            } else {
                $other[] = $row;
            }
        }

        return [
            'master' => [
                'key' => self::MASTER, 'label' => 'Compliance check before marketing', 'enabled' => $core[self::MASTER],
                'explain' => 'When this is on, a property cannot be marketed until every check below that is switched on has passed.',
            ],
            'documents'       => $documents,
            'other_documents' => $other,
            'core' => [
                ['key' => self::PHOTOS, 'label' => self::CORE_LABELS[self::PHOTOS], 'enabled' => $core[self::PHOTOS],
                 'explain' => 'At least 4 photos must be uploaded.'],
                ['key' => self::DETAILS, 'label' => self::CORE_LABELS[self::DETAILS], 'enabled' => $core[self::DETAILS],
                 'explain' => 'The address, suburb, price and other required listing details must be filled in.'],
            ],
        ];
    }

    /**
     * The checks that are OFF right now because the agency switched them off (latest change is a switch-off).
     * A document type that was simply never required is not "switched off" and is not listed.
     *
     * @return array<string, array{key:string,label:string,switched_off_at:?string,switched_off_by:?string,change_id:?int}>
     */
    public function switchedOffGates(int $agencyId): array
    {
        $core = $this->coreStates($agencyId);
        $required = $this->docTypes->complianceMapFor($agencyId);
        $typesBySlug = $this->activeDocumentTypes()->keyBy('slug');

        $out = [];
        foreach ([self::MASTER, self::PHOTOS, self::DETAILS] as $key) {
            if (! $core[$key]) {
                $out[$key] = $this->offEntry($agencyId, $key, self::CORE_LABELS[$key]);
            }
        }

        foreach ($this->latestChanges($agencyId) as $key => $change) {
            if (! str_starts_with($key, self::DOC_PREFIX) || ! $change->isSwitchOff()) {
                continue;
            }
            $type = $typesBySlug->get(substr($key, strlen(self::DOC_PREFIX)));
            if ($type && ! ($required[$type->id] ?? false)) {
                $out[$key] = $this->offEntry($agencyId, $key, $type->label);
            }
        }

        return $out;
    }

    public function latestChange(int $agencyId, string $gateKey): ?ComplianceGateChange
    {
        return $this->latestChanges($agencyId)[$gateKey] ?? null;
    }

    public function lastChangeFor(int $agencyId): ?ComplianceGateChange
    {
        return ComplianceGateChange::where('agency_id', $agencyId)->orderByDesc('id')->first();
    }

    public function disclaimerVersion(): string
    {
        return (string) config('corex-compliance.gate_disclaimer.version');
    }

    /** The exact text shown in the modal and stored on each history row. */
    public function renderDisclaimer(string $agencyName, array $gateLabels): string
    {
        return strtr((string) config('corex-compliance.gate_disclaimer.text'), [
            '{agency}' => $agencyName,
            '{gates}'  => implode(', ', $gateLabels),
        ]);
    }

    // ── The one writer ───────────────────────────────────────────────────

    /**
     * Apply the posted switch positions. Only keys present in $posted are considered (a form that never
     * rendered a switch can never change it). Returns the history rows written.
     *
     * @param  array<string, bool>  $posted
     * @return list<ComplianceGateChange>
     *
     * @throws ValidationException when something is switched off without the current disclaimer accepted
     */
    public function apply(int $agencyId, string $agencyName, array $posted, User $by, Request $request, ?string $acceptedVersion): array
    {
        $gates = $this->gatesFor($agencyId);
        $ordered = array_merge([$gates['master']], $gates['documents'], $gates['other_documents'], $gates['core']);
        $docTypeIds = $this->activeDocumentTypes()->pluck('id', 'slug');

        $changes = [];
        foreach ($ordered as $gate) {
            if (! array_key_exists($gate['key'], $posted)) {
                continue;
            }
            $to = (bool) $posted[$gate['key']];
            if ($to !== $gate['enabled']) {
                $changes[] = ['key' => $gate['key'], 'label' => $gate['label'], 'to' => $to];
            }
        }

        if ($changes === []) {
            return [];
        }

        $offLabels = array_column(array_filter($changes, fn ($c) => ! $c['to']), 'label');
        if ($offLabels !== [] && $acceptedVersion !== $this->disclaimerVersion()) {
            throw ValidationException::withMessages([
                'compliance_disclaimer' => 'Read and accept the disclaimer before switching a compliance check off. Nothing was changed.',
            ]);
        }
        $disclaimerText = $offLabels !== [] ? $this->renderDisclaimer($agencyName, $offLabels) : null;

        $written = DB::transaction(function () use ($changes, $agencyId, $docTypeIds, $by, $request, $disclaimerText) {
            $rows = [];
            $now = now();
            foreach ($changes as $c) {
                if (str_starts_with($c['key'], self::DOC_PREFIX)) {
                    $this->docTypes->setRequired($agencyId, (int) $docTypeIds[substr($c['key'], strlen(self::DOC_PREFIX))], $c['to']);
                } else {
                    DB::table('agency_compliance_gate_settings')->updateOrInsert(
                        ['agency_id' => $agencyId, 'gate_key' => $c['key']],
                        ['enabled' => $c['to'], 'deleted_at' => null, 'updated_at' => $now, 'created_at' => DB::raw('COALESCE(created_at, NOW())')],
                    );
                }

                $off = ! $c['to'];
                $rows[] = ComplianceGateChange::create([
                    'agency_id'              => $agencyId,
                    'gate_key'               => $c['key'],
                    'gate_label'             => $c['label'],
                    'action'                 => $off ? ComplianceGateChange::ACTION_OFF : ComplianceGateChange::ACTION_ON,
                    'user_id'                => $by->id,
                    'user_name'              => $by->name,
                    'user_email'             => $by->email,
                    'ip_address'             => $request->ip(),
                    'user_agent'             => mb_substr((string) $request->userAgent(), 0, 512),
                    'disclaimer_version'     => $off ? $this->disclaimerVersion() : null,
                    'disclaimer_text'        => $off ? $disclaimerText : null,
                    'disclaimer_accepted_at' => $off ? $now : null,
                ]);
            }

            return $rows;
        });

        unset($this->coreCache[$agencyId], $this->latestCache[$agencyId]);

        return $written;
    }

    // ── Internals ────────────────────────────────────────────────────────

    /** @return array<string, bool> */
    private function coreStates(int $agencyId): array
    {
        if (! isset($this->coreCache[$agencyId])) {
            $stored = DB::table('agency_compliance_gate_settings')
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->pluck('enabled', 'gate_key');

            $this->coreCache[$agencyId] = [
                self::MASTER  => (bool) ($stored[self::MASTER] ?? true),
                self::PHOTOS  => (bool) ($stored[self::PHOTOS] ?? true),
                self::DETAILS => (bool) ($stored[self::DETAILS] ?? true),
            ];
        }

        return $this->coreCache[$agencyId];
    }

    /** @return array<string, ComplianceGateChange> */
    private function latestChanges(int $agencyId): array
    {
        if (! isset($this->latestCache[$agencyId])) {
            $ids = ComplianceGateChange::where('agency_id', $agencyId)
                ->selectRaw('MAX(id) as id')
                ->groupBy('gate_key')
                ->pluck('id');

            $this->latestCache[$agencyId] = ComplianceGateChange::whereIn('id', $ids)->get()->keyBy('gate_key')->all();
        }

        return $this->latestCache[$agencyId];
    }

    private function offEntry(int $agencyId, string $key, string $label): array
    {
        $change = $this->latestChange($agencyId, $key);
        $change = $change && $change->isSwitchOff() ? $change : null;

        return [
            'key'             => $key,
            'label'           => $label,
            'switched_off_at' => $change?->created_at?->toIso8601String(),
            'switched_off_by' => $change?->user_name,
            'change_id'       => $change?->id,
        ];
    }

    private function activeDocumentTypes(): \Illuminate\Support\Collection
    {
        return DB::table('document_types')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'slug', 'label']);
    }
}
