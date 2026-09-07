<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Concerns\EnforcesReauthorisationBinding;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-332 — Johan, verbatim: "re-auth only allowed by original auth party."
 * Unconditional as of 2026-09-07: "No, this is not settings but fixes we
 * are building." No test here may depend on
 * EsignSettings::strictReauthorisationBinding() (it no longer exists — see
 * migration 2026_09_07_025135) — the binding always applies.
 */
final class AmendmentReauthorisationBindingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $originalAuthoriser;
    private User $otherAgent;
    private SignatureTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->originalAuthoriser = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->otherAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        $doc = Document::create(['name' => 'Sole Mandate — Test', 'owner_id' => $this->originalAuthoriser->id, 'agency_id' => $this->agency->id]);
        $this->template = SignatureTemplate::create([
            'document_id'   => $doc->id,
            'document_hash' => Str::random(64),
            'status'        => SignatureTemplate::STATUS_AMENDMENT_REVIEW,
            'created_by'    => $this->originalAuthoriser->id,
        ]);

        SignatureRequest::create([
            'signature_template_id' => $this->template->id,
            'party_role'            => 'supervisor',
            'role_index'            => 1,
            'signing_order'         => 1,
            'signer_name'           => $this->originalAuthoriser->name,
            'signer_email'          => $this->originalAuthoriser->email,
            'token'                 => Str::random(48),
            'token_expires_at'      => now()->addDays(14),
            'status'                => SignatureRequest::STATUS_COMPLETED,
            'authorised_by'         => $this->originalAuthoriser->id,
            'authorised_at'         => now(),
        ]);
    }

    private function harness(): object
    {
        return new class {
            use EnforcesReauthorisationBinding;
            public function check($template, $user, $action) {
                return $this->reauthorisationBindingBlockReason($template, $user, $action);
            }
        };
    }

    public function test_the_original_authoriser_is_never_blocked(): void
    {
        $reason = $this->harness()->check($this->template, $this->originalAuthoriser, 'test');

        $this->assertNull($reason);
    }

    public function test_a_different_agent_is_blocked_with_a_named_message_not_a_403(): void
    {
        $reason = $this->harness()->check($this->template, $this->otherAgent, 'test');

        $this->assertNotNull($reason);
        $this->assertStringContainsString($this->originalAuthoriser->name, $reason);
    }

    public function test_a_blocked_attempt_is_logged_with_actor_and_bound_authoriser_metadata(): void
    {
        $this->harness()->check($this->template, $this->otherAgent, 'amendment_approve');

        $log = SignatureAuditLog::where('signature_template_id', $this->template->id)
            ->where('action', 'amendment_reauthorisation_blocked')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->otherAgent->id, $log->actor_id);
        $this->assertSame($this->originalAuthoriser->id, $log->metadata_json['bound_authoriser_id']);
        $this->assertSame($this->originalAuthoriser->name, $log->metadata_json['bound_authoriser_name']);
        $this->assertSame('amendment_approve', $log->metadata_json['attempted_action']);
    }

    public function test_a_document_never_authorised_through_the_supervisor_flow_is_not_blocked(): void
    {
        $doc = Document::create(['name' => 'No Supervisor Flow', 'owner_id' => $this->originalAuthoriser->id, 'agency_id' => $this->agency->id]);
        $bareTemplate = SignatureTemplate::create([
            'document_id'   => $doc->id,
            'document_hash' => Str::random(64),
            'status'        => SignatureTemplate::STATUS_SIGNING,
            'created_by'    => $this->originalAuthoriser->id,
        ]);

        $reason = $this->harness()->check($bareTemplate, $this->otherAgent, 'test');

        $this->assertNull($reason, 'A document with no recorded original authoriser must fail open, not block indiscriminately.');
    }
}
