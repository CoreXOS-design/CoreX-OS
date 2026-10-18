<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Events\Docuperfect\TemplateAgencyAssigned;
use App\Models\Agency;
use App\Models\Docuperfect\Template;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * leases.md §15.12.4 (Build L0) — templates:assign-agency {template_id} {agency_id}: the one-time repair
 * for an e-sign template with no owning agency. Idempotent, prints before/after, writes an audit row,
 * refuses a template another agency already owns, and never invents an agency.
 */
final class AssignTemplateToAgencyCommandTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->other = Agency::create(['name' => 'Karoo Lettings', 'slug' => 'karoo-' . uniqid()]);
    }

    public function test_an_ownerless_template_is_given_the_agency_and_the_before_and_after_are_printed(): void
    {
        $template = $this->template(null);

        // One expectation per printed line (each expectation consumes the write it matches).
        $this->artisan('templates:assign-agency', ['template_id' => $template->id, 'agency_id' => $this->agency->id])
            ->expectsOutputToContain('Before: template #' . $template->id . ' "' . $template->name . '" — agency_id=NULL')
            ->expectsOutputToContain('After : template #' . $template->id . ' "' . $template->name . '" — agency_id=' . $this->agency->id)
            ->assertExitCode(0);

        $this->assertSame($this->agency->id, (int) Template::find($template->id)->agency_id);
    }

    public function test_the_change_is_written_to_the_audit_log(): void
    {
        $template = $this->template(null, ['name' => 'Ownerless lease']);

        $this->artisan('templates:assign-agency', ['template_id' => $template->id, 'agency_id' => $this->agency->id])->assertExitCode(0);

        $row = DB::table('domain_event_log')
            ->where('event_name', TemplateAgencyAssigned::class)
            ->where('subject_id', $template->id)
            ->first();
        $this->assertNotNull($row, 'an audit row must exist');
        $this->assertSame($this->agency->id, (int) $row->agency_id);
        $payload = json_decode($row->payload_snapshot, true);
        $this->assertSame('Ownerless lease', $payload['templateName']);
        $this->assertNull($payload['previousAgencyId']);
        $this->assertSame($this->agency->id, $payload['agencyId']);
    }

    public function test_running_it_again_for_the_same_agency_changes_nothing_and_writes_no_second_audit_row(): void
    {
        $template = $this->template(null);

        $this->artisan('templates:assign-agency', ['template_id' => $template->id, 'agency_id' => $this->agency->id])->assertExitCode(0);
        $updatedAt = Template::find($template->id)->updated_at;

        $this->artisan('templates:assign-agency', ['template_id' => $template->id, 'agency_id' => $this->agency->id])
            ->expectsOutputToContain('Already owned by agency ' . $this->agency->id)
            ->assertExitCode(0);

        $this->assertEquals($updatedAt, Template::find($template->id)->updated_at);
        $this->assertSame(1, DB::table('domain_event_log')->where('event_name', TemplateAgencyAssigned::class)->where('subject_id', $template->id)->count());
    }

    public function test_a_template_another_agency_already_owns_is_refused_and_left_alone(): void
    {
        $template = $this->template($this->other->id);

        $this->artisan('templates:assign-agency', ['template_id' => $template->id, 'agency_id' => $this->agency->id])
            ->expectsOutputToContain('already belongs to agency ' . $this->other->id)
            ->assertExitCode(1);

        $this->assertSame($this->other->id, (int) Template::find($template->id)->agency_id);
        $this->assertSame(0, DB::table('domain_event_log')->where('event_name', TemplateAgencyAssigned::class)->count());
    }

    public function test_an_unknown_template_or_agency_changes_nothing(): void
    {
        $template = $this->template(null);

        $this->artisan('templates:assign-agency', ['template_id' => $template->id, 'agency_id' => 999999])
            ->expectsOutputToContain('Agency 999999 does not exist')
            ->assertExitCode(1);
        $this->artisan('templates:assign-agency', ['template_id' => 999999, 'agency_id' => $this->agency->id])
            ->expectsOutputToContain('Template 999999 does not exist')
            ->assertExitCode(1);

        $this->assertNull(Template::find($template->id)->agency_id);
        $this->assertSame(0, DB::table('domain_event_log')->where('event_name', TemplateAgencyAssigned::class)->count());
    }

    private function template(?int $agencyId, array $overrides = []): Template
    {
        $template = new Template(array_merge(['name' => 'Lease ' . uniqid(), 'render_type' => 'pdf', 'is_esign' => true, 'is_global' => true], $overrides));
        $template->agency_id = $agencyId;
        $template->save();

        return $template->fresh();
    }
}
