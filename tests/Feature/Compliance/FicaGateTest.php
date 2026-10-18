<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\FicaSubmission;
use App\Models\User;
use App\Services\Compliance\FicaGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan's ruling, 2026-10-08 (relayed by the conductor): the FICA gate lifts on SUBMITTED, not only on approved. FicaGate is the ONE place that
 * rule lives — sales' signer gate and every rentals check ask it. Every FICA state, one expectation each.
 */
final class FicaGateTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Contact $contact;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cr-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id,
            'first_name' => 'Ayanda', 'last_name' => 'Mtolo', 'email' => 'ayanda@example.co.za',
        ]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
    }

    private function fica(string $status): FicaSubmission
    {
        return FicaSubmission::create([
            'contact_id' => $this->contact->id, 'agency_id' => $this->agency->id,
            'requested_by' => $this->agent->id, 'status' => $status,
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ]);
    }

    /** @return array<string, array{0:string,1:string,2:bool}> */
    public static function stateProvider(): array
    {
        return [
            'no FICA at all' => ['__none__', FicaGate::STATE_NONE, false],
            'requested, contact has not submitted (draft)' => ['draft', FicaGate::STATE_WAITING_CLIENT, false],
            'sent back for corrections' => ['corrections_requested', FicaGate::STATE_RETURNED, false],
            'rejected' => ['rejected', FicaGate::STATE_RETURNED, false],
            'cancelled' => ['cancelled', FicaGate::STATE_NONE, false],
            'submitted' => ['submitted', FicaGate::STATE_SUBMITTED, true],
            'under review' => ['under_review', FicaGate::STATE_SUBMITTED, true],
            'agent approved (RO approval needed)' => ['agent_approved', FicaGate::STATE_SUBMITTED, true],
            'referred to the compliance officer' => ['referred_to_co', FicaGate::STATE_SUBMITTED, true],
            'approved' => ['approved', FicaGate::STATE_APPROVED, true],
        ];
    }

    /** @dataProvider stateProvider */
    public function test_every_fica_state_opens_or_keeps_the_gate_closed_as_ruled(string $status, string $expectedState, bool $expectedOpen): void
    {
        if ($status !== '__none__') {
            $this->fica($status);
        }

        $this->assertSame($expectedState, FicaGate::stateFor($this->contact->id));
        $this->assertSame($expectedOpen, FicaGate::isOpen($this->contact->id));

        $described = FicaGate::describe($this->contact->fresh(), 'the tenant');
        $this->assertSame($expectedOpen, $described['open']);
        if ($expectedOpen) {
            $this->assertNull($described['warning'], 'an open gate has nothing to warn about');
            $this->assertNull($described['url']);
        } else {
            $this->assertNotEmpty($described['warning']);
            $this->assertStringContainsString('You can carry on', $described['warning'], 'a closed gate is a warning, never a stop');
            $this->assertNotEmpty($described['url'], 'the warning always carries the link to request/complete FICA');
        }
    }

    public function test_an_approved_fica_outranks_a_newer_unsubmitted_draft(): void
    {
        $this->fica('approved');
        $this->fica('draft');

        $this->assertTrue(FicaGate::isOpen($this->contact->id));
        $this->assertSame(FicaGate::STATE_APPROVED, FicaGate::stateFor($this->contact->id));
    }

    public function test_a_soft_deleted_submission_never_opens_the_gate(): void
    {
        $this->fica('approved')->delete();

        $this->assertFalse(FicaGate::isOpen($this->contact->id));
        $this->assertSame(FicaGate::STATE_NONE, FicaGate::stateFor($this->contact->id));
    }

    public function test_the_warning_link_goes_to_the_existing_submission_or_else_to_the_contact(): void
    {
        $none = FicaGate::describe($this->contact, 'the tenant');
        $this->assertSame(route('corex.contacts.show', $this->contact), $none['url']);

        $draft = $this->fica('draft');
        $withDraft = FicaGate::describe($this->contact->fresh(), 'the tenant');
        $this->assertSame(route('compliance.fica.show', $draft), $withDraft['url']);
    }

    public function test_no_contact_is_closed_not_an_error(): void
    {
        $this->assertFalse(FicaGate::isOpen(null));
        $described = FicaGate::describe(null, 'the tenant');
        $this->assertFalse($described['open']);
        $this->assertNull($described['url']);
    }

    public function test_the_open_status_list_is_the_whole_review_pipeline_after_the_contacts_own_submit(): void
    {
        $this->assertSame(
            ['submitted', 'under_review', 'agent_approved', 'referred_to_co', 'approved'],
            FicaGate::OPEN_STATUSES,
        );
    }
}
