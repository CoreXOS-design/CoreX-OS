<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The backfill Johan ruled on: "we will have to run a backfill on live once
 * we have the upgrade to core matches that we clear the old core matches
 * out." Written and tested here (isolated test database, RefreshDatabase —
 * never the shared QA1 database) but NOT RUN anywhere until Johan's word,
 * on live, at upgrade time.
 */
final class BackfillSetAsideForLostBuyersCommandTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Backfill Test Agency', 'slug' => 'backfill-test-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
    }

    private function lostContact(string $suffix): Contact
    {
        return Contact::create([
            'agency_id' => $this->agency->id, 'created_by_user_id' => $this->agent->id,
            'first_name' => 'Lost', 'last_name' => $suffix, 'is_buyer' => true, 'buyer_state' => 'lost',
        ]);
    }

    private function match(Contact $contact): ContactMatch
    {
        return ContactMatch::create([
            'agency_id' => $this->agency->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $this->agent->id, 'name' => 'Test', 'listing_type' => 'sale',
        ]);
    }

    public function test_dry_run_reports_counts_and_writes_nothing(): void
    {
        $contact = $this->lostContact('NotSetAsideYet');
        $match = $this->match($contact);

        $this->artisan('core-matches:backfill-set-aside-lost-buyers', ['--dry-run' => true])
            ->expectsOutputToContain('1 contact(s) in Lost with 1 match(es) not yet set aside.')
            ->assertExitCode(0);

        $match->refresh();
        $this->assertNull($match->set_aside_at, 'dry-run must never write');
    }

    public function test_real_run_sets_aside_matches_for_lost_buyers_never_touched_before(): void
    {
        $contact = $this->lostContact('Backlog');
        $match1 = $this->match($contact);
        $match2 = $this->match($contact);

        $this->artisan('core-matches:backfill-set-aside-lost-buyers')->assertExitCode(0);

        $match1->refresh();
        $match2->refresh();
        $this->assertNotNull($match1->set_aside_at);
        $this->assertNotNull($match2->set_aside_at);
    }

    public function test_a_match_already_set_aside_is_left_completely_untouched(): void
    {
        $contact = $this->lostContact('AlreadyHandled');
        $match = $this->match($contact);
        $originalTimestamp = now()->subDays(3);
        $match->forceFill(['set_aside_at' => $originalTimestamp])->save();

        $this->artisan('core-matches:backfill-set-aside-lost-buyers')->assertExitCode(0);

        $match->refresh();
        // Second-precision comparison — the DATETIME column round-trip
        // drops sub-second precision, which is not what this assertion is
        // checking; it's checking the command left the row alone.
        $this->assertSame(
            $originalTimestamp->format('Y-m-d H:i:s'),
            $match->set_aside_at->format('Y-m-d H:i:s'),
            'must not overwrite an existing set_aside_at timestamp'
        );
    }

    public function test_a_non_lost_buyers_match_is_never_touched(): void
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'created_by_user_id' => $this->agent->id,
            'first_name' => 'Warm', 'last_name' => 'Buyer', 'is_buyer' => true, 'buyer_state' => 'warm',
        ]);
        $match = $this->match($contact);

        $this->artisan('core-matches:backfill-set-aside-lost-buyers')->assertExitCode(0);

        $match->refresh();
        $this->assertNull($match->set_aside_at);
    }

    public function test_a_soft_deleted_match_is_never_touched(): void
    {
        $contact = $this->lostContact('DeletedMatch');
        $match = $this->match($contact);
        $match->delete();

        $this->artisan('core-matches:backfill-set-aside-lost-buyers')->assertExitCode(0);

        $this->assertDatabaseHas('contact_matches', ['id' => $match->id, 'set_aside_at' => null]);
    }

    public function test_running_twice_is_idempotent(): void
    {
        $contact = $this->lostContact('RunTwice');
        $match = $this->match($contact);

        $this->artisan('core-matches:backfill-set-aside-lost-buyers')->assertExitCode(0);
        $match->refresh();
        $firstTimestamp = $match->set_aside_at;
        $this->assertNotNull($firstTimestamp);

        $this->artisan('core-matches:backfill-set-aside-lost-buyers')
            ->expectsOutputToContain('0 contact(s) in Lost with 0 match(es) not yet set aside.')
            ->assertExitCode(0);

        $match->refresh();
        $this->assertTrue($firstTimestamp->equalTo($match->set_aside_at), 'a second run must not re-stamp an already-handled row');
    }

    public function test_no_rows_are_ever_deleted(): void
    {
        $contact = $this->lostContact('NoDeletes');
        $match = $this->match($contact);

        $this->artisan('core-matches:backfill-set-aside-lost-buyers')->assertExitCode(0);

        $this->assertDatabaseHas('contact_matches', ['id' => $match->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'deleted_at' => null]);
    }
}
