<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance\Policy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-29 spec §12.5 — submit() signature validation: drawn requires
 * signature_data, typed requires typed_name, the declaration must be accepted;
 * a valid submission completes the acknowledgement.
 */
final class SignatureValidationTest extends TestCase
{
    use RefreshDatabase, PolicyTestHelpers;

    private function freshConfirmedAck(): array
    {
        $agencyId = $this->seedAgency();
        $user = $this->makeUser($agencyId);
        $this->actingAs($user);

        $policy = $this->makePolicy($agencyId, 'comms', 'Communication Policy');
        $version = $this->makeVersion($policy, 1, 'active');
        $ack = $this->confirmedAck($user, $version); // all sections confirmed, not signed

        return [$user, $ack];
    }

    public function test_declaration_must_be_accepted(): void
    {
        [, $ack] = $this->freshConfirmedAck();

        $this->post(route('policy.ack.submit', 'comms'), [
            'signature_type' => 'typed',
            'typed_name'     => 'Jane Agent',
            // declaration_acknowledged omitted
        ])->assertSessionHasErrors('declaration_acknowledged');

        $this->assertSame('in_progress', $ack->fresh()->status);
    }

    public function test_drawn_requires_signature_data(): void
    {
        $this->freshConfirmedAck();

        $this->post(route('policy.ack.submit', 'comms'), [
            'signature_type'           => 'drawn',
            'declaration_acknowledged' => '1',
            // signature_data omitted
        ])->assertSessionHasErrors('signature_data');
    }

    public function test_typed_requires_typed_name(): void
    {
        $this->freshConfirmedAck();

        $this->post(route('policy.ack.submit', 'comms'), [
            'signature_type'           => 'typed',
            'declaration_acknowledged' => '1',
            // typed_name omitted
        ])->assertSessionHasErrors('typed_name');
    }

    public function test_valid_typed_submission_completes_the_acknowledgement(): void
    {
        [, $ack] = $this->freshConfirmedAck();

        $this->post(route('policy.ack.submit', 'comms'), [
            'signature_type'           => 'typed',
            'typed_name'               => 'Jane Agent',
            'declaration_acknowledged' => '1',
        ])->assertRedirect(route('policy.ack.receipt', ['comms', $ack->id]));

        $ack = $ack->fresh();
        $this->assertSame('completed', $ack->status);
        $this->assertSame('typed', $ack->signature_type);
        $this->assertSame('Jane Agent', $ack->typed_signature_name);
        $this->assertNotNull($ack->valid_until);
        $this->assertNotEmpty($ack->declaration_text);
    }
}
