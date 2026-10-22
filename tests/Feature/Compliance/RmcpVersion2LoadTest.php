<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Agency;
use App\Models\Compliance\RmcpAcknowledgement;
use App\Models\Compliance\RmcpSection;
use App\Models\Compliance\RmcpVariable;
use App\Models\Compliance\RmcpVersion;
use App\Services\Compliance\CompliancePdf;
use App\Services\Compliance\RmcpVariableResolver;
use App\Services\Compliance\RmcpVersionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Compliance\Policy\PolicyTestHelpers;
use Tests\TestCase;

/**
 * AT-506 — RMCP v2 loaded into CoreX as a draft from the approved text, filled from the company profile.
 *
 * Covers: the source parses into v2's own structure (tables, bullet and numbered lists, no [INSERT] left);
 * the importer is dry-run first, idempotent, and leaves the active version alone; the new merge fields
 * (business profile, offices, approval block); an admin adding a variable key; the page footer;
 * and what approval does to staff (earlier acknowledgements superseded, in-app notice, no email).
 */
final class RmcpVersion2LoadTest extends TestCase
{
    use RefreshDatabase, PolicyTestHelpers;

    private const SOURCE = 'database/data/rmcp/v2.md';

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = $this->seedAgency();
        DB::table('agencies')->where('id', $this->agencyId)->update([
            'name' => 'Ramsgate Realty', 'reg_no' => '2001/123456/07', 'fic_no' => 'AI/1/0000001',
            'ffc_no' => '2026-FFC-77', 'ppra_number' => 'F100100', 'address' => '12 Marine Drive, Ramsgate',
        ]);
    }

    private function source(): string
    {
        return (string) file_get_contents(base_path(self::SOURCE));
    }

    private function agency(): Agency
    {
        return Agency::withoutGlobalScopes()->findOrFail($this->agencyId);
    }

    private function fixtureLeaseAndDeal(string $status, float $rent): void
    {
        Schema::disableForeignKeyConstraints();
        DB::table('leases')->insert([
            'agency_id' => $this->agencyId, 'property_id' => 999001, 'status' => $status, 'rental_amount' => $rent,
            'start_date' => '2026-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::enableForeignKeyConstraints();
    }

    private function fixtureDeal(string $status, ?string $registered): void
    {
        Schema::disableForeignKeyConstraints();
        DB::table('deals_v2')->insert([
            'reference' => 'D-' . uniqid(), 'deal_type' => 'bond', 'status' => $status, 'listing_agent_id' => 1,
            'purchase_price' => 1500000, 'commission_amount' => 0, 'commission_vat' => 0, 'offer_date' => '2026-01-01',
            'actual_registration' => $registered, 'branch_id' => $this->agencyId, 'agency_id' => $this->agencyId,
            'created_by_id' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::enableForeignKeyConstraints();
    }

    // ── The approved text, as structure ──────────────────────────────────

    public function test_the_source_text_becomes_v2s_own_structure(): void
    {
        $parsed = app(RmcpVersionImporter::class)->parse($this->source());

        self::assertSame('Risk Management and Compliance Programme', $parsed['title']);

        $numbers = array_column($parsed['sections'], 'number');
        self::assertCount(39, $parsed['sections'], '31 sections + 5 schedule rows + 2 annexures + the approval block');
        self::assertSame(range(1, 31), array_map('intval', array_slice($numbers, 0, 31)));
        self::assertSame(['Schedule 1', 'Schedule 2', 'Schedule 3', 'Schedules 4–7', 'Schedule 8', 'Annexure A', 'Annexure B', 'Approval'], array_slice($numbers, 31));

        $by = fn (string $n) => collect($parsed['sections'])->firstWhere('number', $n);

        // Tables the brief asked for.
        self::assertSame(1, substr_count($by('4')['html'], '<table>'));
        self::assertStringContainsString('<th>Factor</th>', $by('4')['html']);
        self::assertSame(4, substr_count($by('4')['html'], '<tr>') - 1, 's4: Client, Service, Delivery channel, Geography');
        self::assertStringContainsString('<th>Deadline</th>', $by('25')['html']);
        self::assertSame(3, substr_count($by('25')['html'], '<tr>') - 1, 's25: STR/SAR, CTR, TPR');
        self::assertStringContainsString('<table>', $by('Approval')['html']);

        // Bullet lists and numbered steps.
        foreach (['1', '3', '5', '6', '10', '19', '20', '22', '23', 'Schedule 1', 'Schedule 8', 'Annexure B'] as $n) {
            self::assertStringContainsString('<ul>', $by($n)['html'], "section {$n} is a bullet list");
        }
        self::assertStringContainsString('<ol>', $by('8')['html']);
        self::assertSame(4, substr_count($by('Annexure A')['html'], '<ol>'), 'four numbered CDD lists');

        // Nothing left to fill by hand; every placeholder became a merge field.
        foreach ($parsed['sections'] as $s) {
            self::assertStringNotContainsString('[INSERT', $s['html']);
        }
        self::assertStringContainsString('{{compliance_officer.id_number}}', $by('28')['html']);
        self::assertStringContainsString('{{profile.avg_sales_per_year}}', $by('4')['html']);

        // The wording is the approved wording.
        self::assertStringContainsString('As soon as possible, within 15 working days', $by('25')['html']);
        self::assertStringContainsString('R49 999.99', $by('25')['html']);
        self::assertStringContainsString('every year by 31 October, and any amended RMCP within 10 days of approval', $by('26')['html']);
    }

    public function test_the_staff_acknowledgement_steps_follow_the_v1_pattern(): void
    {
        $sections = collect(app(RmcpVersionImporter::class)->parse($this->source())['sections'])->keyBy('number');

        self::assertFalse($sections['1']['ack'], 'definitions are read, not signed section by section');
        self::assertTrue($sections['2']['ack']);
        self::assertTrue($sections['31']['ack']);
        self::assertTrue($sections['Schedule 8']['ack']);
        self::assertTrue($sections['Annexure A']['ack']);
        self::assertFalse($sections['Schedule 1']['ack']);
        self::assertFalse($sections['Approval']['ack']);
        self::assertSame('acknowledgement', $sections['Annexure B']['type'], 'the declaration the e-sign step shows');
        self::assertFalse($sections['Annexure B']['ack']);
        self::assertSame('I have read and understood section 7 (When CDD must be done).', $sections['7']['prompt']);
    }

    // ── The importer ─────────────────────────────────────────────────────

    public function test_dry_run_writes_nothing_and_apply_creates_a_draft_without_touching_the_active_version(): void
    {
        $v1 = RmcpVersion::create([
            'agency_id' => $this->agencyId, 'version_number' => 1, 'title' => 'Risk Management and Compliance Programme',
            'status' => 'active', 'approved_at' => now()->subMonth(),
        ]);
        RmcpSection::create(['agency_id' => $this->agencyId, 'rmcp_version_id' => $v1->id, 'section_type' => 'section',
            'display_order' => 1, 'section_number' => '1', 'title' => 'Definitions', 'body_html' => '<p>v1</p>']);

        Artisan::call('rmcp:import-version', ['agency' => (string) $this->agencyId]);
        $out = Artisan::output();
        self::assertStringContainsString('DRY RUN', $out);
        self::assertStringContainsString('Dry run only', $out);
        self::assertSame(1, RmcpVersion::withoutGlobalScopes()->where('agency_id', $this->agencyId)->count(), 'a dry run writes nothing');

        Artisan::call('rmcp:import-version', ['agency' => (string) $this->agencyId, '--apply' => true]);
        $v2 = RmcpVersion::withoutGlobalScopes()->where('agency_id', $this->agencyId)->where('version_number', 2)->firstOrFail();

        self::assertSame('draft', $v2->status);
        self::assertNull($v2->approved_at);
        self::assertSame(39, RmcpSection::withoutGlobalScopes()->where('rmcp_version_id', $v2->id)->count());
        self::assertSame(range(1, 39), RmcpSection::withoutGlobalScopes()->where('rmcp_version_id', $v2->id)->orderBy('display_order')->pluck('display_order')->all());
        self::assertSame($this->agencyId, (int) RmcpSection::withoutGlobalScopes()->where('rmcp_version_id', $v2->id)->value('agency_id'));

        $v1->refresh();
        self::assertSame('active', $v1->status, 'v1 stays active until v2 is approved');
        self::assertNull($v1->superseded_at);
        self::assertSame(1, RmcpSection::withoutGlobalScopes()->where('rmcp_version_id', $v1->id)->count());

        // Run it again: nothing changes, and it says so.
        Artisan::call('rmcp:import-version', ['agency' => (string) $this->agencyId, '--apply' => true]);
        self::assertStringContainsString('already exists', Artisan::output());
        self::assertSame(2, RmcpVersion::withoutGlobalScopes()->where('agency_id', $this->agencyId)->count());
        self::assertSame(39, RmcpSection::withoutGlobalScopes()->where('rmcp_version_id', $v2->id)->count());
    }

    public function test_the_dry_run_never_prints_an_id_number_in_full(): void
    {
        DB::table('fica_officer_appointments')->insert([
            'agency_id' => $this->agencyId, 'role' => 'primary_compliance_officer', 'full_name' => 'Officer One',
            'id_number' => '7001015009087', 'title' => 'FICA Compliance Officer', 'appointed_on' => '2026-03-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Artisan::call('rmcp:import-version', ['agency' => (string) $this->agencyId]);
        $out = Artisan::output();

        self::assertStringContainsString('{{compliance_officer.id_number}}  ****9087', $out);
        self::assertStringNotContainsString('7001015009087', $out);
    }

    public function test_the_dry_run_names_every_merge_field_the_agency_would_print_blank(): void
    {
        Artisan::call('rmcp:import-version', ['agency' => (string) $this->agencyId]);
        $out = Artisan::output();

        self::assertStringContainsString('{{agency.area_of_operation}}', $out);
        self::assertStringContainsString('*** BLANK ***', $out, 'a fact the agency has not given must be called out, not printed as nothing');
        self::assertStringContainsString('Blank for this agency', $out);
        self::assertStringContainsString('agency.area_of_operation', $out);
        self::assertStringNotContainsString('UNKNOWN merge fields', $out, 'every token in the text is one the resolver knows');
    }

    public function test_variables_are_set_only_when_the_agency_has_none_and_never_overwritten(): void
    {
        RmcpVariable::create(['agency_id' => $this->agencyId, 'variable_key' => 'agency.area_of_operation', 'value' => 'Already stated', 'data_source' => 'manual']);

        Artisan::call('rmcp:import-version', [
            'agency' => (string) $this->agencyId, '--apply' => true,
            '--var'  => ['agency.area_of_operation=Somewhere else', 'profile.active_leases=55'],
        ]);

        $val = fn (string $k) => RmcpVariable::withoutGlobalScopes()->where('agency_id', $this->agencyId)->where('variable_key', $k)->value('value');
        self::assertSame('Already stated', $val('agency.area_of_operation'));
        self::assertSame('55', $val('profile.active_leases'));
    }

    // ── The merge fields ─────────────────────────────────────────────────

    public function test_the_business_profile_is_worked_out_per_agency_and_an_override_wins(): void
    {
        $this->fixtureLeaseAndDeal('active', 8000);
        $this->fixtureLeaseAndDeal('active', 12000);
        $this->fixtureLeaseAndDeal('expired', 99999);          // not active: ignored
        $this->fixtureDeal('completed', now()->subMonths(2)->toDateString());
        $this->fixtureDeal('completed', now()->subMonths(11)->toDateString());
        $this->fixtureDeal('completed', now()->subMonths(14)->toDateString());   // older than 12 months
        $this->fixtureDeal('cancelled', now()->subMonths(1)->toDateString());    // cancelled: ignored
        $this->fixtureDeal('active', null);                                       // not registered yet

        $vars = app(RmcpVariableResolver::class)->resolve($this->agency());
        self::assertSame('2', $vars['profile.active_leases']);
        self::assertSame('R 10,000', $vars['profile.avg_monthly_rent']);
        self::assertSame('2 registrations in the last 12 months', $vars['profile.avg_sales_per_year']);

        // Another agency's figures are its own.
        $other = $this->seedAgency();
        $otherVars = app(RmcpVariableResolver::class)->resolve(Agency::withoutGlobalScopes()->findOrFail($other));
        self::assertSame('0', $otherVars['profile.active_leases']);
        self::assertSame('n/a', $otherVars['profile.avg_monthly_rent']);
        self::assertSame('0 registrations in the last 12 months', $otherVars['profile.avg_sales_per_year']);

        // The agency's own stated figure replaces the computed one (leases managed in another system).
        RmcpVariable::create(['agency_id' => $this->agencyId, 'variable_key' => 'profile.active_leases', 'value' => '55', 'data_source' => 'manual']);
        RmcpVariable::create(['agency_id' => $this->agencyId, 'variable_key' => 'profile.avg_monthly_rent', 'value' => 'R 9,384', 'data_source' => 'manual']);
        $vars = app(RmcpVariableResolver::class)->resolve($this->agency());
        self::assertSame('55', $vars['profile.active_leases']);
        self::assertSame('R 9,384', $vars['profile.avg_monthly_rent']);
    }

    public function test_the_offices_sentence_comes_from_the_agencys_branches_that_have_an_address(): void
    {
        $resolve = fn () => app(RmcpVariableResolver::class)->resolve($this->agency());

        // The seeded branch has no address, so the agency's own address stands in.
        self::assertSame('one office, 12 Marine Drive, Ramsgate, to which all agents are attached', $resolve()['agency.branches_statement']);

        DB::table('branches')->where('id', $this->agencyId)->update(['name' => 'Ramsgate', 'address' => '12 Marine Drive, Ramsgate']);
        self::assertSame('one office, Ramsgate, to which all agents are attached', $resolve()['agency.branches_statement']);

        RmcpVariable::create(['agency_id' => $this->agencyId, 'variable_key' => 'agency.subsidiaries_statement', 'value' => 'no subsidiaries in or outside South Africa', 'data_source' => 'manual']);
        self::assertSame('one office, Ramsgate, to which all agents are attached; no subsidiaries in or outside South Africa', $resolve()['agency.branches_statement']);

        DB::table('branches')->insert(['agency_id' => $this->agencyId, 'name' => 'Margate', 'address' => '3 Beach Rd, Margate', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['agency_id' => $this->agencyId, 'name' => 'Closed office', 'address' => null, 'created_at' => now(), 'updated_at' => now()]);
        self::assertSame('two offices, Ramsgate and Margate, each with its own agents attached; no subsidiaries in or outside South Africa', $resolve()['agency.branches_statement']);
        self::assertStringContainsString('Ramsgate: ______________; Margate: ______________', $resolve()['agency.branches_and_responsible'], 'no branch manager → a line to write the name on');
    }

    public function test_the_new_keys_are_there_for_the_approval_block_and_the_header(): void
    {
        $v = RmcpVersion::create([
            'agency_id' => $this->agencyId, 'version_number' => 2, 'title' => 'x', 'status' => 'draft',
        ]);
        $vars = app(RmcpVariableResolver::class)->resolve($this->agency(), $v);

        self::assertSame('F100100', $vars['agency.ppra_number']);
        self::assertSame('', $vars['rmcp.approved_by_name'], 'nobody has approved a draft');
        self::assertSame('______________', $vars['fic.directive12_submitted_on'], 'a line to write on until the FIC submission is recorded');

        $approver = $this->makeUser($this->agencyId);
        $v->update(['status' => 'active', 'approved_by' => $approver->id, 'approved_at' => now()]);
        $vars = app(RmcpVariableResolver::class)->resolve($this->agency(), $v->fresh());
        self::assertSame($approver->name, $vars['rmcp.approved_by_name']);
    }

    // ── Adding a variable key ────────────────────────────────────────────

    public function test_an_admin_can_add_a_variable_key_and_override_a_computed_one(): void
    {
        $admin = $this->makeUser($this->agencyId);

        $this->actingAs($admin)->post(route('compliance.rmcp.variables.store'), ['variable_key' => 'agency.area_of_operation', 'value' => 'Lower South Coast'])
            ->assertRedirect(route('compliance.rmcp.variables'));
        $this->actingAs($admin)->post(route('compliance.rmcp.variables.store'), ['variable_key' => 'agency.area_of_operation', 'value' => 'South and North Coast'])
            ->assertRedirect();

        $rows = RmcpVariable::withoutGlobalScopes()->where('agency_id', $this->agencyId)->where('variable_key', 'agency.area_of_operation')->get();
        self::assertCount(1, $rows, 'adding an existing key updates it, never duplicates');
        self::assertSame('South and North Coast', $rows->first()->value);

        $page = $this->actingAs($admin)->get(route('compliance.rmcp.variables'));
        $page->assertOk()->assertSee('Add variable');
    }

    public function test_a_variable_key_must_look_like_group_dot_name_and_needs_edit_permission(): void
    {
        $admin = $this->makeUser($this->agencyId);

        foreach (['Bad Key', 'nodots', 'UPPER.case', 'a..b', '{{x.y}}'] as $bad) {
            $this->actingAs($admin)->from(route('compliance.rmcp.variables'))
                ->post(route('compliance.rmcp.variables.store'), ['variable_key' => $bad, 'value' => 'x'])
                ->assertSessionHasErrors('variable_key');
        }
        self::assertSame(0, RmcpVariable::withoutGlobalScopes()->where('agency_id', $this->agencyId)->count());

        // A user who holds no RMCP grant (the test posture for that: an assistant) is stopped and nothing is saved.
        $assistant = $this->makeUser($this->agencyId, 'agent');
        DB::table('users')->where('id', $assistant->id)->update(['is_assistant' => 1]);
        $this->actingAs($assistant->fresh())->post(route('compliance.rmcp.variables.store'), ['variable_key' => 'a.b', 'value' => 'x'])->assertForbidden();
        self::assertSame(0, RmcpVariable::withoutGlobalScopes()->where('agency_id', $this->agencyId)->count());
    }

    // ── Printing ─────────────────────────────────────────────────────────

    public function test_the_rmcp_pdf_footer_carries_the_agency_version_page_count_and_initials(): void
    {
        $v = RmcpVersion::create(['agency_id' => $this->agencyId, 'version_number' => 2, 'title' => 'Risk Management and Compliance Programme', 'status' => 'draft']);
        RmcpSection::create(['agency_id' => $this->agencyId, 'rmcp_version_id' => $v->id, 'section_type' => 'section', 'display_order' => 1,
            'section_number' => '1', 'title' => 'Definitions', 'body_html' => '<p>x</p>']);

        $printer = $this->partialMock(CompliancePdf::class);
        $printer->shouldReceive('render')->once()
            ->withArgs(function (string $html, string $label, string $tag, ?string $pageWord, ?string $suffix) {
                return $label === 'Ramsgate Realty · RMCP v2' && $pageWord === 'page' && $suffix === 'Initials ____'
                    && str_contains($html, 'FIC registration number AI/1/0000001 · PPRA FFC 2026-FFC-77');
            })
            ->andReturn('%PDF-1.4 test');

        $this->actingAs($this->makeUser($this->agencyId))->get(route('compliance.rmcp.pdf', $v))->assertOk();
    }

    public function test_the_printed_approval_block_is_not_printed_twice(): void
    {
        $approver = $this->makeUser($this->agencyId);
        $v = RmcpVersion::create(['agency_id' => $this->agencyId, 'version_number' => 2, 'title' => 'Risk Management and Compliance Programme',
            'status' => 'active', 'approved_by' => $approver->id, 'approved_at' => now(), 'approver_title' => 'Principal']);
        RmcpSection::create(['agency_id' => $this->agencyId, 'rmcp_version_id' => $v->id, 'section_type' => 'section', 'display_order' => 1,
            'section_number' => 'Approval', 'title' => 'Approval', 'body_html' => '<p>Approved by {{rmcp.approved_by_name}}</p>']);

        $html = app(CompliancePdf::class)->versionHtml('k', null, $v->load('sections', 'approver'), $this->agency(),
            app(RmcpVariableResolver::class)->resolve($this->agency(), $v), 'Risk Management and Compliance Programme');

        self::assertStringContainsString('Approved by ' . e($approver->name), $html);
        self::assertSame(0, substr_count($html, '<strong>Approved by:</strong>'), "the page's own approval block is left out");
        self::assertStringNotContainsString('Approval. Approval', $html);
    }

    // ── Approval → staff re-sign ─────────────────────────────────────────

    public function test_approving_v2_supersedes_earlier_acknowledgements_and_tells_active_staff_in_app_only(): void
    {
        Mail::fake();

        $admin    = $this->makeUser($this->agencyId);
        $agent    = $this->makeUser($this->agencyId, 'agent');
        $inactive = $this->makeUser($this->agencyId, 'agent');
        $inactive->forceFill(['is_active' => false])->save();
        $stranger = $this->makeUser($this->seedAgency(), 'agent');

        $v1 = RmcpVersion::create(['agency_id' => $this->agencyId, 'version_number' => 1, 'title' => 'RMCP', 'status' => 'active',
            'approved_by' => $admin->id, 'approved_at' => now()->subMonths(3)]);
        $ackV1 = RmcpAcknowledgement::create(['agency_id' => $this->agencyId, 'rmcp_version_id' => $v1->id, 'user_id' => $agent->id,
            'status' => 'completed', 'started_at' => now()->subMonths(3), 'completed_at' => now()->subMonths(3), 'valid_until' => now()->addMonths(9),
            'sections_acknowledged_count' => 3, 'sections_total_count' => 3]);
        self::assertSame('valid', $agent->rmcpAcknowledgementStatus());

        Artisan::call('rmcp:import-version', ['agency' => (string) $this->agencyId, '--apply' => true]);
        $v2 = RmcpVersion::withoutGlobalScopes()->where('agency_id', $this->agencyId)->where('version_number', 2)->firstOrFail();

        $this->actingAs($admin)->post(route('compliance.rmcp.approve', $v2), [
            'approver_title' => 'Principal', 'board_approval_document' => UploadedFile::fake()->create('board.pdf', 50, 'application/pdf'),
            'effective_from' => now()->toDateString(), 'next_review_due' => now()->addYear()->toDateString(),
        ])->assertRedirect(route('compliance.rmcp.show', $v2));

        self::assertSame('active', $v2->fresh()->status);
        self::assertSame('superseded', $v1->fresh()->status);

        // The signed v1 record is kept, marked superseded.
        self::assertSame('superseded', $ackV1->fresh()->status);
        self::assertSame(1, RmcpAcknowledgement::withoutGlobalScopes()->where('id', $ackV1->id)->count());

        // The existing flow now asks for v2.
        self::assertSame('not_started', $agent->fresh()->rmcpAcknowledgementStatus());

        // Active staff of THIS agency were told, in-app; nobody else; no email.
        $told = fn ($u) => DB::table('notifications')->where('notifiable_id', $u->id)->where('notifiable_type', get_class($u))->count();
        self::assertGreaterThanOrEqual(1, $told($agent));
        self::assertGreaterThanOrEqual(1, $told($admin));
        self::assertSame(0, $told($inactive), 'inactive staff are not asked');
        self::assertSame(0, $told($stranger), "another agency's staff are not asked");
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }
}
