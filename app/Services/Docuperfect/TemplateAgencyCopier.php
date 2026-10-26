<?php

namespace App\Services\Docuperfect;

use App\Models\Agency;
use App\Models\Docuperfect\Template;
use App\Models\RentalLeaseTemplate;
use App\Models\User;
use App\Services\Docuperfect\TemplateTransfer\TemplatePackageExporter;
use App\Services\Docuperfect\TemplateTransfer\TemplatePackageImporter;
use App\Services\Docuperfect\TemplateTransfer\TemplateTransferException;
use App\Services\Rentals\LeaseAgreementTemplateGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Copy a finished template from one agency to another on this system — CoreX builds each agency's
 * documents in a workshop agency, then transfers the result to the client agency.
 * Spec: .ai/specs/cds-reimport-from-word.md §Copy template to another agency.
 *
 * This is the existing template-package pipeline (TemplatePackageExporter -> TemplatePackageImporter,
 * .ai/specs/esign-template-transfer.md) run in memory, one click, same box — NOT a second copier.
 * So everything the package carries (wording, field definitions and groups matched by stable key,
 * tags/bindings, signing parties, settings, document look, signature zones, page images) arrives
 * exactly as an export + import would deliver it, an unsafe package is refused the same way, and a
 * failed copy rolls back and leaves nothing behind. It always creates a NEW template in the target
 * agency and never overwrites; a name already taken there gets "<name> v2".
 *
 * Owner-level only: the caller (route: owner_only + templates.transfer) AND this service check it.
 */
class TemplateAgencyCopier
{
    public function __construct(
        private readonly TemplatePackageExporter $exporter,
        private readonly TemplatePackageImporter $importer,
        private readonly LeaseAgreementTemplateGuard $leaseGuard,
    ) {
    }

    /**
     * @return array{template: Template, renamed: bool, lease_templates: list<RentalLeaseTemplate>, lease_problems: list<string>}
     * @throws TemplateTransferException with a plain-language message
     */
    public function copy(Template $source, Agency $target, User $actor, bool $copyLeaseFieldMap = false): array
    {
        if (! $actor->isOwnerRole() || ! $actor->hasPermission('templates.transfer')) {
            throw new TemplateTransferException('Only a CoreX administrator can copy a template to another agency.');
        }
        if ($source->agency_id !== null && (int) $source->agency_id === (int) $target->id) {
            throw new TemplateTransferException('That template already belongs to ' . $target->name . '. Choose a different agency.');
        }

        $pkg = $this->exporter->export($source, $actor, false);

        $rel = 'tmp/template-copy/' . Str::uuid() . '.cxpkg';
        Storage::disk('local')->put($rel, $pkg['bytes']);
        try {
            $loaded = $this->importer->load(Storage::disk('local')->path($rel));
            $previews = $this->importer->preview($loaded, $target);
            // A name already taken in the target agency becomes "<name> v2" — nothing is replaced.
            $choices = [];
            foreach ($previews as $p) {
                if ($p['clash']['exists']) {
                    $choices[$p['index']] = TemplatePackageImporter::CHOICE_NEW_VERSION;
                }
            }
            // A document type that does not exist in this system is not a reason to stop a same-box
            // copy (the type table is shared); the package's own safety checks still run.
            $created = $this->importer->import($loaded, $target, $actor, $choices, false, $source);
        } finally {
            Storage::disk('local')->delete($rel);
        }

        /** @var Template $copy */
        $copy = $created[0];

        $lease = [];
        $problems = [];
        if ($copyLeaseFieldMap) {
            [$lease, $problems] = $this->copyLeaseTemplates($source, $copy, $target);
        }

        return [
            'template' => $copy,
            'renamed' => trim((string) $copy->name) !== trim((string) $source->name),
            'lease_templates' => $lease,
            'lease_problems' => $problems,
        ];
    }

    /**
     * Only when the caller ticked it. The rows are created in the TARGET agency pointing at the NEW
     * template; field_map is copied verbatim (it names the template's own field names, which the
     * copy keeps) and re-validated by the same guard the lease process uses. A row becomes the
     * target agency's default for its category only when that agency has no default there yet.
     *
     * @return array{0: list<RentalLeaseTemplate>, 1: list<string>}
     */
    private function copyLeaseTemplates(Template $source, Template $copy, Agency $target): array
    {
        $rows = RentalLeaseTemplate::withoutGlobalScopes()
            ->where('docuperfect_template_id', $source->id)->get();

        $made = [];
        $problems = [];
        DB::transaction(function () use ($rows, $copy, $target, &$made, &$problems) {
            foreach ($rows as $row) {
                $found = $this->leaseGuard->problemsFor($copy, (int) $target->id, (array) ($row->field_map ?? []));
                $hasDefault = RentalLeaseTemplate::withoutGlobalScopes()
                    ->where('agency_id', $target->id)->where('category', $row->category)
                    ->where('is_active', true)->where('is_default', true)->exists();

                $made[] = RentalLeaseTemplate::withoutAgencyStamping(fn () => RentalLeaseTemplate::create([
                    'agency_id' => $target->id,
                    'name' => $row->name,
                    'docuperfect_template_id' => $copy->id,
                    'category' => $row->category,
                    'is_active' => true,
                    'field_map' => $row->field_map,
                    'is_default' => ! $hasDefault,
                    'validated_at' => now(),
                    'validation_problems' => $found ?: null,
                ]));
                foreach ($found as $p) {
                    $problems[] = $row->name . ': ' . (is_string($p) ? $p : json_encode($p));
                }
            }
        });

        return [$made, $problems];
    }
}
