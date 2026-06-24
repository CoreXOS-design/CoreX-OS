<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill / normalise `contact_property.role` so the compliance gate's
 * seller-FICA check (which reads role IN owner/seller/landlord/lessor) sees
 * correctly-roled sellers. Fixes the historic NULL/free-text rows written
 * before the role became a required canonical select.
 *
 * Modes (default = dry-run report; nothing written without --apply):
 *   --apply        write the safe fixes
 *   --infer-sole   ALSO fill a NULL role when the property has exactly ONE
 *                  linked contact (a sole contact on a listing is the seller);
 *                  listing-based: sale → seller, rental → landlord
 *
 * Categories:
 *   NORMALISE  non-null role that trims/lowercases to a canonical value
 *              (e.g. "Seller" → seller). Always safe — applied with --apply.
 *   INFER_SOLE NULL role, property has exactly 1 linked contact. Applied only
 *              with --apply --infer-sole.
 *   AMBIGUOUS  NULL/off-list role on a property with >1 contacts, or a value
 *              that doesn't map to canon. REPORTED, never auto-written.
 */
class BackfillContactPropertyRoles extends Command
{
    protected $signature = 'corex:backfill-contact-property-roles {--apply : Write changes} {--infer-sole : Also infer sole-contact NULL roles}';

    protected $description = 'Normalise contact_property.role (fix NULL/free-text seller roles for the compliance gate)';

    private const CANONICAL = ['seller', 'buyer', 'owner', 'landlord', 'lessor', 'tenant'];
    private const SELLER_SIDE = ['owner', 'seller', 'landlord', 'lessor'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $inferSole = (bool) $this->option('infer-sole');

        $normalised = 0;
        $inferred = 0;
        $ambiguous = [];

        // Only rows whose role is not already a canonical value need attention.
        $rows = DB::table('contact_property')->get();

        foreach ($rows as $row) {
            $raw = $row->role;
            $canon = is_string($raw) ? strtolower(trim($raw)) : null;

            // Already canonical — nothing to do.
            if ($canon !== null && in_array($canon, self::CANONICAL, true)) {
                // Tidy a stored variant (e.g. "Seller" / " seller ") to canon.
                if ($raw !== $canon) {
                    $this->line("NORMALISE  prop={$row->property_id} contact={$row->contact_id}  '{$raw}' → '{$canon}'");
                    if ($apply) {
                        DB::table('contact_property')->where('id', $row->id)->update(['role' => $canon]);
                    }
                    $normalised++;
                }
                continue;
            }

            $contactCount = DB::table('contact_property')->where('property_id', $row->property_id)->count();
            $listingType = DB::table('properties')->where('id', $row->property_id)->value('listing_type') ?? 'sale';
            $inferredRole = $listingType === 'rental' ? 'landlord' : 'seller';

            // A non-null value that doesn't map to canon → never guess.
            if ($canon !== null && $canon !== '') {
                $ambiguous[] = "prop={$row->property_id} contact={$row->contact_id}  off-list role='{$raw}' (needs manual review)";
                continue;
            }

            // NULL/blank role.
            if ($contactCount === 1) {
                $msg = "INFER_SOLE prop={$row->property_id} contact={$row->contact_id}  NULL → '{$inferredRole}' (sole contact, {$listingType})";
                if ($inferSole && $apply) {
                    // Only the pivot role matters to the compliance gate. The
                    // PropertySellerLink (seller-outreach) record is a separate
                    // concern and needs an auth context — left for its own flow.
                    DB::table('contact_property')->where('id', $row->id)->update(['role' => $inferredRole]);
                    $this->info($msg . '  [applied]');
                    $inferred++;
                } else {
                    $this->line($msg . ($inferSole ? '  [run with --apply]' : '  [run with --infer-sole --apply]'));
                }
                continue;
            }

            $ambiguous[] = "prop={$row->property_id} contact={$row->contact_id}  NULL role on property with {$contactCount} contacts (which is the seller?)";
        }

        $this->newLine();
        $this->info('Summary:');
        $this->line("  Normalised (variant → canonical): {$normalised}" . ($apply ? ' applied' : ' (dry-run)'));
        $this->line("  Inferred sole-contact NULL → seller: {$inferred}" . ($inferSole && $apply ? ' applied' : ' (not applied)'));
        $this->line('  Ambiguous (reported, NOT changed): ' . count($ambiguous));
        foreach ($ambiguous as $a) {
            $this->warn('  ⚠ ' . $a);
        }

        if (!$apply) {
            $this->newLine();
            $this->comment('Dry-run only. Re-run with --apply (and --infer-sole to fill sole-contact NULLs).');
        }

        return self::SUCCESS;
    }
}
