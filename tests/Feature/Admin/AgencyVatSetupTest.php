<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalVatType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Agency VAT set-up (2026-10-05) — reuses agencies.vat_registered/vat_no
 * (already existed, had no edit UI) and PerformanceSetting 'vat_rate'
 * (already existed, unchanged). Adds the missing edit control, the new
 * vat_capture_mode column, an audit stamp, and the agency-maintained VAT
 * types list. Proves: save works, vat_no required when registered, the
 * audit stamp records who/when, a sibling form sharing update() never
 * silently flips VAT registration off, and VAT type CRUD is agency-isolated.
 */
final class AgencyVatSetupTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'VAT Setup Agency', 'slug' => 'vat-setup-' . uniqid()]);
        Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'admin']);
    }

    private function baseCompanyPayload(array $overrides = []): array
    {
        return array_merge([
            'trading_name' => 'Test Trading Name',
        ], $overrides);
    }

    public function test_registering_for_vat_requires_a_vat_number(): void
    {
        $resp = $this->actingAs($this->admin)->put(route('admin.company-settings.update', $this->agency), $this->baseCompanyPayload([
            'vat_registered' => '1',
        ]));

        $resp->assertSessionHasErrors('vat_no');
        $this->assertFalse($this->agency->fresh()->vat_registered);
    }

    public function test_saving_vat_set_up_persists_and_stamps_the_audit_trail(): void
    {
        $resp = $this->actingAs($this->admin)->put(route('admin.company-settings.update', $this->agency), $this->baseCompanyPayload([
            'vat_no' => '4123456789',
            'vat_registered' => '1',
            'vat_capture_mode' => 'incl',
        ]));

        $resp->assertRedirect();
        $this->agency->refresh();
        $this->assertTrue($this->agency->vat_registered);
        $this->assertSame('4123456789', $this->agency->vat_no);
        $this->assertSame('incl', $this->agency->vat_capture_mode);
        $this->assertNotNull($this->agency->vat_settings_updated_at);
        $this->assertSame($this->admin->id, $this->agency->vat_settings_updated_by_user_id);
    }

    public function test_default_capture_mode_is_excl(): void
    {
        $this->actingAs($this->admin)->put(route('admin.company-settings.update', $this->agency), $this->baseCompanyPayload([
            'vat_no' => '4123456789',
            'vat_registered' => '1',
        ]))->assertRedirect();

        $this->assertSame(Agency::VAT_CAPTURE_EXCL, $this->agency->fresh()->vat_capture_mode);
    }

    /** A form that shares update() but never renders vat_registered must never silently flip it off. */
    public function test_a_request_without_vat_registered_present_never_touches_it(): void
    {
        $this->agency->update([
            'vat_registered' => true, 'vat_no' => '4123456789', 'vat_capture_mode' => 'incl',
        ]);

        // Simulates the Branding tab's own form — same action, no VAT fields rendered at all.
        $this->actingAs($this->admin)->put(route('admin.company-settings.update', $this->agency), [
            'sidebar_color' => '#112233',
        ])->assertRedirect();

        $fresh = $this->agency->fresh();
        $this->assertTrue($fresh->vat_registered);
        $this->assertSame('incl', $fresh->vat_capture_mode);
    }

    public function test_unregistering_vat_is_allowed_without_a_vat_number(): void
    {
        $this->agency->update(['vat_registered' => true, 'vat_no' => '4123456789']);

        $this->actingAs($this->admin)->put(route('admin.company-settings.update', $this->agency), $this->baseCompanyPayload([
            'vat_no' => '4123456789',
            // vat_registered omitted → unchecked checkbox posts '0' via the hidden field in the real form;
            // simulate that explicitly here.
            'vat_registered' => '0',
        ]))->assertRedirect()->assertSessionDoesntHaveErrors();

        $this->assertFalse($this->agency->fresh()->vat_registered);
    }

    // ── VAT types — agency-maintained list ────────────────────────────────

    public function test_vat_types_seed_standard_none_and_custom(): void
    {
        RentalVatType::seedDefaultsFor($this->agency->id);

        $types = RentalVatType::where('agency_id', $this->agency->id)->orderBy('sort_order')->get();
        $this->assertCount(3, $types);
        $this->assertSame(['Standard VAT', 'No VAT', 'Custom'], $types->pluck('name')->all());
        $this->assertTrue($types->firstWhere('name', 'Standard VAT')->is_default);
    }

    public function test_adding_a_vat_type_and_making_it_default(): void
    {
        RentalVatType::seedDefaultsFor($this->agency->id);

        $this->actingAs($this->admin)->post(route('admin.vat-types.store', $this->agency), [
            'name' => 'Zero-rated', 'rate_mode' => 'fixed', 'fixed_rate' => 0,
        ])->assertRedirect();

        $zeroRated = RentalVatType::where('agency_id', $this->agency->id)->where('name', 'Zero-rated')->firstOrFail();
        $this->assertFalse($zeroRated->is_default);

        $this->actingAs($this->admin)->patch(route('admin.vat-types.default', [$this->agency, $zeroRated]))->assertRedirect();

        $this->assertTrue($zeroRated->fresh()->is_default);
        // Exactly one default — the old one (Standard VAT) is unset.
        $this->assertSame(1, RentalVatType::where('agency_id', $this->agency->id)->where('is_default', true)->count());
    }

    public function test_archiving_a_vat_type_is_a_soft_delete_and_restorable(): void
    {
        RentalVatType::seedDefaultsFor($this->agency->id);
        $custom = RentalVatType::where('agency_id', $this->agency->id)->where('name', 'Custom')->firstOrFail();

        $this->actingAs($this->admin)->delete(route('admin.vat-types.archive', [$this->agency, $custom]))->assertRedirect();
        $this->assertSoftDeleted('rental_vat_types', ['id' => $custom->id]);

        $this->actingAs($this->admin)->post(route('admin.vat-types.restore', [$this->agency, $custom->id]))->assertRedirect();
        $this->assertDatabaseHas('rental_vat_types', ['id' => $custom->id, 'deleted_at' => null]);
    }

    public function test_vat_types_are_agency_isolated(): void
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        RentalVatType::seedDefaultsFor($this->agency->id);
        RentalVatType::seedDefaultsFor($other->id);

        $theirType = RentalVatType::where('agency_id', $other->id)->first();

        // An admin of $this->agency cannot act on the other agency's VAT type via its own agency route.
        $resp = $this->actingAs($this->admin)->put(route('admin.vat-types.update', [$this->agency, $theirType]), [
            'name' => 'Hijacked', 'rate_mode' => $theirType->rate_mode,
        ]);
        $resp->assertStatus(404);
        $this->assertNotSame('Hijacked', $theirType->fresh()->name);
    }
}
