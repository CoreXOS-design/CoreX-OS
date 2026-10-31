<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Prospecting\PropertyIdentityResolver;
use App\Services\Prospecting\SameAddressSweep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * .ai/specs/property-identity.md section 17 (M2-M4): houses and stands at the same number + street + unit merge, flats with no unit number and
 * big blocks go to the review list, a "not the same" answer is respected, and another agency's files are never touched.
 */
final class SameAddressSweepTest extends TestCase
{
    use RefreshDatabase;

    private Agency $a;
    private Agency $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $this->b = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
    }

    private function file(array $attrs = [], ?Agency $agency = null): TrackedProperty
    {
        return TrackedProperty::queryWithoutAgencyScope()->create(array_merge(
            ['agency_id' => ($agency ?? $this->a)->id, 'status' => 'active', 'first_seen_at' => now(), 'street_number' => '33', 'street_name' => 'Windsor Avenue'],
            $attrs,
        ));
    }

    private function sweep(string $scope, bool $apply = true, ?Agency $agency = null): array
    {
        return app(SameAddressSweep::class)->run(($agency ?? $this->a)->id, $scope, $apply);
    }

    private function fileStatus(TrackedProperty $tp): string
    {
        return (string) TrackedProperty::queryWithoutAgencyScope()->find($tp->id)->status;
    }

    private function pending(): int
    {
        return DB::table('property_match_decisions')->where('agency_id', $this->a->id)->where('subject_type', PropertyIdentityResolver::DECISION_SUBJECT)
            ->whereNull('confirmed_at')->whereNull('rejected_at')->count();
    }

    public function test_two_houses_with_no_suburb_at_the_same_number_and_street_merge(): void
    {
        $one = $this->file();
        $two = $this->file();
        $r = $this->sweep('no-suburb');

        $this->assertSame(1, $r['merged_files']);
        $statuses = [$this->fileStatus($one), $this->fileStatus($two)];
        sort($statuses);
        $this->assertSame(['active', 'duplicate'], $statuses, 'one survives, the other is flagged duplicate - never deleted');
        $this->assertSame(2, TrackedProperty::queryWithoutAgencyScope()->where('agency_id', $this->a->id)->count());
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        $this->file();
        $this->file();
        $r = $this->sweep('no-suburb', false);

        $this->assertSame(1, $r['merged_files']);
        $this->assertSame(2, TrackedProperty::queryWithoutAgencyScope()->where('agency_id', $this->a->id)->where('status', 'active')->count());
    }

    public function test_the_exact_scope_needs_the_same_suburb_and_leaves_other_suburbs_alone(): void
    {
        $this->file(['suburb' => 'Margate', 'suburb_normalised' => 'margate']);
        $this->file(['suburb' => 'Margate', 'suburb_normalised' => 'margate']);
        $other = $this->file(['suburb' => 'Uvongo', 'suburb_normalised' => 'uvongo']);

        $r = $this->sweep('exact');

        $this->assertSame(1, $r['merged_files']);
        $this->assertSame('active', $this->fileStatus($other));
    }

    public function test_flats_with_no_unit_number_are_sent_to_the_review_list_not_merged(): void
    {
        $one = $this->file(['suburb' => 'Margate', 'suburb_normalised' => 'margate', 'property_type' => 'Apartment']);
        $two = $this->file(['suburb' => 'Margate', 'suburb_normalised' => 'margate', 'property_type' => 'Apartment']);

        $r = $this->sweep('exact');

        $this->assertSame(0, $r['merged_files']);
        $this->assertSame(1, $r['flat_groups']);
        $this->assertSame(1, $r['review_pairs']);
        $this->assertSame(1, $this->pending());
        $this->assertSame('active', $this->fileStatus($one));
        $this->assertSame('active', $this->fileStatus($two));

        $again = $this->sweep('exact');
        $this->assertSame(0, $again['review_pairs'], 'running it again does not queue the same pair twice');
        $this->assertSame(1, $this->pending());
    }

    public function test_flats_that_name_the_same_unit_do_merge(): void
    {
        $this->file(['suburb' => 'Margate', 'suburb_normalised' => 'margate', 'property_type' => 'Apartment', 'unit_number' => '12']);
        $this->file(['suburb' => 'Margate', 'suburb_normalised' => 'margate', 'property_type' => 'Apartment', 'unit_number' => '12']);

        $this->assertSame(1, $this->sweep('exact')['merged_files']);
    }

    public function test_a_big_block_goes_to_review_not_merge(): void
    {
        for ($i = 0; $i < SameAddressSweep::MAX_AUTO_MERGE_FILES + 1; $i++) {
            $this->file();
        }
        $r = $this->sweep('no-suburb');

        $this->assertSame(0, $r['merged_files']);
        $this->assertSame(1, $r['big_groups']);
        $this->assertSame(SameAddressSweep::MAX_AUTO_MERGE_FILES, $r['review_pairs']);
    }

    public function test_a_pair_the_agent_said_is_not_the_same_is_left_alone(): void
    {
        $one = $this->file();
        $two = $this->file();
        $decisions = app(\App\Services\Prospecting\PropertyMatchDecisionService::class);
        $d = $decisions->record($this->a->id, PropertyIdentityResolver::DECISION_SUBJECT, 'same_address:' . $one->id, 'tracked_property', $two->id, 'address_unit', 'x', null, [], null);
        $d->update(['rejected_at' => now(), 'rejected_by_user_id' => null]);

        $r = $this->sweep('no-suburb');

        $this->assertSame(0, $r['merged_files']);
        $this->assertSame('active', $this->fileStatus($one));
        $this->assertSame('active', $this->fileStatus($two));
    }

    public function test_another_agencys_files_are_never_touched_and_deeds_files_are_skipped(): void
    {
        $mine = $this->file();
        $theirs = $this->file([], $this->b);
        $deeds = $this->file(['capture_kind' => 'deeds_capture']);

        $r = $this->sweep('no-suburb');

        $this->assertSame(0, $r['merged_files'], 'a deeds file is not swept, and the other agency\'s file is not a twin');
        $this->assertSame('active', $this->fileStatus($mine));
        $this->assertSame('active', $this->fileStatus($theirs));
        $this->assertSame('active', $this->fileStatus($deeds));
    }
    public function test_same_address_houses_whose_adverts_contradict_go_to_review_not_to_a_merge(): void
    {
        $one = $this->file(['street_name' => 'Newport Road', 'street_number' => '13', 'suburb' => 'Port Edward', 'suburb_normalised' => 'port edward']);
        $two = $this->file(['street_name' => 'Newport Road', 'street_number' => '13', 'suburb' => 'Port Edward', 'suburb_normalised' => 'port edward']);
        $this->advertOn($one, 5, 700_000);
        $this->advertOn($two, 6, 1_499_000);

        $r = $this->sweep('exact');

        $this->assertSame(0, $r['merged_files']);
        $this->assertSame(1, $r['contradictions']);
        $this->assertSame(1, $r['review_pairs']);
        $this->assertSame('active', $this->fileStatus($one));
        $this->assertSame('active', $this->fileStatus($two));
        $this->assertStringContainsString('different bedrooms', (string) DB::table('property_match_decisions')->where('subject_key', 'like', 'same_address:%')->value('reason'));
    }

    public function test_same_address_houses_with_matching_adverts_still_merge(): void
    {
        $one = $this->file(['street_name' => 'Newport Road', 'street_number' => '13', 'suburb' => 'Port Edward', 'suburb_normalised' => 'port edward']);
        $two = $this->file(['street_name' => 'Newport Road', 'street_number' => '13', 'suburb' => 'Port Edward', 'suburb_normalised' => 'port edward']);
        $this->advertOn($one, 5, 700_000);
        $this->advertOn($two, 5, 720_000);

        $this->assertSame(1, $this->sweep('exact')['merged_files']);
    }

    private function advertOn(TrackedProperty $tp, int $beds, int $price): void
    {
        static $n = 119000000;
        $n++;
        $l = new \App\Models\ProspectingListing();
        $l->forceFill(['agency_id' => $tp->agency_id, 'captured_by_user_id' => \App\Models\User::factory()->create(['agency_id' => $tp->agency_id])->id, 'tracked_property_id' => $tp->id, 'portal_source' => 'p24',
            'portal_ref' => 'P24-' . $n, 'portal_url' => 'https://www.property24.com/x/' . $n, 'suburb' => 'Port Edward', 'price' => $price, 'bedrooms' => $beds, 'bathrooms' => 2,
            'property_type' => 'House', 'first_seen_at' => now(), 'last_seen_at' => now(), 'is_active' => true, 'listing_type' => 'sale'])->save();
    }
}
