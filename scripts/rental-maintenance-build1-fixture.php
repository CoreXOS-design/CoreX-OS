<?php

/**
 * Throwaway fixture creator/cleaner for scripts/rental-maintenance-build1-smoke.mjs
 * (.ai/specs/rental-work-orders.md §17.4 / §17.5 / §17.11, maintenance flow Build 1).
 *
 * Same convention as scripts/rental-click-through-fixture.php: every run creates its OWN throwaway agency (never an existing
 * agency, never job card 1), users, rental property with a landlord, crew and one DRAFT job card — and --cleanup soft-deletes
 * all of it (never a hard delete), whether the smoke passed or failed. Every email address is @example.invalid; the landlord
 * and crew can never receive real mail.
 *
 * Run it as the web user so files it ever creates are readable by php-fpm:
 *   sudo -u www-data php8.2 scripts/rental-maintenance-build1-fixture.php --app-root=/corex-qa1 --create
 *       -> prints ONE JSON object (every id the smoke needs, plus the raw crew-page token)
 *   sudo -u www-data php8.2 scripts/rental-maintenance-build1-fixture.php --app-root=/corex-qa1 --cleanup='<json from --create>'
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
use App\Models\Property;
use App\Models\RentalCatalogueItem;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalCrew;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalVatType;
use App\Models\RentalWorkOrderSetting;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Rentals\RentalJobCardService;
use App\Services\Rentals\RentalSecureAccessTokenService;

/** QA1 runs without dev dependencies (no faker), so a factory is not available: create the user row directly. */
$makeUser = fn (array $a) => User::forceCreate($a + [
    'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(32)),
    'email_verified_at' => now(), 'first_login_at' => now(), 'is_active' => true, 'remember_token' => \Illuminate\Support\Str::random(10),
]);

if (isset($opts['create'])) {
    $stamp = 'zz-b1-smoke-' . date('YmdHis') . '-' . substr(uniqid(), -5);

    $agency = Agency::create(['name' => 'ZZ Build1 Smoke Co', 'slug' => $stamp, 'vat_registered' => true, 'vat_capture_mode' => Agency::VAT_CAPTURE_EXCL]);
    $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Smoke Branch']);
    \App\Models\PerformanceSetting::set('vat_rate', '15', $agency->id);
    RentalVatType::seedDefaultsFor($agency->id);
    RentalCatalogueItemType::seedDefaultsFor($agency->id);
    RentalCatalogueUnit::seedDefaultsFor($agency->id);
    RentalWorkOrderSetting::create(['agency_id' => $agency->id, 'capture_prices_on_job_cards' => true, 'no_approval_spend_threshold' => 1000000]);

    // The office: a full admin (price + view costs), and a plain user who may use job cards but sees no costs and sets no prices.
    $admin = $makeUser(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'name' => 'Smoke Admin', 'email' => $stamp . '-admin@example.invalid']);
    $clerkRole = 'zzsmokeclerk' . substr(uniqid(), -5);
    Role::firstOrCreate(['name' => $clerkRole, 'agency_id' => $agency->id], ['label' => 'Smoke Clerk']);
    foreach (['rental_job_cards.view', 'rental_job_cards.create', 'rental_job_cards.send_quote'] as $key) {
        RolePermission::updateOrCreate(['role' => $clerkRole, 'permission_key' => $key, 'agency_id' => $agency->id], ['scope' => 'all']);
    }
    $clerk = $makeUser(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => $clerkRole, 'name' => 'Smoke Clerk', 'email' => $stamp . '-clerk@example.invalid']);

    $property = Property::forceCreate([
        'agency_id' => $agency->id, 'agent_id' => $admin->id, 'branch_id' => $branch->id,
        'title' => '1 Smoke Street, Test Town', 'status' => 'active', 'listing_type' => 'rental',
    ]);
    $landlord = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Smoke', 'last_name' => 'Landlord', 'email' => $stamp . '-landlord@example.invalid']);
    ContactPropertyLinker::link($landlord->id, $property->id, 'landlord');

    $crew = RentalCrew::create(['agency_id' => $agency->id, 'name' => 'Smoke Crew', 'email' => $stamp . '-crew@example.invalid', 'phone' => '082 000 0000', 'created_by_user_id' => $admin->id]);

    $item = RentalCatalogueItem::create([
        'agency_id' => $agency->id,
        'rental_catalogue_item_type_id' => RentalCatalogueItemType::where('agency_id', $agency->id)->where('kind', 'part')->firstOrFail()->id,
        'code' => 'SMOKE-TAP', 'description' => 'Tap washer set', 'default_price' => null, 'default_cost' => 35.00, 'sort_order' => 1,
        'rental_catalogue_unit_id' => RentalCatalogueUnit::where('agency_id', $agency->id)->where('name', 'Each')->firstOrFail()->id,
        'created_by_user_id' => $admin->id,
    ]);

    $card = app(RentalJobCardService::class)->createStandalone(['property_id' => $property->id, 'title' => 'ZZ Build 1 smoke - leaking tap', 'access_notes' => 'Smoke test - no real tenant'], $admin);
    $card->forceFill(['rental_crew_id' => $crew->id])->save();
    $crewPageToken = app(RentalSecureAccessTokenService::class)->issueForCrew($crew, $admin)['raw_token'];

    echo json_encode([
        'agency_id' => $agency->id, 'branch_id' => $branch->id, 'admin_id' => $admin->id, 'clerk_id' => $clerk->id, 'clerk_role' => $clerkRole,
        'property_id' => $property->id, 'landlord_id' => $landlord->id, 'crew_id' => $crew->id, 'item_id' => $item->id,
        'card_id' => $card->id, 'crew_page_token' => $crewPageToken,
    ]) . "\n";
    exit(0);
}

// ── cleanup: soft-delete everything named in the JSON (idempotent; safe on a partial --create's output) ──
$ids = json_decode($opts['cleanup'], true) ?: [];
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
$agencyId = $ids['agency_id'] ?? null;
if ($agencyId) {
    // every job card of the throwaway agency (the smoke made exactly one) — archived via the real model so history shows it
    foreach (RentalJobCard::withoutGlobalScopes()->where('agency_id', $agencyId)->get() as $c) {
        if (! $c->trashed()) {
            $c->delete();
            $c->logUpdate('archived', null, 'Build 1 smoke fixture cleaned up');
            $done['job_cards'] = ($done['job_cards'] ?? 0) + 1;
        }
    }
    $soft('crews', RentalCrew::withoutGlobalScopes()->where('agency_id', $agencyId));
    $soft('catalogue_items', RentalCatalogueItem::withoutGlobalScopes()->where('agency_id', $agencyId));
    $soft('properties', Property::withoutGlobalScopes()->where('agency_id', $agencyId));
    $soft('contacts', Contact::withoutGlobalScopes()->where('agency_id', $agencyId));
    \App\Models\RentalSecureAccessToken::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    // Users are deactivated, not deleted: the app (rightly) refuses to delete an agency's only admin.
    $done['users_deactivated'] = User::withoutGlobalScopes()->where('agency_id', $agencyId)->update(['is_active' => false]);
}
echo json_encode(['cleaned' => $done]) . "\n";
