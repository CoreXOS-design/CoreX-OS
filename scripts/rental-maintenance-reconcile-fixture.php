<?php

/**
 * Throwaway fixture creator/cleaner for scripts/rental-maintenance-reconcile-walk.mjs
 * (.ai/specs/rental-work-orders.md §17, maintenance flow reconciliation walk, 7 Oct 2026).
 *
 * Same convention as scripts/rental-maintenance-build1-fixture.php: every run creates its OWN throwaway agency (never an existing
 * agency, never job card 1) — an admin, a rental property with a landlord, a tenant on an active lease, a crew and ONE reported
 * fault — and --cleanup soft-deletes all of it (never a hard delete), whether the walk passed or failed. Every email address is
 * @example.invalid; mail only ever reaches QA1's Mailpit guard and nothing can leave the box.
 *
 * The owner's work terms on the property are set the way the walk needs them: no-approval limit R500, tolerance 10 %.
 *
 *   sudo -u www-data php8.2 scripts/rental-maintenance-reconcile-fixture.php --app-root=/corex-qa1 --create
 *       -> prints ONE JSON object (every id the walk needs)
 *   sudo -u www-data php8.2 scripts/rental-maintenance-reconcile-fixture.php --app-root=/corex-qa1 --cleanup='<json from --create>'
 */

$opts = getopt('', ['app-root:', 'create', 'cleanup:']);
if (empty($opts['app-root']) || (! isset($opts['create']) && empty($opts['cleanup']))) {
    fwrite(STDERR, "Usage: --app-root=<path> (--create | --cleanup=<json>)\n");
    exit(2);
}

$appRoot = rtrim($opts['app-root'], '/');
chdir($appRoot);
require $appRoot . '/vendor/autoload.php';
$app = require $appRoot . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalCrew;
use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalVatType;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;

/** QA1 runs without dev dependencies (no faker): create the user row directly. */
$makeUser = fn (array $a) => User::forceCreate($a + [
    'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(32)),
    'email_verified_at' => now(), 'first_login_at' => now(), 'is_active' => true, 'remember_token' => \Illuminate\Support\Str::random(10),
]);

if (isset($opts['create'])) {
    $stamp = 'zz-recon-' . date('YmdHis') . '-' . substr(uniqid(), -5);

    $agency = Agency::create(['name' => 'ZZ Reconcile Walk Co', 'slug' => $stamp, 'vat_registered' => false]);
    $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Walk Branch', 'code' => 'ZW' . $agency->id, 'is_active' => true]);
    RentalVatType::seedDefaultsFor($agency->id);
    RentalCatalogueItemType::seedDefaultsFor($agency->id);
    RentalCatalogueUnit::seedDefaultsFor($agency->id);
    RentalWorkOrderSetting::create(['agency_id' => $agency->id, 'capture_prices_on_job_cards' => true, 'no_approval_spend_threshold' => 500]);

    $admin = $makeUser(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'name' => 'Walk Admin', 'email' => $stamp . '-admin@example.invalid']);

    $property = Property::forceCreate([
        'agency_id' => $agency->id, 'agent_id' => $admin->id, 'branch_id' => $branch->id,
        'title' => '7 Walk Street, Test Town', 'status' => 'active', 'listing_type' => 'rental',
        // the owner's agreed work terms (§17.6): work up to R500 needs no approval; extra work up to 10 % over an approved quote is automatic
        'rental_no_approval_spend_threshold' => 500, 'rental_variation_tolerance_percent' => 10,
    ]);
    $landlord = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Walk', 'last_name' => 'Landlord', 'email' => $stamp . '-landlord@example.invalid']);
    ContactPropertyLinker::link($landlord->id, $property->id, 'landlord');

    $lease = Lease::withoutGlobalScopes()->create([
        'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
        'status' => 'active', 'rental_amount' => 9500, 'deposit_amount' => 9500,
        'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
    ]);
    $tenant = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Walk', 'last_name' => 'Tenant', 'email' => $stamp . '-tenant@example.invalid']);
    LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);

    $crew = RentalCrew::create(['agency_id' => $agency->id, 'name' => 'Walk Crew', 'email' => $stamp . '-crew@example.invalid', 'phone' => '082 000 0000', 'created_by_user_id' => $admin->id]);

    $fault = RentalFaultReport::create([
        'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id, 'lease_id' => $lease->id,
        'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT, 'reported_by_contact_id' => $tenant->id,
        'reported_channel' => RentalFaultReport::CHANNEL_PHONE, 'captured_by_user_id' => $admin->id,
        'title' => 'ZZ Walk - geyser not heating', 'description' => 'No hot water since Monday (reconciliation walk, throwaway)',
        'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED,
        'reported_at' => now(), 'created_by_user_id' => $admin->id,
    ]);

    echo json_encode([
        'stamp' => $stamp, 'agency_id' => $agency->id, 'branch_id' => $branch->id, 'admin_id' => $admin->id,
        'property_id' => $property->id, 'landlord_id' => $landlord->id, 'lease_id' => $lease->id, 'tenant_id' => $tenant->id,
        'tenant_email' => $tenant->email, 'landlord_email' => $landlord->email, 'crew_id' => $crew->id, 'crew_email' => $crew->email, 'fault_id' => $fault->id,
    ]) . "\n";
    exit(0);
}

// ── cleanup: soft-delete everything of the throwaway agency (idempotent; safe on a partial --create's output) ──
$ids = json_decode($opts['cleanup'], true) ?: [];
$agencyId = $ids['agency_id'] ?? null;
$done = [];
$soft = function (string $label, $query) use (&$done) {
    $n = 0;
    foreach ($query->get() as $m) {
        if (method_exists($m, 'trashed') && ! $m->trashed()) {
            $m->delete();
            $n++;
        }
    }
    $done[$label] = $n;
};
if ($agencyId) {
    foreach (RentalJobCard::withoutGlobalScopes()->where('agency_id', $agencyId)->get() as $c) {
        if (! $c->trashed()) {
            $c->delete();
            $c->logUpdate('archived', null, 'Reconcile walk fixture cleaned up');
            $done['job_cards'] = ($done['job_cards'] ?? 0) + 1;
        }
    }
    $soft('work_orders', RentalWorkOrder::withoutGlobalScopes()->where('agency_id', $agencyId));
    $soft('fault_reports', RentalFaultReport::withoutGlobalScopes()->where('agency_id', $agencyId));
    $soft('leases', Lease::withoutGlobalScopes()->where('agency_id', $agencyId));
    $soft('crews', RentalCrew::withoutGlobalScopes()->where('agency_id', $agencyId));
    $soft('properties', Property::withoutGlobalScopes()->where('agency_id', $agencyId));
    $soft('contacts', Contact::withoutGlobalScopes()->where('agency_id', $agencyId));
    \App\Models\RentalSecureAccessToken::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    // Users are deactivated, not deleted: the app (rightly) refuses to delete an agency's only admin.
    $done['users_deactivated'] = User::withoutGlobalScopes()->where('agency_id', $agencyId)->update(['is_active' => false]);
}
echo json_encode(['cleaned' => $done]) . "\n";
