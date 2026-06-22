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
 *   - 0 contacts   -> soft-delete (drop the orphan).
 *   - maps to a role (by esign_role or name: seller/buyer/lessor/lessee, plus
 *     owner->seller, investor->buyer) -> preserve the old name as a sub-tag
 *     under that parent, re-point each contact (pivot + sub-tag + primary
 *     mirror), then soft-delete the extra type.
 *   - non-transaction (Lead, Other, Attorney, Agent, …):
 *       * without --preserve-unmappable -> ABORT (never silently mis-bucket).
 *       * with    --preserve-unmappable -> keep the name as an UNSORTED
 *         (parent-less) tag on each contact and CLEAR the contact's transaction
 *         type (it is not a buyer/seller/lessor/lessee). The label is preserved
 *         and surfaced under Settings → Contacts → Unsorted for later sorting.
 *
 *   php artisan contacts:normalise-types                                  # dry-run
 *   php artisan contacts:normalise-types --force                          # apply (aborts on non-transaction types)
 *   php artisan contacts:normalise-types --force --preserve-unmappable    # apply, keeping non-transaction types as unsorted tags
 */
class NormaliseContactTypes extends Command
{
    protected $signature = 'contacts:normalise-types
        {--force : Apply changes (default is a dry-run report)}
        {--preserve-unmappable : Keep non-transaction types (Lead, Other, Attorney, …) as unsorted parent-less tags instead of aborting}';

    protected $description = 'Collapse contact types to the 4 fixed e-sign parents; map/drop/preserve extras (AT-79).';

    /** Name fragment => canonical esign_role. Checked in order. */
    private array $nameMap = [
        'tenant'    => 'lessee',
        'lessee'    => 'lessee',
        'landlord'  => 'lessor',
        'lessor'    => 'lessor',
        'vendor'    => 'seller',
        'seller'    => 'seller',
        'owner'     => 'seller',   // a property owner is the seller (matches e-sign)
        'purchaser' => 'buyer',
        'investor'  => 'buyer',    // an investor is acquiring -> buyer
        'buyer'     => 'buyer',
    ];

    public function handle(): int
    {
        $apply    = (bool) $this->option('force');
        $preserve = (bool) $this->option('preserve-unmappable');
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

        $drops = [];        // [name]
        $maps  = [];        // [['extra'=>, 'role'=>, 'parent'=>, 'contacts'=>]]
        $unmappable = [];   // [['extra'=>, 'contacts'=>]]

        foreach ($extras as $extra) {
            $count = DB::table('contacts')->whereNull('deleted_at')->where('contact_type_id', $extra->id)->count();
            if ($count === 0) {
                $drops[] = $extra->name;
                continue;
            }
            $role = $this->resolveParentRole($extra);
            if ($role === null) {
                $unmappable[] = ['extra' => $extra, 'contacts' => $count];
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
        if ($unmappable) {
            if ($preserve) {
                $this->warn('PRESERVE (non-transaction type -> unsorted tag, transaction type cleared):');
                foreach ($unmappable as $u) $this->line("  • {$u['extra']->name}  ({$u['contacts']} contacts)");
                $this->newLine();
            } else {
                $this->error('UNMAPPABLE extras WITH contacts — pass --preserve-unmappable to keep them as unsorted tags, or rename/map them, then re-run:');
                foreach ($unmappable as $u) $this->line("  • {$u['extra']->name} ({$u['contacts']} contacts)");
                $this->error('Aborting: refusing to mis-bucket real contacts.');
                return self::FAILURE;
            }
        }

        $parentlessTags = DB::table('contact_tags')->whereNull('deleted_at')->whereNull('contact_type_id')->count();
        if ($parentlessTags > 0) {
            $this->warn("Existing legacy tags without a parent: {$parentlessTags} (shown under Settings → Contacts → Unsorted).");
            $this->newLine();
        }

        if (empty($drops) && empty($maps) && empty($unmappable)) {
            $this->info('Nothing to normalise — contact types are already the 4 parents.');
            return self::SUCCESS;
        }

        if (!$apply) {
            $this->info('Dry run complete. Re-run with --force' . ($preserve ? ' --preserve-unmappable' : '') . ' to apply.');
            return self::SUCCESS;
        }

        // ── Apply ──
        DB::transaction(function () use ($drops, $maps, $unmappable, $preserve) {
            $now = now();

            // Mappable extras -> sub-tag under the parent, contacts re-pointed.
            foreach ($maps as $m) {
                $this->repointContacts($m['extra'], $m['parent']->id, $now);
                DB::table('contact_types')->where('id', $m['extra']->id)->update(['deleted_at' => $now]);
            }

            // Non-transaction extras -> unsorted (parent-less) tag, type cleared.
            if ($preserve) {
                foreach ($unmappable as $u) {
                    $this->repointContacts($u['extra'], null, $now);
                    DB::table('contact_types')->where('id', $u['extra']->id)->update(['deleted_at' => $now]);
                }
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

    /**
     * Turn every contact on $extra into a tag named after $extra under $parentId
     * (null = unsorted), attach it, and set the primary mirror to $parentId
     * (null clears the transaction type). Sub-tags are agency-scoped, so one tag
     * is created/reused per agency.
     */
    private function repointContacts(ContactType $extra, ?int $parentId, $now): void
    {
        $contacts = DB::table('contacts')
            ->whereNull('deleted_at')->where('contact_type_id', $extra->id)
            ->get(['id', 'agency_id']);

        $tagByAgency = [];
        foreach ($contacts->pluck('agency_id')->unique() as $agencyId) {
            $aid = (int) ($agencyId ?: (DB::table('agencies')->value('id') ?? 1));
            $lookup = DB::table('contact_tags')
                ->where('agency_id', $aid)
                ->where('name', $extra->name)
                ->whereNull('deleted_at');
            $parentId === null
                ? $lookup->whereNull('contact_type_id')
                : $lookup->where('contact_type_id', $parentId);
            $tagId = $lookup->value('id');

            if (!$tagId) {
                $tagId = DB::table('contact_tags')->insertGetId([
                    'agency_id'       => $aid,
                    'contact_type_id' => $parentId,
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
            if ($parentId !== null) {
                DB::table('contact_contact_type')->insertOrIgnore([
                    'contact_id'      => $c->id,
                    'contact_type_id' => $parentId,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
            }
            DB::table('contact_tag')->insertOrIgnore([
                'contact_id'     => $c->id,
                'contact_tag_id' => $tagByAgency[(int) $c->agency_id],
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
            DB::table('contacts')->where('id', $c->id)->update([
                'contact_type_id' => $parentId, // null = transaction type cleared
                'updated_at'      => $now,
            ]);
        }
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
