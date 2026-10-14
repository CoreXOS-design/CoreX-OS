<?php

declare(strict_types=1);

namespace Tests\Feature\Agencies;

use App\Http\Controllers\Payroll\PayrollRunController;
use App\Models\Agency;
use App\Models\Payroll\PayrollEarningType;
use App\Models\Payroll\PayrollEmployee;
use App\Models\Payroll\PayrollEmployeeEarning;
use App\Models\Payroll\PayrollPayslip;
use App\Models\Payroll\PayrollRun;
use App\Models\User;
use App\Support\AgencyShortCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Document numbers carry the AGENCY's own letters, never another agency's. Payslips used to start with a literal "HFC-"
 * (PayrollRunController), so a second agency's payslips said HFC. The letters are now the agency's short code
 * (agencies.short_code): worked out from the name when the agency is created, editable in Company Settings, the Setup
 * Wizard identity step and Admin > Agencies. Home Finders Coastal works out to HFC, so its numbering is unchanged.
 */
final class AgencyShortCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        \App\Models\Role::clearCache();
        parent::tearDown();
    }

    // ── the code itself ────────────────────────────────────────────────────────────────

    public function test_a_code_is_worked_out_from_the_name(): void
    {
        self::assertSame('HFC', AgencyShortCode::fromName('Home Finders Coastal'));
        self::assertSame('HFC', AgencyShortCode::fromName('Home Finders Coastal (Pty) Ltd'));
        self::assertSame('CTR', AgencyShortCode::fromName('Cape Town Rentals'));
        self::assertSame('CTR', AgencyShortCode::fromName('The Cape Town Rentals CC'));
        self::assertSame('SR', AgencyShortCode::fromName('Smith & Realty'));
        self::assertSame('ACM', AgencyShortCode::fromName('Acme'), 'a one-word name uses its first three letters');
        self::assertSame('AG', AgencyShortCode::fromName(''), 'never empty');
        self::assertSame('AG', AgencyShortCode::fromName(null));
    }

    public function test_a_typed_code_is_cleaned_and_a_bad_one_is_not_a_code(): void
    {
        self::assertSame('CTR', AgencyShortCode::clean(' c-t.r '));
        self::assertSame('CPT12', AgencyShortCode::clean('cpt/12'));
        self::assertSame('ABCDEFGHIJ', AgencyShortCode::clean('abcdefghijklmnop'), 'ten characters at most');
        self::assertNull(AgencyShortCode::clean('x'), 'too short');
        self::assertNull(AgencyShortCode::clean('--'));
        self::assertNull(AgencyShortCode::clean(null));
    }

    public function test_every_new_agency_gets_a_code_and_two_agencies_never_share_one(): void
    {
        $hfc = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $other = Agency::create(['name' => 'Harbour Front Coastal', 'slug' => 'hfc2-' . uniqid()]);
        $third = Agency::create(['name' => 'Hilltop Flats Cape', 'slug' => 'hfc3-' . uniqid()]);

        self::assertSame('HFC', $hfc->fresh()->short_code, "Home Finders Coastal's numbering is exactly what it was");
        self::assertSame('HFC2', $other->fresh()->short_code, 'a clash gets a number added');
        self::assertSame('HFC3', $third->fresh()->short_code);
    }

    public function test_a_typed_code_is_kept_and_a_blank_one_never_empties_it(): void
    {
        $agency = Agency::create(['name' => 'Cape Town Rentals', 'slug' => 'ctr-' . uniqid(), 'short_code' => 'cpt']);
        self::assertSame('CPT', $agency->fresh()->short_code);

        $agency->update(['short_code' => null]);
        self::assertSame('CTR', $agency->fresh()->short_code, 'blank = worked out from the name again, never left empty');

        $agency->update(['name' => 'Totally Different Name']);
        self::assertSame('CTR', $agency->fresh()->short_code, 'renaming the agency does not renumber its documents');
    }

    // ── payslips ──────────────────────────────────────────────────────────────────────

    /** @return array{0:int,1:User,2:int} agency id, actor, employee id */
    private function payrollAgency(string $name): array
    {
        $agency = Agency::create(['name' => $name, 'slug' => Str::slug($name) . '-' . Str::random(5)]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agency->id, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $actor = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branchId, 'role' => 'admin', 'is_active' => true]);
        $basic = PayrollEarningType::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'code' => 'basic', 'label' => 'Basic Salary',
            'is_taxable' => true, 'affects_uif_remuneration' => true, 'affects_sdl_remuneration' => true,
            'pro_rates_on_partial' => true, 'sort_order' => 1,
        ]);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branchId, 'role' => 'agent', 'date_of_birth' => '1985-06-15']);
        $emp = PayrollEmployee::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'user_id' => $user->id, 'branch_id' => $branchId,
            'employment_date' => '2024-01-01', 'designation_snapshot' => 'Agent',
            'pay_frequency' => 'monthly', 'pay_day_of_month' => 25, 'is_active' => true, 'created_by' => $actor->id,
        ]);
        PayrollEmployeeEarning::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'payroll_employee_id' => $emp->id, 'earning_type_id' => $basic->id,
            'amount' => 30000, 'effective_from' => '2024-01-01', 'created_by' => $actor->id,
        ]);

        return [(int) $agency->id, $actor, (int) $emp->id];
    }

    private function seedPayrollTables(): void
    {
        $this->seed(\Database\Seeders\PayrollDeductionTypeSeeder::class);
        $this->seed(\Database\Seeders\PayrollTaxTableSeeder::class);
        $this->seed(\Database\Seeders\PayrollTaxRebateSeeder::class);
    }

    /** @return array<int,string> the payslip numbers the run produced */
    private function runPayroll(int $agencyId, User $actor, int $empId, string $month): array
    {
        Auth::login($actor);
        $req = Request::create('/x', 'POST', [
            'period_month' => $month . '-01', 'pay_date' => $month . '-25', 'employee_ids' => [$empId],
        ]);
        $req->setUserResolver(fn () => $actor);
        app(PayrollRunController::class)->store($req);
        $run = PayrollRun::withoutGlobalScopes()->where('agency_id', $agencyId)->orderByDesc('id')->firstOrFail();

        return PayrollPayslip::withoutGlobalScopes()->where('payroll_run_id', $run->id)->orderBy('id')->pluck('payslip_number')->all();
    }

    public function test_a_second_agencys_payslips_carry_its_own_letters_not_hfc(): void
    {
        [$hfcId, $hfcActor, $hfcEmp] = $this->payrollAgency('Home Finders Coastal');
        [$capeId, $capeActor, $capeEmp] = $this->payrollAgency('Cape Town Rentals');
        $this->seedPayrollTables();   // after the agencies exist: the deduction types are seeded per agency

        $hfc = $this->runPayroll($hfcId, $hfcActor, $hfcEmp, '2026-07');
        $cape = $this->runPayroll($capeId, $capeActor, $capeEmp, '2026-07');

        self::assertSame(['HFC-202607-001'], $hfc, "HFC's numbering is unchanged");
        self::assertSame(['CTR-202607-001'], $cape, 'the second agency numbers with its own code');
        foreach ($cape as $number) {
            self::assertStringNotContainsStringIgnoringCase('HFC', $number);
        }
    }

    public function test_changing_the_code_only_changes_numbers_issued_from_then_on(): void
    {
        [$capeId, $actor, $empId] = $this->payrollAgency('Cape Town Rentals');
        $this->seedPayrollTables();

        $july = $this->runPayroll($capeId, $actor, $empId, '2026-07');
        Agency::findOrFail($capeId)->update(['short_code' => 'cape']);
        $august = $this->runPayroll($capeId, $actor, $empId, '2026-08');

        self::assertSame(['CTR-202607-001'], $july);
        self::assertSame(['CAPE-202608-001'], $august);
        self::assertSame(
            ['CTR-202607-001', 'CAPE-202608-001'],
            PayrollPayslip::withoutGlobalScopes()->where('payroll_employee_id', $empId)->orderBy('id')->pluck('payslip_number')->all(),
            'the payslip already issued keeps the number it was issued with'
        );
    }

    public function test_no_document_number_in_the_code_is_hardcoded_to_an_agency(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $i => $line) {
                // a literal "HFC-" / 'HFC-' being joined onto a number
                if (preg_match('/[\'"]HFC-[\'"]\s*\./', $line)) {
                    $offenders[] = str_replace(base_path() . '/', '', $file->getPathname()) . ':' . ($i + 1);
                }
            }
        }
        self::assertSame([], $offenders, 'a document number starts with the agency\'s own short code (AgencyShortCode), never a literal');
    }

    // ── the backfill ──────────────────────────────────────────────────────────────────

    public function test_the_migration_gives_every_existing_agency_a_code_oldest_first(): void
    {
        // Rows as they were before the column existed: no code.
        $a = DB::table('agencies')->insertGetId(['name' => 'Home Finders Coastal', 'slug' => 'old-a-' . uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        $b = DB::table('agencies')->insertGetId(['name' => 'Harbour Front Coastal', 'slug' => 'old-b-' . uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        $c = DB::table('agencies')->insertGetId(['name' => 'Cape Town Rentals', 'slug' => 'old-c-' . uniqid(), 'created_at' => now(), 'updated_at' => now()]);
        self::assertNull(DB::table('agencies')->where('id', $a)->value('short_code'));

        (require base_path('database/migrations/2026_11_02_110000_add_short_code_to_agencies_table.php'))->up();

        self::assertSame('HFC', DB::table('agencies')->where('id', $a)->value('short_code'), 'the first agency keeps the plain version');
        self::assertSame('HFC2', DB::table('agencies')->where('id', $b)->value('short_code'));
        self::assertSame('CTR', DB::table('agencies')->where('id', $c)->value('short_code'));
    }

    // ── where a person sets it ─────────────────────────────────────────────────────────

    private function agencyAdmin(string $name = 'Cape Town Rentals'): User
    {
        $agency = Agency::create(['name' => $name, 'slug' => Str::slug($name) . '-' . Str::random(5)]);
        $branch = \App\Models\Branch::create(['agency_id' => $agency->id, 'name' => 'Head Office']);

        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
    }

    public function test_the_settings_page_saves_a_typed_code_and_rejects_a_taken_or_too_short_one(): void
    {
        Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);   // holds HFC
        $admin = $this->agencyAdmin();
        $url = route('corex.settings.agency.update');

        $this->actingAs($admin)->put($url, ['short_code' => 'cptown'])->assertSessionHasNoErrors();
        self::assertSame('CPTOWN', $admin->agency->fresh()->short_code);

        $this->actingAs($admin)->put($url, ['short_code' => 'hfc'])->assertSessionHasErrors('short_code');
        $this->actingAs($admin)->put($url, ['short_code' => 'x'])->assertSessionHasErrors('short_code');
        self::assertSame('CPTOWN', $admin->agency->fresh()->short_code, 'a rejected code changes nothing');

        $this->actingAs($admin)->put($url, ['short_code' => ''])->assertSessionHasNoErrors();
        self::assertSame('CPTOWN', $admin->agency->fresh()->short_code, 'a blank box keeps the code - it is never emptied');

        $this->actingAs($admin)->put($url, ['tagline' => 'New line'])->assertSessionHasNoErrors();
        self::assertSame('CPTOWN', $admin->agency->fresh()->short_code, 'a form that does not show the box leaves the code alone (§6.1)');
    }

    public function test_the_setup_wizard_identity_step_shows_and_saves_it(): void
    {
        $control = collect(config('agency-onboarding-copy.identity.controls'))->firstWhere('key', 'short_code');
        self::assertNotNull($control, 'a setting that is not in the wizard is not done');
        self::assertNotEmpty($control['explain']);
        self::assertNotEmpty($control['affects']);

        $admin = $this->agencyAdmin('Cape Town Rentals');
        $setup = new \App\Models\AgencyOnboardingSetup();
        $setup->agency_id = $admin->agency_id;
        $setup->token = \App\Models\AgencyOnboardingSetup::generateToken();
        $setup->slug = \App\Models\AgencyOnboardingSetup::generateSlug('Cape Town Rentals', $admin->agency_id);
        $setup->current_step = 1;
        $setup->completed_steps = [];
        $setup->expires_at = now()->addDays(30);
        $setup->save();
        $setup->markStepComplete('welcome');

        $page = $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'identity']))->assertOk()->getContent();
        self::assertStringContainsString('name="short_code"', $page);
        self::assertStringContainsString('value="CTR"', $page, 'the code worked out from the name is pre-filled');

        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'identity']), ['short_code' => 'cape'])
            ->assertRedirect()->assertSessionHasNoErrors();
        self::assertSame('CAPE', $admin->agency->fresh()->short_code);
    }

    public function test_admin_agencies_create_form_takes_a_code_and_rejects_one_in_use(): void
    {
        Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $role = \App\Models\Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        \App\Models\Role::clearCache();
        $owner = User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'branch_id' => null, 'is_active' => true]);

        $base = ['is_demo' => '1', 'branch_name' => 'Head Office', 'branch_code' => 'HQ'];
        $this->actingAs($owner)->post(route('agencies.store'), $base + ['name' => 'Cape Town Rentals', 'short_code' => 'hfc'])->assertSessionHasErrors('short_code');
        self::assertSame(0, Agency::withoutGlobalScopes()->where('name', 'Cape Town Rentals')->count(), 'nothing was created');

        $this->actingAs($owner)->post(route('agencies.store'), $base + ['name' => 'Cape Town Rentals'])->assertSessionHasNoErrors();
        self::assertSame('CTR', Agency::withoutGlobalScopes()->where('name', 'Cape Town Rentals')->value('short_code'), 'left blank = worked out from the name');
    }
}
