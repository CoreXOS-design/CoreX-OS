<?php

declare(strict_types=1);

namespace App\Console\Commands\Docuperfect;

use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\WebPack;
use App\Models\Docuperfect\WebPackItem;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * HD-3b — compose the "Sales Mandate Pack (CANDIDATE)" web pack from the templates that ACTUALLY
 * exist on this environment, per Johan's D-1 composition:
 *
 *   Mandate           selectable, one-of (Open / Exclusive)
 *   Mandatory Disclosure  required
 *   FICA              selectable, one-of (the applicable one)
 *
 * This is deliberately a COMPOSITION step, not a content-creation step. It never invents a template
 * and never runs the static #111 EATS seeder (that reproduces the phantom and skips m5's corrected
 * import bindings). It resolves real, e-signable web templates by their document_type, wires the ones
 * it finds into the pack, and REPORTS every slot it could not fill — because a pack built against
 * templates that do not exist is exactly the "no production content" problem restated.
 *
 * Dry-run by default. --apply writes. Idempotent: re-running updates the same CANDIDATE pack, so once
 * the missing members (Open mandate, FICA) are created, a re-run folds them in.
 *
 * Run on qa1 by the deploy hand — it must run WHERE the real templates live; it cannot be run from a
 * lane's empty dev DB.
 */
final class ComposeSalesMandatePack extends Command
{
    protected $signature = 'esign:compose-sales-mandate-pack
        {--agency= : Agency id to own the pack (defaults to the only agency, or errors if ambiguous)}
        {--apply : Write the pack (default is a dry-run report)}';

    protected $description = 'Compose the Sales Mandate Pack (CANDIDATE) web pack from existing mandate/disclosure/FICA templates (HD-3b).';

    private const PACK_NAME = 'Sales Mandate Pack (CANDIDATE)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $agencyId = $this->resolveAgencyId();
        if ($agencyId === null) {
            return self::FAILURE;
        }

        $owner = User::withoutGlobalScopes()->where('agency_id', $agencyId)->orderByDesc('id')->first();
        if (! $owner) {
            $this->error("No user found for agency {$agencyId} to own the pack.");
            return self::FAILURE;
        }

        // Resolve real, e-signable web templates by document_type. A mandate that is blocked
        // (isEsignBlocked — e.g. mis-typed as an OTP) is excluded so a candidate pack can never carry
        // an un-e-signable document.
        //
        // The MANDATE slot also accepts a NAME fallback (the same pattern the classifier uses:
        // "authority to sell" / "exclusive authority" / "mandate"). This is the belt for tonight:
        // when an agent imports the EATS through the builder, the classifier normally stamps
        // document_type=mandate — but if it lands null (an unusual name), the fallback still wires the
        // real mandate rather than silently composing without it. It is SAFE because isEsignBlocked()
        // still excludes every alienation document, so a sale can never enter via the name path. Any
        // name-fallback match is reported, never silent.
        $mandates    = $this->esignableByType($agencyId, 'mandate', '/\b(mandate|authority\s+to\s+sell|exclusive\s+authority)\b/i');
        $disclosures = $this->esignableByType($agencyId, 'disclosure');
        $ficas       = $this->esignableByType($agencyId, 'fica');

        $this->line('');
        $this->info('Sales Mandate Pack — resolution on agency ' . $agencyId . ':');
        $this->reportSlot('Mandate (selectable — Open/Exclusive)', $mandates);
        $this->reportSlot('Mandatory Disclosure (required)', $disclosures);
        $this->reportSlot('FICA (selectable — applicable one)', $ficas);
        $this->line('');

        // A mandate pack with no mandate is meaningless — refuse.
        if ($mandates->isEmpty()) {
            $this->error('No e-signable mandate template found. Import the Exclusive Authority to Sell through the '
                . 'builder first (NOT the static #111 seeder). Nothing written.');
            return self::FAILURE;
        }

        // Honest gap surfacing — these are Johan/human decisions, not blockers to a candidate pack.
        if ($disclosures->isEmpty()) {
            $this->warn('⚠ No Disclosure template — run SalesMandatoryDisclosureEsignSeeder, then re-run.');
        }
        if ($ficas->isEmpty()) {
            $this->warn('⚠ No FICA template — FICA is the Compliance module (Schedule 4-7), not a DocuPerfect '
                . 'template today. The FICA slot stays EMPTY until Johan decides: create FICA e-sign templates, '
                . 'or handle FICA via the compliance-module link (no pack slot). Composed WITHOUT a FICA slot.');
        }
        if ($mandates->count() < 2) {
            $this->warn('⚠ Only one mandate variant found — the Open/Exclusive choice needs both. Composed with '
                . 'the ' . $mandates->count() . ' that exist.');
        }

