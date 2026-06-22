<?php

namespace App\Console\Commands;

use App\Models\ContactType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AT-79 — collapse contact types to the four fixed e-sign parents.
 *
 * Destructive normalisation, separated from the additive schema migrations so it
 * only runs after a signed-off dry-run (CLAUDE.md: never destroy prod data
 * unattended). For each NON-canonical contact type:
 *   - 0 contacts  -> soft-delete (drop the orphan).
 *   - >0 contacts -> map to the closest parent, preserve the old name as a
 *                    sub-tag under that parent (per agency), re-point each
 *                    affected contact (pivot + sub-tag + primary mirror), then
 *                    soft-delete the now-empty extra type.
 *   - unmappable with contacts -> ABORT (never silently mis-bucket real people).
 *
 * Legacy free-form tags with no parent are REPORTED for manual re-homing in
 * Settings, not auto-bucketed.
 *
 *   php artisan contacts:normalise-types            # dry-run report
 *   php artisan contacts:normalise-types --force    # apply
 */
class NormaliseContactTypes extends Command
{
    protected $signature = 'contacts:normalise-types {--force : Apply changes (default is a dry-run report)}';

    protected $description = 'Collapse contact types to the 4 fixed e-sign parents; map/drop extras (AT-79).';

    /** Name fragment => canonical esign_role. Checked in order. */
    private array $nameMap = [
        'tenant'    => 'lessee',
        'lessee'    => 'lessee',
        'landlord'  => 'lessor',
        'lessor'    => 'lessor',
        'vendor'    => 'seller',
        'seller'    => 'seller',
        'purchaser' => 'buyer',
        'buyer'     => 'buyer',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('force');
        $this->info($apply ? 'APPLYING contact-type normalisation…' : 'DRY RUN — no changes will be written. Use --force to apply.');
        $this->newLine();

        // Resolve the four canonical parents (created by the additive migration).
        $parents = ContactType::query()->canonical()->get()->keyBy('esign_role');
        $missing = array_diff(array_keys(ContactType::CANONICAL), $parents->keys()->all());
        if (!empty($missing)) {
            $this->error('Canonical parents missing: ' . implode(', ', $missing) . '. Run migrations first.');
            return self::FAILURE;
        }
        $canonicalIds = $parents->pluck('id')->all();

        $extras = ContactType::query()
            ->whereNull('deleted_at')
            ->whereNotIn('id', $canonicalIds)
            ->orderBy('name')
            ->get();

        $drops = [];      // [name]
        $maps  = [];      // [['name'=>, 'parent'=>, 'contacts'=>]]
        $unmappable = []; // [name]

        foreach ($extras as $extra) {
            $count = DB::table('contacts')->whereNull('deleted_at')->where('contact_type_id', $extra->id)->count();
            if ($count === 0) {
                $drops[] = $extra->name;
                continue;
            }
            $role = $this->resolveParentRole($extra);
            if ($role === null) {
                $unmappable[] = "{$extra->name} ({$count} contacts)";
                continue;
            }
            $maps[] = ['extra' => $extra, 'role' => $role, 'parent' => $parents[$role], 'contacts' => $count];
        }

        // ── Report ──
        if ($drops) {
            $this->warn('DROP (0 contacts, soft-deleted):');
            foreach ($drops as $n) $this->line("  • {$n}");
            $this->newLine();
        }
        if ($maps) {
            $this->warn('MAP (extra type -> parent, name kept as sub-tag):');
            foreach ($maps as $m) $this->line("  • {$m['extra']->name}  ->  {$m['parent']->name}  ({$m['contacts']} contacts)");
            $this->newLine();
        }
        $parentlessTags = DB::table('contact_tags')->whereNull('deleted_at')->whereNull('contact_type_id')->count();
        if ($parentlessTags > 0) {
            $this->warn("LEGACY TAGS without a parent: {$parentlessTags} — re-home them under a parent in Settings → Contacts. (Not auto-bucketed.)");
            $this->newLine();
        }
        if ($unmappable) {
            $this->error('UNMAPPABLE extras WITH contacts — resolve manually, then re-run:');
            foreach ($unmappable as $u) $this->line("  • {$u}");
            $this->error('Aborting: refusing to mis-bucket real contacts.');
            return self::FAILURE;
        }
        if (empty($drops) && empty($maps)) {
            $this->info('Nothing to normalise — contact types are already the 4 parents.');
            return self::SUCCESS;
        }

        if (!$apply) {
            $this->info('Dry run complete. Re-run with --force to apply.');
            return self::SUCCESS;
        }

        // ── Apply ──
        DB::transaction(function () use ($drops, $maps) {
            $now = now();

            foreach ($maps as $m) {
                $extra  = $m['extra'];
                $parent = $m['parent'];

                // Affected contacts grouped by agency (sub-tags are agency-scoped).
                $contacts = DB::table('contacts')
                    ->whereNull('deleted_at')->where('contact_type_id', $extra->id)
                    ->get(['id', 'agency_id']);

                $tagByAgency = [];
                foreach ($contacts->pluck('agency_id')->unique() as $agencyId) {
                    $aid = (int) ($agencyId ?: (DB::table('agencies')->value('id') ?? 1));
                    $tagId = DB::table('contact_tags')
                        ->where('agency_id', $aid)
                        ->where('contact_type_id', $parent->id)
                        ->where('name', $extra->name)
                        ->whereNull('deleted_at')
                        ->value('id');
                    if (!$tagId) {
                        $tagId = DB::table('contact_tags')->insertGetId([
                            'agency_id'       => $aid,
                            'contact_type_id' => $parent->id,
                            'name'            => $extra->name,
                            'color'           => $extra->color ?: '#6366f1',
                            'sort_order'      => 0,
                            'is_active'       => 1,
                            'created_at'      => $now,
                            'updated_at'      => $now,
                        ]);
                    }
                    $tagByAgency[(int) $agencyId] = $tagId;
                }

                foreach ($contacts as $c) {
                    DB::table('contact_contact_type')->insertOrIgnore([
                        'contact_id'      => $c->id,
                        'contact_type_id' => $parent->id,
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ]);
                    DB::table('contact_tag')->insertOrIgnore([
                        'contact_id'     => $c->id,
                        'contact_tag_id' => $tagByAgency[(int) $c->agency_id],
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ]);
                    // Re-point the primary mirror to the parent.
                    DB::table('contacts')->where('id', $c->id)->update([
                        'contact_type_id' => $parent->id,
                        'updated_at'      => $now,
                    ]);
                }

                // Extra type is now empty — soft-delete it.
                DB::table('contact_types')->where('id', $extra->id)->update(['deleted_at' => $now]);
            }

            // Drop zero-contact orphans.
            if ($drops) {
                DB::table('contact_types')
                    ->whereIn('name', $drops)
                    ->whereNull('deleted_at')
                    ->whereNotIn('esign_role', array_keys(ContactType::CANONICAL))
                    ->update(['deleted_at' => $now]);
            }
        });

        $this->info('Normalisation applied.');
        return self::SUCCESS;
    }

    /** Map an extra type to a canonical role via its esign_role, then its name. */
    private function resolveParentRole(ContactType $extra): ?string
    {
        if ($extra->esign_role && isset(ContactType::CANONICAL[$extra->esign_role])) {
            return $extra->esign_role;
        }
        $name = strtolower($extra->name);
        foreach ($this->nameMap as $fragment => $role) {
            if (str_contains($name, $fragment)) {
                return $role;
            }
        }
        return null;
    }
}
