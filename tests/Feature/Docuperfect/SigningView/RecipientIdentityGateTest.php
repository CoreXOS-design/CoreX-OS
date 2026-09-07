<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AT-385 — Johan, 2026-09-07: "No, this is not settings but fixes we are
 * building." The identity gate is unconditional — there is no setting to
 * turn it off, no agency exemption. This locks that in: no test here may
 * depend on EsignSettings::requireIdentityBeforeSend() (it no longer
 * exists — see migration 2026_09_07_025135), and every assertion proves
 * the gate fires regardless of any per-agency configuration.
 */
final class RecipientIdentityGateTest extends TestCase
{
    use RefreshDatabase;

    private function invokeGate(array &$recipients): void
    {
        $controller = app(ESignWizardController::class);
        $ref = new ReflectionMethod($controller, 'assertRecipientsHaveIdentityForSend');
        $ref->setAccessible(true);
        $ref->invokeArgs($controller, [&$recipients]);
    }

    public function test_a_party_with_no_id_and_no_passport_is_blocked(): void
    {
        $recipients = [
            ['role' => 'agent', 'name' => 'Agent Smith', 'id_number' => ''],
            ['role' => 'buyer', 'name' => 'John Doe', 'id_number' => '', 'passport_number' => '', '_contact_id' => null],
        ];

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->invokeGate($recipients);
    }

    public function test_a_passport_only_party_is_not_blocked(): void
    {
        $recipients = [
            ['role' => 'buyer', 'name' => 'Jane Foreigner', 'id_number' => '', 'passport_number' => 'P1234567', '_contact_id' => null],
        ];

        $this->invokeGate($recipients);
        $this->assertTrue(true); // reaching here means no exception was thrown
    }

    public function test_an_id_only_party_is_not_blocked(): void
    {
        $recipients = [
            ['role' => 'seller', 'name' => 'Nomsa Dlamini', 'id_number' => '8501015800083', 'passport_number' => '', '_contact_id' => null],
        ];

        $this->invokeGate($recipients);
        $this->assertTrue(true);
    }

    public function test_the_agent_role_is_never_gated(): void
    {
        $recipients = [
            ['role' => 'agent', 'name' => 'Agent Smith', 'id_number' => '', 'passport_number' => ''],
        ];

        $this->invokeGate($recipients);
        $this->assertTrue(true);
    }

    /**
     * Document-first, contact-fallback: a blank recipient row backed by a
     * Contact that DOES have an ID passes, and the resolved value is
     * backfilled onto the row so the SignatureRequest created afterwards
     * actually carries it (without this, the /sign gateway's ID gate would
     * never fire for that party at all — see the AT-385 fix commit).
     */
    public function test_a_blank_row_is_rescued_by_its_linked_contact_and_the_value_is_backfilled(): void
    {
        $agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Ramsgate']);
        $contact = Contact::create([
            'agency_id'  => $agency->id,
            'branch_id'  => $branch->id,
            'first_name' => 'Piet',
            'last_name'  => 'Botha',
            'id_number'  => '7001015800089',
        ]);

        $recipients = [
            ['role' => 'seller', 'name' => 'Piet Botha', 'id_number' => '', 'passport_number' => '', '_contact_id' => $contact->id],
        ];

        $this->invokeGate($recipients);

        $this->assertSame('7001015800089', $recipients[0]['id_number']);
    }

    public function test_a_blank_row_with_no_linked_contact_is_still_blocked(): void
    {
        $recipients = [
            ['role' => 'tenant', 'name' => 'No Contact Person', 'id_number' => '', 'passport_number' => '', '_contact_id' => null],
        ];

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->invokeGate($recipients);
    }
}