        // Build the slot plan.
        $plan = [];
        foreach ($mandates as $t)    { $plan[] = ['template' => $t, 'slot_type' => 'selectable', 'slot_group' => 1, 'slot_label' => 'Mandate type']; }
        foreach ($disclosures as $t) { $plan[] = ['template' => $t, 'slot_type' => 'required',   'slot_group' => null, 'slot_label' => null]; }
        foreach ($ficas as $t)       { $plan[] = ['template' => $t, 'slot_type' => 'selectable', 'slot_group' => 2, 'slot_label' => 'FICA']; }

        $this->info(($apply ? 'Writing' : 'Would write') . ' "' . self::PACK_NAME . '" — ' . count($plan) . ' item(s):');
        foreach ($plan as $i => $row) {
            $label = $row['slot_type'] . ($row['slot_group'] ? " g{$row['slot_group']}" : '') . ($row['slot_label'] ? " ({$row['slot_label']})" : '');
            $this->line(sprintf('  %2d. %-40s %s', $i + 1, $row['template']->name, $label));
        }

        if (! $apply) {
            $this->line('');
            $this->comment('Dry-run. Re-run with --apply to write.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($agencyId, $owner, $plan) {
            $pack = WebPack::withoutGlobalScopes()->updateOrCreate(
                ['agency_id' => $agencyId, 'name' => self::PACK_NAME],
                ['created_by' => $owner->id, 'description' => 'CANDIDATE — awaiting human vet (clause fields + binding review) before production naming. HD-3b.'],
            );

            // Idempotent: rebuild items from the current plan.
            $pack->items()->forceDelete();

            foreach ($plan as $i => $row) {
                WebPackItem::create([
                    'web_pack_id' => $pack->id,
                    'template_id' => $row['template']->id,
                    'sort_order'  => $i * 10,
                    'slot_type'   => $row['slot_type'],
                    'slot_group'  => $row['slot_group'],
                    'slot_label'  => $row['slot_label'],
                ]);
            }
        });

        $this->line('');
        $this->info('✓ "' . self::PACK_NAME . '" composed on agency ' . $agencyId . '. It now appears under Documents → Web Packs.');
        return self::SUCCESS;
    }

    /**
     * e-signable web templates of a document_type, agency-visible, excluding legally-blocked ones.
     *
     * @param  string|null  $nameFallback  optional regex — also include e-signable web templates whose
     *                                      NAME matches, even if their document_type is unset/other.
     *                                      Used only for the mandate slot; every match still passes
     *                                      through the isEsignBlocked() exclusion below.
     */
    private function esignableByType(int $agencyId, string $slug, ?string $nameFallback = null)
    {
        $base = Template::query()
            ->where('render_type', 'web')
            ->where('is_esign', true)
            ->whereNull('archived_at');

        $templates = (clone $base)
            ->whereHas('documentType', fn ($q) => $q->where('slug', $slug))
            ->get();

        if ($nameFallback !== null) {
            $byName = (clone $base)->get()
                ->filter(fn (Template $t) => preg_match($nameFallback, (string) $t->name) === 1);

            $typedIds = $templates->pluck('id')->all();
            foreach ($byName as $t) {
                if (! in_array($t->id, $typedIds, true)) {
                    $this->line("  <fg=cyan>· name-fallback matched \"{$t->name}\" as a mandate (document_type not '{$slug}')</>");
                    $templates->push($t);
                }
            }
        }

        return $templates
            ->reject(fn (Template $t) => $t->isEsignBlocked())
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    private function reportSlot(string $label, $templates): void
    {
        if ($templates->isEmpty()) {
            $this->line("  <fg=yellow>{$label}: none found</>");
            return;
        }
        $this->line("  <fg=green>{$label}: " . $templates->count() . "</> — " . $templates->pluck('name')->implode(', '));
    }

    private function resolveAgencyId(): ?int
    {
        $given = $this->option('agency');
        if ($given !== null && $given !== '') {
            return (int) $given;
        }

        $ids = DB::table('agencies')->whereNull('deleted_at')->pluck('id');
        if ($ids->count() === 1) {
            return (int) $ids->first();
        }

        $this->error('Multiple agencies exist — pass --agency=<id> (the pack is agency-scoped content).');
        return null;
    }
}
