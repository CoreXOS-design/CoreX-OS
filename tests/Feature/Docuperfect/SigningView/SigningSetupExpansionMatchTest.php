<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Models\Agency;
use App\Models\Contact;
use App\Models\ContactRepresentative;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Job 1 (Johan/cc1, 2026-08-26) — cc1 independently reproduced, via the real
 * prepare-signing endpoint, that a represented party's signing request
 * reached NOBODY after 3afc42c42: exactly the agent + other signers were
 * created, the representative's own row never was, zero mail sent, and the
 * endpoint still returned 200 "ok":true with no error.
 *
 * ROOT CAUSE (ESignWizardController.php, both prepareSigning() and
 * prepareWetInk()): signing_setup (step 6's drag-reorder/skip-email/FICA
 * data) is built by the FRONTEND against the ORIGINAL recipient names,
 * before expandEntityRecipients() ever runs. Re-matching a signing_setup
 * entry to a recipient by role+NAME *after* expansion silently failed for
 * any entity/represented-party row, because expansion replaces that row's
 * name with the REPRESENTATIVE's name. The failed match just dropped the
 * slot from $orderedRecipients — no error, no log line.
 *
 * FIX: attachSigningSetupMatch() matches BEFORE expansion, while names still
 * agree with what signing_setup was built from, tagging each recipient with
 * a stable _matched_signing_setup_index. expandEntityRecipients() copies
 * that index onto every representative row a matched original recipient
 * expands into, so every downstream lookup (reorder, skip-email, FICA,
 * email override) reads the index instead of re-matching a name expansion
 * already changed. An unmatched signing_setup entry now throws instead of
 * silently vanishing a party from the send.
 */
final class SigningSetupExpansionMatchTest extends TestCase
{
    use RefreshDatabase;

    private function attachMatch(array $recipients, array $signingSetup): array
    {
        $m = new ReflectionMethod(ESignWizardController::class, 'attachSigningSetupMatch');
        $m->setAccessible(true);

        return $m->invoke(app(ESignWizardController::class), $recipients, $signingSetup);
    }

    private function expand(array $recipients, $user): array
    {
        $m = new ReflectionMethod(ESignWizardController::class, 'expandEntityRecipients');
        $m->setAccessible(true);

        return $m->invoke(app(ESignWizardController::class), $recipients, $user);
    }

    public function test_recipients_are_tagged_with_matching_signing_setup_index(): void
    {
        $recipients = [
            ['role' => 'seller', 'name' => 'Anna Seller'],
            ['role' => 'seller', 'name' => 'Ben Seller'],
        ];
        $signingSetup = [
            ['role' => 'agent', 'name' => 'Agent User', 'signing_order' => 1],
            ['role' => 'seller', 'name' => 'Anna Seller', 'signing_order' => 2, 'skipEmail' => true],
            ['role' => 'seller', 'name' => 'Ben Seller', 'signing_order' => 3],
        ];

        $tagged = $this->attachMatch($recipients, $signingSetup);

        $this->assertSame(1, $tagged[0]['_matched_signing_setup_index']);
        $this->assertSame(2, $tagged[1]['_matched_signing_setup_index']);
    }

    public function test_empty_signing_setup_leaves_recipients_untagged(): void
    {
        $recipients = [['role' => 'seller', 'name' => 'Anna Seller']];

        $tagged = $this->attachMatch($recipients, []);

        $this->assertArrayNotHasKey('_matched_signing_setup_index', $tagged[0]);
    }

    /** THE EXACT BUG: a signing_setup entry naming a party that no longer exists in $recipients must throw, never silently drop. */
    public function test_unmatched_signing_setup_entry_throws_instead_of_silently_dropping(): void
    {
        $recipients = [
            ['role' => 'seller', 'name' => 'Anna Seller'],
        ];
        $signingSetup = [
            ['role' => 'agent', 'name' => 'Agent User', 'signing_order' => 1],
            ['role' => 'seller', 'name' => 'Anna Seller', 'signing_order' => 2],
            // A party the recipient array no longer has under this name —
            // exactly what expansion used to produce silently.
            ['role' => 'seller', 'name' => 'A Represented Party Whose Name Changed', 'signing_order' => 3],
        ];

        $this->expectException(ValidationException::class);
        $this->attachMatch($recipients, $signingSetup);
    }

    public function test_agent_entries_in_signing_setup_are_never_matched_or_required(): void
    {
        $recipients = [['role' => 'seller', 'name' => 'Anna Seller']];
        $signingSetup = [
            ['role' => 'agent', 'name' => 'Agent User', 'signing_order' => 1],
            ['role' => 'seller', 'name' => 'Anna Seller', 'signing_order' => 2],
        ];

        $tagged = $this->attachMatch($recipients, $signingSetup);

        $this->assertSame(1, $tagged[0]['_matched_signing_setup_index']);
        $this->addToAssertionCount(1); // no exception for the agent entry
    }

    /**
     * THE FULL REPRODUCTION of cc1's finding: a represented natural person
     * (Piet, represented by Koos) with signing_setup populated exactly as a
     * real wizard step 6 would send it (role+name keyed to Piet, the
     * ORIGINAL party — signing_setup never sees the substitution). Before
     * this fix, expandEntityRecipients() would replace Piet's row with
     * Koos's, the post-expansion reorder would fail to match "Piet" against
     * Koos's row, and Koos's row would be silently dropped from
     * $orderedRecipients. This proves the index survives expansion intact.
     */
    public function test_matched_index_survives_representative_expansion(): void
    {
        $agency = Agency::create(['name' => 'Test Agency ' . uniqid(), 'slug' => 'test-agency-' . uniqid()]);
        $branchId = (int) \Illuminate\Support\Facades\DB::table('branches')->insertGetId([
            'agency_id' => $agency->id, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $piet = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branchId,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON,
            'first_name' => 'Piet', 'last_name' => 'Represented' . uniqid(),
        ]);
        $koos = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branchId,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON,
            'first_name' => 'Koos', 'last_name' => 'Executor' . uniqid(),
            'email' => 'koos.expansionmatch' . uniqid() . '@example.test',
        ]);
        ContactRepresentative::create([
            'entity_contact_id' => $piet->id,
            'representative_contact_id' => $koos->id,
            'capacity' => 'Power of Attorney',
        ]);

        $recipients = [
            ['role' => 'seller', 'name' => $piet->full_name, '_contact_id' => $piet->id],
        ];
        $signingSetup = [
            ['role' => 'agent', 'name' => 'Agent User', 'signing_order' => 1],
            ['role' => 'seller', 'name' => $piet->full_name, 'signing_order' => 2, 'skipEmail' => false],
        ];

        $user = \App\Models\User::factory()->create(['agency_id' => $agency->id]);

        $tagged = $this->attachMatch($recipients, $signingSetup);
        $expanded = $this->expand($tagged, $user);

        $this->assertCount(1, $expanded, 'Expansion must produce exactly one representative row for Piet.');
        $this->assertSame($koos->id, $expanded[0]['_contact_id'], 'The expanded row must be Koos, the representative.');
        $this->assertSame(
            1,
            $expanded[0]['_matched_signing_setup_index'],
            'The signing_setup match (index 1 = Piet\'s original entry) must survive onto Koos\'s expanded row — this is the exact index the reorder step now uses instead of re-matching by name.'
        );
    }
}
