<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Models\Contact;
use App\Models\ContactRepresentative;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Shape 5 (deceased seller, executor is a COMPANY acting through a named
 * representative) — cc3, 2026-08-30. A deceased party's "Replace this
 * party" chain binds the executor slot to a `type:'recipient'` row
 * (RecipientTemplate.php's own documented mechanism — the ONLY binding
 * type that produces a real SignatureRequest). When that recipient's own
 * Contact is an entity, expandEntityRecipients() unconditionally replaces
 * it with its representative(s) — correct — but the replacement row it
 * built never carried the ORIGINAL row's `_recipient_local_key` (or
 * `_deceased_substitute_for`) through. The deceased row's own
 * `_slot_bindings['executor']['recipient_local_key']` then points at a key
 * that no longer exists anywhere in the post-expansion array, so
 * assertDeceasedRecipientsHaveSubstituteSigner() — the hard block that
 * exists specifically to stop a late-estate document going out with no
 * real substitute signer — concludes none was ever chosen and refuses the
 * send, even though one genuinely was. Confirmed via the real wizard
 * screens too (ESignWizardController's bindSlotToContact() promotes a
 * searched company to a new recipient row the identical way), so this
 * wasn't just a test-construction artefact — a real agent hits the same
 * 422 today.
 *
 * The fix carries `_recipient_local_key`/`_deceased_substitute_for`
 * through expansion (ESignWizardController::expandEntityRecipients(),
 * inside the `foreach ($signers as $rep)` loop) rather than touching the
 * assertion itself — the assertion's job (refuse a document with no real
 * substitute) is unchanged and must keep refusing exactly the cases it
 * refused before.
 */
final class DeceasedCompanyExecutorExpansionTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test Agency', 'slug' => 'test-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Test Branch',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function expand(array $recipients, User $user, bool $signersOnly = true): array
    {
        $m = new ReflectionMethod(ESignWizardController::class, 'expandEntityRecipients');
        $m->setAccessible(true);

        return $m->invoke(app(ESignWizardController::class), $recipients, $user, $signersOnly);
    }

    private function assertDeceased(array $recipients): void
    {
        $m = new ReflectionMethod(ESignWizardController::class, 'assertDeceasedRecipientsHaveSubstituteSigner');
        $m->setAccessible(true);
        $m->invoke(app(ESignWizardController::class), $recipients);
    }

    /** (a) Deceased seller + COMPANY executor, executor's own rep on file — must send, no 422. */
    public function test_deceased_seller_with_company_executor_survives_expansion_and_send_gate(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyId]);

        $executorCo = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'Estate Trustees (Pty) Ltd',
            'entity_reg_no' => '2020/555001/07', 'first_name' => 'Estate Trustees (Pty) Ltd', 'last_name' => '',
        ]);
        $rep = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'Jane', 'last_name' => 'Director',
            'id_number' => '8001015800111', 'email' => 'jane.director@x.test',
        ]);
        ContactRepresentative::create([
            'entity_contact_id' => $executorCo->id, 'representative_contact_id' => $rep->id,
            'capacity' => 'Director', 'signs_as_proxy' => false, 'is_primary' => true,
        ]);

        $recipients = [
            [
                'name' => 'John Smith', 'role' => 'seller',
                '_recipient_local_key' => 'deceased-key', '_is_deceased' => true,
                '_recipient_template_id' => 1,
                '_slot_bindings' => [
                    'deceased' => ['type' => 'self'],
                    'executor' => ['type' => 'recipient', 'recipient_local_key' => 'executor-key'],
                ],
            ],
            [
                'name' => 'Estate Trustees (Pty) Ltd', 'role' => 'seller',
                '_recipient_local_key' => 'executor-key', '_is_deceased' => false,
                '_contact_id' => $executorCo->id,
            ],
        ];

        $expanded = $this->expand($recipients, $agent);

        // The company row must have been replaced by its representative...
        $repRow = collect($expanded)->firstWhere('_contact_id', $rep->id);
        $this->assertNotNull($repRow, 'The company recipient must expand to its representative.');
        $this->assertSame('Jane', $repRow['first_name']);

        // ...and CRITICALLY must still carry the local key the deceased row's binding expects.
        $this->assertSame('executor-key', $repRow['_recipient_local_key'] ?? null,
            'The expanded representative row must carry the original recipient_local_key through.');

        // The deceased row itself must pass through expansion completely untouched
        // (it is already spoken for by the chain-binding pass, not this one).
        $deceasedRow = collect($expanded)->firstWhere('_recipient_local_key', 'deceased-key');
        $this->assertNotNull($deceasedRow);
        $this->assertTrue($deceasedRow['_is_deceased']);

        // And the hard-block guard must now find the substitute and pass.
        $this->assertDeceased($expanded); // no exception = pass
        $this->addToAssertionCount(1);
    }

    /** (b) REGRESSION — a late-estate document with genuinely NO substitute must still be refused. */
    public function test_deceased_seller_with_no_substitute_at_all_still_blocks(): void
    {
        $recipients = [
            [
                'name' => 'John Smith', 'role' => 'seller',
                '_recipient_local_key' => 'deceased-key', '_is_deceased' => true,
                '_recipient_template_id' => 1,
                '_slot_bindings' => ['deceased' => ['type' => 'self']], // executor never bound
            ],
        ];

        try {
            $this->assertDeceased($recipients);
            $this->fail('Expected ValidationException — no substitute was ever chosen.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('John Smith', $e->validator->errors()->first('recipients'));
        }
    }

    /** (b, integration) Same, but via the FULL expand()+assert() pipeline my fix touches — must still bite. */
    public function test_company_executor_row_removed_before_send_still_blocks(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyId]);

        $recipients = [
            [
                'name' => 'John Smith', 'role' => 'seller',
                '_recipient_local_key' => 'deceased-key', '_is_deceased' => true,
                '_recipient_template_id' => 1,
                '_slot_bindings' => [
                    'deceased' => ['type' => 'self'],
                    // Points at a key that will never exist — the agent bound the
                    // slot, then the recipient row was removed before send.
                    'executor' => ['type' => 'recipient', 'recipient_local_key' => 'never-existed'],
                ],
            ],
        ];

        $expanded = $this->expand($recipients, $agent);

        $this->expectException(ValidationException::class);
        $this->assertDeceased($expanded);
    }

    /** (c) REGRESSION — deceased seller + NATURAL PERSON executor (cc6's shape) must be completely unaffected. */
    public function test_deceased_seller_with_natural_person_executor_unchanged(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyId]);

        $naturalExecutor = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'Piet', 'last_name' => 'Executor',
            'id_number' => '7001015800112',
        ]);

        $recipients = [
            [
                'name' => 'John Smith', 'role' => 'seller',
                '_recipient_local_key' => 'deceased-key', '_is_deceased' => true,
                '_recipient_template_id' => 2,
                '_slot_bindings' => [
                    'deceased' => ['type' => 'self'],
                    'executor' => ['type' => 'recipient', 'recipient_local_key' => 'executor-key'],
                ],
            ],
            [
                'name' => 'Piet Executor', 'role' => 'seller',
                '_recipient_local_key' => 'executor-key', '_is_deceased' => false,
                '_contact_id' => $naturalExecutor->id,
            ],
        ];

        // A natural person with no representatives of their own never enters the
        // expansion branch at all — expand() must return the array unchanged.
        $expanded = $this->expand($recipients, $agent);
        $this->assertSame($recipients, $expanded, 'A natural-person executor must pass through expansion untouched.');

        $this->assertDeceased($expanded); // still passes, exactly as before this fix.
        $this->addToAssertionCount(1);
    }
}
