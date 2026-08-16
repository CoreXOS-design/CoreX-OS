<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Agency;
use App\Models\PerformanceSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-08-15 (Johan, HFC tenant-isolation fix, Wave 1) — locks in the fix
 * for the cross-tenant branding leak.
 *
 * Root cause: PerformanceSetting::get() resolved an agency-specific row,
 * then fell back to the GLOBAL (agency_id=NULL) row before ever reaching
 * the caller's $default. A single global row seeded with Home Finders
 * Coastal's real company_name/address/tel/ffc/logo_url meant every other
 * agency without its own override silently inherited HFC's business
 * details on printed documents (CMA, commission calculator, deal
 * settlements) — confirmed live via the audit.
 *
 * Fix: company_* keys now NEVER consult the global row — only the
 * agency's own row, falling straight to $default (the caller's own
 * Agency-model fields) when no agency row exists. Non-company_* keys
 * keep the original agency-then-global behaviour unchanged.
 *
 * Agency::create() is wrapped in Model::withoutEvents() in the tests that
 * probe get()'s fallback logic in isolation — AgencyObserver::created()
 * now ALSO auto-seeds a company_name row from the agency's own name (see
 * the dedicated seeding test below), which would otherwise mask exactly
 * the fallback path these tests exist to prove.
 */
class PerformanceSettingAgencyOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function agencyWithoutSeeding(string $name): Agency
    {
        return Model::withoutEvents(fn () => Agency::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid()]));
    }

    public function test_company_key_never_falls_back_to_the_global_row(): void
    {
        $agencyB = $this->agencyWithoutSeeding('Agency B');

        // The exact poisoned-global-row shape found live: a global row
        // holding one agency's real business details.
        PerformanceSetting::create(['agency_id' => null, 'key' => 'company_name', 'value' => 'Home Finders Coastal']);

        $result = PerformanceSetting::get('company_name', 'Agency B fallback', $agencyB->id);

        $this->assertSame('Agency B fallback', $result, 'must fall through to $default, never read the global HFC row');
        $this->assertNotSame('Home Finders Coastal', $result);
    }

    public function test_company_key_still_prefers_the_agencys_own_row_when_one_exists(): void
    {
        $agency = $this->agencyWithoutSeeding('Agency A');
        PerformanceSetting::create(['agency_id' => null, 'key' => 'company_name', 'value' => 'Home Finders Coastal']);
        PerformanceSetting::create(['agency_id' => $agency->id, 'key' => 'company_name', 'value' => 'Agency A Real Name']);

        $result = PerformanceSetting::get('company_name', 'unused default', $agency->id);

        $this->assertSame('Agency A Real Name', $result);
    }

    public function test_non_company_key_keeps_the_original_global_fallback_behaviour(): void
    {
        $agency = $this->agencyWithoutSeeding('Agency X');
        PerformanceSetting::create(['agency_id' => null, 'key' => 'vat_rate', 'value' => '15']);

        $result = PerformanceSetting::get('vat_rate', 'unused default', $agency->id);

        $this->assertSame('15', $result, 'non-company keys must still read the global row when the agency has no override');
    }

    public function test_company_key_with_no_authenticated_agency_context_returns_default_not_global(): void
    {
        PerformanceSetting::create(['agency_id' => null, 'key' => 'company_name', 'value' => 'Home Finders Coastal']);

        $result = PerformanceSetting::get('company_name', 'no-agency default', null);

        $this->assertSame('no-agency default', $result);
    }

    public function test_set_still_writes_a_proper_agency_scoped_row(): void
    {
        $agency = $this->agencyWithoutSeeding('Agency X');
        PerformanceSetting::set('company_name', 'Agency A Real Name', $agency->id);

        $this->assertDatabaseHas('performance_settings', [
            'agency_id' => $agency->id,
            'key'       => 'company_name',
            'value'     => 'Agency A Real Name',
        ]);
    }

    /**
     * Locks in the explicit Wave-1 requirement: a brand-new agency gets its
     * own company_* PerformanceSetting rows seeded from its own profile
     * fields at creation time, so Admin > Performance Settings shows
     * correct pre-filled values from day one (defense-in-depth / UX, not a
     * correctness requirement — get()'s fallback fix alone is sufficient
     * for the leak — but Johan asked for it explicitly).
     */
    public function test_agency_observer_seeds_company_settings_from_the_agencys_own_profile(): void
    {
        $agency = Agency::create([
            'name'    => 'Fresh New Agency',
            'slug'    => 'fresh-new-agency-' . uniqid(),
            'address' => '123 Fresh Street',
            'phone'   => '0110000000',
            'ffc_no'  => 'FFC12345',
        ]);

        $this->assertDatabaseHas('performance_settings', ['agency_id' => $agency->id, 'key' => 'company_name', 'value' => 'Fresh New Agency']);
        $this->assertDatabaseHas('performance_settings', ['agency_id' => $agency->id, 'key' => 'company_address', 'value' => '123 Fresh Street']);
        $this->assertDatabaseHas('performance_settings', ['agency_id' => $agency->id, 'key' => 'company_tel', 'value' => '0110000000']);
        $this->assertDatabaseHas('performance_settings', ['agency_id' => $agency->id, 'key' => 'company_ffc', 'value' => 'FFC12345']);
    }

    public function test_agency_observer_seed_skips_blank_fields_gracefully(): void
    {
        // No address/phone/ffc_no set — must not seed empty-string rows.
        $agency = Agency::create(['name' => 'Bare Agency', 'slug' => 'bare-agency-' . uniqid()]);

        $this->assertDatabaseHas('performance_settings', ['agency_id' => $agency->id, 'key' => 'company_name', 'value' => 'Bare Agency']);
        $this->assertDatabaseMissing('performance_settings', ['agency_id' => $agency->id, 'key' => 'company_address']);
    }
}
