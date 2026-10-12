<?php

declare(strict_types=1);

namespace Tests\Feature\TitleDeeds;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\User;
use App\Support\Audit\AuditContext;
use App\Services\PermissionService;
use App\Services\TitleDeeds\TitleDeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared fixtures for the Title Deed Register tests (AT-450). Real-shaped South African data: street addresses, +27 mobiles,
 * owners that share an email, owners with no email, an archived contact. NOTE `admin` is an OWNER role in this app - it
 * bypasses permission checks; denial tests use a role of their own with explicit grants.
 */
abstract class TitleDeedTestCase extends TestCase
{
    use RefreshDatabase;

    protected int $agencyId;
    protected Branch $branchA;
    protected Branch $branchB;
    protected User $admin;
    protected User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        AuditContext::reset();
        Mail::fake();
        config(['mail.non_production_redirect' => 'redirect@example.test']);

        $this->agencyId = $this->makeAgency('Coastal');
        $this->branchA = Branch::create(['agency_id' => $this->agencyId, 'name' => 'Margate']);
        $this->branchB = Branch::create(['agency_id' => $this->agencyId, 'name' => 'Southbroom']);

        $this->admin = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchA->id, 'role' => 'admin']);
        $this->agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'name' => 'Anele Dlamini']);
    }

    protected function makeAgency(string $name): int
    {
        return (int) DB::table('agencies')->insertGetId([
            'name' => $name . ' ' . Str::random(4), 'slug' => Str::slug($name) . '-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function property(string $address = '14 Marine Drive', string $suburb = 'Shelly Beach', ?int $agencyId = null, ?int $branchId = null, ?User $agent = null): Property
    {
        return Property::create([
            'agency_id' => $agencyId ?? $this->agencyId,
            'agent_id' => ($agent ?? $this->agent)->id,
            'branch_id' => $branchId ?? $this->branchA->id,
            'title' => $address . ', ' . $suburb, 'address' => $address, 'suburb' => $suburb,
            'status' => 'active', 'property_type' => 'House', 'price' => 1_950_000,
        ]);
    }

    protected function contact(string $first, string $last, ?string $email = 'auto', ?string $phone = 'auto', ?int $agencyId = null): Contact
    {
        $email = $email === 'auto' ? strtolower($first . '.' . $last) . '@example.test' : $email;
        $phone = $phone === 'auto' ? '082 ' . random_int(100, 999) . ' ' . random_int(1000, 9999) : $phone;

        $branchId = $agencyId && $agencyId !== $this->agencyId
            ? Branch::create(['agency_id' => $agencyId, 'name' => 'Branch ' . Str::random(4)])->id
            : $this->branchA->id;

        return Contact::create([
            'agency_id' => $agencyId ?? $this->agencyId, 'branch_id' => $branchId, 'first_name' => $first, 'last_name' => $last,
            'email' => $email, 'phone' => $phone,
        ]);
    }

    /** A deed with two owners by default, created through the real service. */
    protected function deed(array $owners = [], array $data = [], ?User $by = null): TitleDeed
    {
        $by ??= $this->admin;
        if ($owners === []) {
            $owners = [$this->contact('Thandi', 'Mkhize'), $this->contact('Sipho', 'Mkhize')];
        }
        $property = $data['_property'] ?? $this->property();
        unset($data['_property']);
        // A contact created a moment ago counts as "details changed recently" (that is the rule) - so the fixtures are aged
        // unless a test is specifically about the rule.
        if (($data['_age'] ?? true) !== false) {
            $this->ageContactDetails(...$owners);
        }
        unset($data['_age']);

        return app(TitleDeedService::class)->create($by, array_merge([
            'property_id' => $property->id,
            'owner_ids' => collect($owners)->pluck('id')->all(),
            'agent_id' => $this->agent->id,
            'branch_id' => $this->branchA->id,
            'attorney_firm' => 'Shepstone & Wylie',
            'received_at' => now()->subDays(3)->toDateString(),
            'storage_location' => 'Safe 2, top shelf',
            'notes' => null,
        ], $data));
    }

    /** A user with a role of their own holding EXACTLY these permissions; the .view scope is applied to title_deeds.view. */
    protected function userWith(array $permissions, string $scope = 'all', ?int $branchId = null, ?int $agencyId = null, string $name = 'Staff'): User
    {
        $agencyId ??= $this->agencyId;
        $role = 'td_role_' . Str::random(6);
        Role::create(['name' => $role, 'label' => $role, 'agency_id' => $agencyId]);
        foreach ($permissions as $key) {
            RolePermission::create([
                'role' => $role, 'permission_key' => $key,
                'scope' => $key === 'title_deeds.view' ? $scope : null,
                'agency_id' => $agencyId,
            ]);
        }
        PermissionService::clearCache();

        return User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $branchId ?? $this->branchA->id, 'role' => $role, 'name' => $name . ' ' . Str::random(3)]);
    }

    protected function allPermissions(): array
    {
        return ['access_title_deeds', 'title_deeds.view', 'title_deeds.create', 'title_deeds.edit', 'title_deeds.archive',
            'title_deeds.notify', 'title_deeds.handover', 'title_deeds.override', 'title_deeds.manage_settings'];
    }

    protected function pngDataUri(): string
    {
        $img = imagecreatetruecolor(40, 20);
        ob_start();
        imagepng($img);
        $bin = (string) ob_get_clean();
        imagedestroy($img);

        return 'data:image/png;base64,' . base64_encode($bin);
    }

    /** A blank slate for "contact details changed recently": push every identifier row and audit row back in time. */
    protected function ageContactDetails(Contact ...$contacts): void
    {
        $old = now()->subDays(60);
        foreach ($contacts as $c) {
            DB::table('contact_emails')->where('contact_id', $c->id)->update(['created_at' => $old, 'updated_at' => $old]);
            DB::table('contact_phones')->where('contact_id', $c->id)->update(['created_at' => $old, 'updated_at' => $old]);
            DB::table('contact_audit_log')->where('contact_id', $c->id)->delete();
        }
    }
}
