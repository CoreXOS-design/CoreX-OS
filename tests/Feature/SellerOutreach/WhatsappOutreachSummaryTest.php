<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\SellerOutreach\WhatsappOutreachSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-91 — WhatsApp Outreach Summary board.
 *
 * Proves: the 4 outreach-outcome counts per agent are correct, never-contacted
 * contacts are excluded, the 'awaiting' leftover (sent → engaged → no decision)
 * is counted into the total, click-through parity (board cell == drilled list),
 * scope (agent → own row, BM → branch, admin → all), and the unassigned roll-up.
 *
 * Spec: .ai/specs/whatsapp-outreach-summary.md
 */
final class WhatsappOutreachSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // The unseeded-permissions fast-path caches a static flag; reset it so a
        // test that seeds role_permissions can't leak "seeded" into the next test.
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function seedAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $agencyId;
    }

    private function makeUser(int $agencyId, int $branchId, string $role): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => $role,
        ]);
    }

    /**
     * Create a contact in a given board state, optionally with a WhatsApp send.
     *
     * @param array{state:string, agent_id:?int, created_by:int, branch_id:int} $opts
     */
    private function makeContact(int $agencyId, array $opts, bool $withWhatsappSend = true): Contact
    {
        $contact = Contact::create([
            'agency_id' => $agencyId,
            'branch_id' => $opts['branch_id'],
            'first_name' => 'C' . Str::random(4),
            'last_name' => 'Test',
            'phone' => '082' . random_int(1000000, 9999999),
            'created_by_user_id' => $opts['created_by'],
            'agent_id' => $opts['agent_id'],
        ]);

        // Consent columns set directly (bypass fillable) to land the exact state.
        // agent_id forced here too: ContactObserver::creating defaults a null
        // agent_id to the creator, so to test the genuine unassigned (null) case
        // we re-apply the intended value after create, past the observer.
        DB::table('contacts')->where('id', $contact->id)->update(
            $this->stateColumns($opts['state']) + ['agent_id' => $opts['agent_id']]
        );

        if ($withWhatsappSend) {
            DB::table('seller_outreach_sends')->insert([
                'agency_id' => $agencyId,
                'contact_id' => $contact->id,
                'agent_id' => $opts['agent_id'],
                'channel' => 'whatsapp',
                'body_snapshot' => 'Test pitch body',
                'facts_snapshot' => json_encode(['merge_fields' => []]),
                'tracking_short_code' => Str::upper(Str::random(6)),
                'sent_at' => now(),
                'outcome' => 'sent',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $contact->refresh();
    }

    /** @return array<string, mixed> */
    private function stateColumns(string $state): array
    {
        return match ($state) {
            'pending' => ['messaging_opt_out_at' => null, 'messaging_opted_in_at' => null, 'outreach_permission_asked_at' => now()],
            'confirmed' => ['messaging_opt_out_at' => null, 'messaging_opted_in_at' => now(), 'outreach_permission_asked_at' => null],
            'opt_out_no_response' => ['messaging_opt_out_at' => now(), 'messaging_opt_out_kind' => 'no_response'],
            'opted_out' => ['messaging_opt_out_at' => now(), 'messaging_opt_out_kind' => 'declined'],
            'awaiting' => ['messaging_opt_out_at' => null, 'messaging_opted_in_at' => null, 'outreach_permission_asked_at' => null],
            default => [],
        };
    }

    public function test_board_counts_each_state_and_excludes_never_contacted(): void
    {
        $agencyId = $this->seedAgency();
        $admin = $this->makeUser($agencyId, $agencyId, 'admin');
        $agent = $this->makeUser($agencyId, $agencyId, 'agent');

        $base = ['agent_id' => $agent->id, 'created_by' => $agent->id, 'branch_id' => $agencyId];
        $this->makeContact($agencyId, ['state' => 'pending'] + $base);
        $this->makeContact($agencyId, ['state' => 'confirmed'] + $base);
        $this->makeContact($agencyId, ['state' => 'opt_out_no_response'] + $base);
        $this->makeContact($agencyId, ['state' => 'opted_out'] + $base);
        $this->makeContact($agencyId, ['state' => 'awaiting'] + $base);
        // Never contacted on WhatsApp — must NOT appear.
        $this->makeContact($agencyId, ['state' => 'pending'] + $base, withWhatsappSend: false);

        $this->actingAs($admin);
        $board = app(WhatsappOutreachSummaryService::class)->board();

        $row = collect($board['rows'])->firstWhere('agent_id', $agent->id);
        $this->assertNotNull($row, 'Agent row present');
        $this->assertSame(1, $row['pending']);
        $this->assertSame(1, $row['confirmed']);
        $this->assertSame(1, $row['opt_out_no_response']);
        $this->assertSame(1, $row['opted_out']);
        $this->assertSame(1, $row['awaiting']);
        // Total = the 5 WhatsApp-sent contacts; the never-contacted one excluded.
        $this->assertSame(5, $row['total']);
        $this->assertTrue($board['has_awaiting']);
    }

    public function test_total_reconciles_four_states_plus_awaiting(): void
    {
        $agencyId = $this->seedAgency();
        $agent = $this->makeUser($agencyId, $agencyId, 'agent');
        $admin = $this->makeUser($agencyId, $agencyId, 'admin');
        $base = ['agent_id' => $agent->id, 'created_by' => $agent->id, 'branch_id' => $agencyId];

        foreach (['pending', 'pending', 'confirmed', 'opt_out_no_response', 'opted_out', 'awaiting'] as $s) {
            $this->makeContact($agencyId, ['state' => $s] + $base);
        }

        $this->actingAs($admin);
        $row = collect(app(WhatsappOutreachSummaryService::class)->board()['rows'])->firstWhere('agent_id', $agent->id);

        $this->assertSame(
            $row['total'],
            $row['pending'] + $row['confirmed'] + $row['opt_out_no_response'] + $row['opted_out'] + $row['awaiting'],
            'Total must equal the four states plus the awaiting leftover — no silent loss.'
        );
    }

    /** Click-through parity: each board cell count == the drilled contact list total. */
    public function test_drill_through_count_equals_list_total(): void
    {
        $agencyId = $this->seedAgency();
        $admin = $this->makeUser($agencyId, $agencyId, 'admin');
        $agent = $this->makeUser($agencyId, $agencyId, 'agent');
        $base = ['agent_id' => $agent->id, 'created_by' => $agent->id, 'branch_id' => $agencyId];

        // Asymmetric counts so a wrong filter can't coincidentally pass.
        $this->makeContact($agencyId, ['state' => 'pending'] + $base);
        $this->makeContact($agencyId, ['state' => 'pending'] + $base);
        $this->makeContact($agencyId, ['state' => 'opt_out_no_response'] + $base);
        $this->makeContact($agencyId, ['state' => 'opted_out'] + $base);
        $this->makeContact($agencyId, ['state' => 'opted_out'] + $base);
        $this->makeContact($agencyId, ['state' => 'opted_out'] + $base);

        $this->actingAs($admin);
        $row = collect(app(WhatsappOutreachSummaryService::class)->board()['rows'])->firstWhere('agent_id', $agent->id);

        foreach (['pending', 'opt_out_no_response', 'opted_out'] as $state) {
            $resp = $this->actingAs($admin)->get(route('corex.contacts.index', [
                'agent_id' => $agent->id,
                'outreach_state' => $state,
                'channel' => 'whatsapp',
            ]));
            $resp->assertOk();
            $listTotal = $resp->viewData('contacts')->total();
            $this->assertSame(
                $row[$state],
                $listTotal,
                "Cell '{$state}' count ({$row[$state]}) must equal the drilled list total ({$listTotal})."
            );
        }
    }

    public function test_agent_sees_only_own_row_admin_sees_all(): void
    {
        $agencyId = $this->seedAgency();
        $admin = $this->makeUser($agencyId, $agencyId, 'admin');
        $agentA = $this->makeUser($agencyId, $agencyId, 'agent');
        $agentB = $this->makeUser($agencyId, $agencyId, 'agent');

        // Each agent both creates AND owns their contacts (so ContactScope 'own',
        // keyed on created_by, and the board's agent_id grouping coincide).
        $this->makeContact($agencyId, ['state' => 'pending', 'agent_id' => $agentA->id, 'created_by' => $agentA->id, 'branch_id' => $agencyId]);
        $this->makeContact($agencyId, ['state' => 'confirmed', 'agent_id' => $agentB->id, 'created_by' => $agentB->id, 'branch_id' => $agencyId]);

        // Agent A → only their own row.
        $this->actingAs($agentA);
        $rowsA = app(WhatsappOutreachSummaryService::class)->board()['rows'];
        $this->assertCount(1, $rowsA);
        $this->assertSame($agentA->id, $rowsA[0]['agent_id']);

        // Admin → both agents.
        $this->actingAs($admin);
        $rowsAdmin = app(WhatsappOutreachSummaryService::class)->board()['rows'];
        $ids = collect($rowsAdmin)->pluck('agent_id')->all();
        $this->assertContains($agentA->id, $ids);
        $this->assertContains($agentB->id, $ids);
    }

    public function test_unassigned_contacts_roll_into_unassigned_row(): void
    {
        $agencyId = $this->seedAgency();
        $admin = $this->makeUser($agencyId, $agencyId, 'admin');

        $this->makeContact($agencyId, ['state' => 'pending', 'agent_id' => null, 'created_by' => $admin->id, 'branch_id' => $agencyId]);

        $this->actingAs($admin);
        $row = collect(app(WhatsappOutreachSummaryService::class)->board()['rows'])
            ->first(fn ($r) => $r['agent_id'] === null);

        $this->assertNotNull($row);
        $this->assertSame('Unassigned', $row['agent_name']);
        $this->assertSame(1, $row['pending']);
    }

    /** The gated route renders end-to-end for a permitted user (route + middleware + controller guard + view). */
    public function test_authorized_user_can_render_board(): void
    {
        $agencyId = $this->seedAgency();
        $agent = $this->makeUser($agencyId, $agencyId, 'agent');
        $this->makeContact($agencyId, ['state' => 'pending', 'agent_id' => $agent->id, 'created_by' => $agent->id, 'branch_id' => $agencyId]);

        $resp = $this->actingAs($agent)->get(route('corex.outreach-summary.index'));

        $resp->assertOk();
        $resp->assertSee('WhatsApp Outreach Summary');
        $resp->assertViewHas('rows');
    }
}
