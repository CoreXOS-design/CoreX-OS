<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * HD-5 / P2-1a — the agent checkpoint fires between GROUPS, not between people.
 *
 * Today the ceremony stops for agent approval after EVERY external party: seller 1 signs → the agent
 * must approve → seller 2 signs → the agent must approve again. For joint sellers on one mandate that
 * is friction with no decision in it — the agent is authorising the gap between two people signing the
 * same document for the same reason. Doctrine (esign-ceremony-v3 §4): mandate = `sellers → agent`,
 * with the sellers as ONE group.
 *
 * THE DEFAULT MUST NOT MOVE. `signing_group` is NULLABLE and NULL means "a group of one". Had the
 * column defaulted to 1, every party in every EXISTING ceremony would have landed in the same group
 * and every intermediate checkpoint in every live lease flow (tenant → agent → landlord) would have
 * silently disappeared on deploy. The first test below is that regression guard, and it is the reason
 * the column is nullable.
 *
 * Sequential WITHIN a group is deliberate, not a shortcut: two people inside one signing view at once
 * is exactly how captured-but-unsubmitted signatures get destroyed (the P0 signing-view invariant).
 * A group changes WHERE THE CHECKPOINT FIRES — never how many links go out at once.
 */
final class SigningGroupCheckpointTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;
    private SignatureService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);
        $this->agent = User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'agent',
        ]);
        $this->actingAs($this->agent);

        $this->service = app(SignatureService::class);
    }

    private function ceremony(): SignatureTemplate
    {
        $document = Document::create([
            'name'     => 'Sole Mandate — 27 Marine Drive, Ramsgate',
            'owner_id' => $this->agent->id,
        ]);

        return SignatureTemplate::create([
            'document_id'   => $document->id,
            'document_hash' => Str::random(64),
            'status'        => SignatureTemplate::STATUS_SIGNING,
            'created_by'    => $this->agent->id,
        ]);
    }

    private function party(
        SignatureTemplate $template,
        string $role,
        string $name,
        int $order,
        ?int $group = null,
        string $status = SignatureRequest::STATUS_WAITING,
    ): SignatureRequest {
        return SignatureRequest::create([
            'signature_template_id' => $template->id,
            'party_role'            => $role,
            'role_index'            => $order,
            'signing_order'         => $order,
            'signing_group'         => $group,
            'signer_name'           => $name,
            'signer_email'          => Str::slug($name) . '@example.co.za',
            'token'                 => Str::random(48),
            'token_expires_at'      => now()->addDays(14),
            'status'                => $status,
        ]);
    }

    /**
     * THE REGRESSION GUARD. An UNGROUPED ceremony must behave exactly as it does today: the agent is
     * asked to approve after the first external party, and the second party is NOT released.
     */
    public function test_ungrouped_parties_still_checkpoint_after_every_party(): void
    {
        $template = $this->ceremony();
        $seller1 = $this->party($template, 'seller', 'Nomsa Dlamini', 1, group: null, status: SignatureRequest::STATUS_PENDING);
        $seller2 = $this->party($template, 'seller', 'Thabo Dlamini', 2, group: null);

        $this->service->handlePartyCompletion($template, 'seller', $seller1);

        $this->assertSame(SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL, $template->fresh()->status,
            'With no groups, the agent checkpoint must still fire after the first party — as it does today.');
        $this->assertSame(SignatureRequest::STATUS_WAITING, $seller2->fresh()->status,
            'The second party must NOT have been released without the agent.');
    }

    /** Joint sellers in ONE group: seller 1 hands straight to seller 2, with no agent in between. */
    public function test_a_grouped_party_hands_straight_to_the_next_member_without_a_checkpoint(): void
    {
        $template = $this->ceremony();
        $seller1 = $this->party($template, 'seller', 'Johan Muller', 1, group: 1, status: SignatureRequest::STATUS_PENDING);
        $seller2 = $this->party($template, 'seller', 'Marlene Muller', 2, group: 1);

        $this->service->handlePartyCompletion($template, 'seller', $seller1);

        $this->assertNotSame(SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL, $template->fresh()->status,
            'The agent must NOT be asked to authorise the gap between two joint sellers.');
        $this->assertSame(SignatureRequest::STATUS_PENDING, $seller2->fresh()->status,
            'Seller 2 must have been sent their link automatically.');
        $this->assertNotNull($seller2->fresh()->sent_at);
    }

    /** …and when the LAST member of the group finishes, the checkpoint fires as normal. */
    public function test_the_checkpoint_fires_when_the_last_member_of_the_group_completes(): void
    {
        $template = $this->ceremony();
        $seller1 = $this->party($template, 'seller', 'Johan Muller', 1, group: 1, status: SignatureRequest::STATUS_COMPLETED);
        $seller2 = $this->party($template, 'seller', 'Marlene Muller', 2, group: 1, status: SignatureRequest::STATUS_PENDING);

        $this->service->handlePartyCompletion($template, 'seller', $seller2);

        $this->assertSame(SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL, $template->fresh()->status,
            'The group is finished — now the agent checkpoints.');
    }

    /** A group of one behaves like an ungrouped party: it checkpoints on its own. */
    public function test_a_group_of_one_checkpoints_on_its_own(): void
    {
        $template = $this->ceremony();
        $seller = $this->party($template, 'seller', 'Refilwe Mabaso', 1, group: 1, status: SignatureRequest::STATUS_PENDING);
        $this->party($template, 'buyer', 'Pieter van Niekerk', 2, group: 2);   // a DIFFERENT group

        $this->service->handlePartyCompletion($template, 'seller', $seller);

        $this->assertSame(SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL, $template->fresh()->status,
            'The next party is in another group, so the agent checkpoints between them.');
    }

    /** The hand-off inside a group is recorded — the evidence timeline must show who held the pen. */
    public function test_the_in_group_handoff_is_audited(): void
    {
        $template = $this->ceremony();
        $seller1 = $this->party($template, 'seller', 'Johan Muller', 1, group: 1, status: SignatureRequest::STATUS_PENDING);
        $this->party($template, 'seller', 'Marlene Muller', 2, group: 1);

        $this->service->handlePartyCompletion($template, 'seller', $seller1);

        $log = SignatureAuditLog::where('signature_template_id', $template->id)
            ->where('action', 'group_member_completed')
            ->first();

        $this->assertNotNull($log, 'An in-group hand-off is a real ceremony event and must be on the timeline.');
        // The column is metadata_json (cast to array) — NOT `metadata`.
        $this->assertSame('Marlene Muller', $log->metadata_json['next_in_group'] ?? null);
        $this->assertSame(1, $log->metadata_json['signing_group'] ?? null);
    }

    // ── HD-6: who actually STAMPS the groups ────────────────────────────────────────────────────

    /**
     * A mandate's plan (`sellers → agent`) puts both sellers in group 1 and the agent in group 2 —
     * so the ceremony checkpoints exactly once, after the sellers, which is the doctrine.
     */
    public function test_a_mandate_plan_groups_the_sellers_together_and_the_agent_alone(): void
    {
        $template = $this->ceremony();
        $template->update(['group_order_json' => SignatureTemplate::GROUP_ORDER_MANDATE]);

        $seller1 = $this->service->createSigningRequest($template, 'seller', 'Johan Muller', 'johan@example.co.za');
        $seller2 = $this->service->createSigningRequest($template, 'seller', 'Marlene Muller', 'marlene@example.co.za');
        $agent   = $this->service->createSigningRequest($template, 'agent', $this->agent->name, $this->agent->email);

        $this->assertSame(1, $seller1->signing_group);
        $this->assertSame(1, $seller2->signing_group, 'Joint sellers sign as ONE group.');
        $this->assertSame(2, $agent->signing_group, 'The agent is their own group — that is where the checkpoint lands.');
    }

    /**
     * THE DEFAULT. A ceremony with no plan stamps NULL, so every existing flow (a lease, above all)
     * keeps checkpointing after every party exactly as it does today. Grouping is opt-in or it is a
     * silent change to live behaviour.
     */
    public function test_a_ceremony_with_no_plan_leaves_every_party_ungrouped(): void
    {
        $template = $this->ceremony();   // no group_order_json

        $tenant   = $this->service->createSigningRequest($template, 'tenant', 'Farhana Patel', 'farhana@example.co.za');
        $landlord = $this->service->createSigningRequest($template, 'landlord', 'Sarel Botha', 'sarel@example.co.za');

        $this->assertNull($tenant->signing_group);
        $this->assertNull($landlord->signing_group);
    }

    /** A party the plan never mentions is not swept into someone else's group. */
    public function test_a_party_outside_the_plan_stands_alone(): void
    {
        $template = $this->ceremony();
        $template->update(['group_order_json' => SignatureTemplate::GROUP_ORDER_MANDATE]);

        $witness = $this->service->createSigningRequest($template, 'witness', 'Ayanda Khumalo', 'ayanda@example.co.za');

        $this->assertNull($witness->signing_group,
            'A witness is not a seller — the plan does not name them, so they are a group of one.');
    }

    /** The completing party is marked completed either way — grouping must not skip that. */
    public function test_the_completing_party_is_marked_completed(): void
    {
        $template = $this->ceremony();
        $seller1 = $this->party($template, 'seller', 'Johan Muller', 1, group: 1, status: SignatureRequest::STATUS_PENDING);
        $this->party($template, 'seller', 'Marlene Muller', 2, group: 1);

        $this->service->handlePartyCompletion($template, 'seller', $seller1);

        $this->assertSame(SignatureRequest::STATUS_COMPLETED, $seller1->fresh()->status);
        $this->assertNotNull($seller1->fresh()->completed_at);
    }
}
