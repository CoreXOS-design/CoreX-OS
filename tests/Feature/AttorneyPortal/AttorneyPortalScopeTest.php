<?php

namespace Tests\Feature\AttorneyPortal;

use App\Models\AttorneyPortalAccess;
use App\Models\AttorneyPortalLog;
use App\Models\AttorneyPortalSetting;
use App\Services\AttorneyPortal\AttorneyPortalAccessService;
use App\Services\AttorneyPortal\AttorneyPortalDocuments;
use App\Services\AttorneyPortal\AttorneyPortalScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** .ai/specs/attorney-portal.md §3, §7.4, §9 - who sees which deal, which documents, and until when. */
class AttorneyPortalScopeTest extends TestCase
{
    use RefreshDatabase, AttorneyPortalFixtures;

    private function access($w, $contact): AttorneyPortalAccess
    {
        $r = app(AttorneyPortalAccessService::class)->invite($contact, $w['agent']);

        return $r['access'];
    }

    public function test_an_attorney_sees_only_the_deals_where_that_person_is_assigned(): void
    {
        $w = $this->world();
        $dealA   = $this->deal($w, $this->transferTo($w, $w['a']), ['address' => '1 Alpha Rd']);
        $dealB   = $this->deal($w, $this->transferTo($w, $w['b']), ['address' => '2 Beta Rd']);
        $firmOnly = $this->deal($w, ['transfer_firm' => $w['firm']->id], ['address' => '3 Firm Rd']); // firm, no person
        $bondA   = $this->deal($w, ['bond' => $w['a']->id, 'bond_firm' => $w['firm']->id], ['address' => '4 Bond Rd']);

        $scope = app(AttorneyPortalScope::class);
        $idsA  = $scope->visibleDeals($this->access($w, $w['a']))->pluck('id')->all();
        $idsB  = $scope->visibleDeals($this->access($w, $w['b']))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$dealA['deal']->id, $bondA['deal']->id], $idsA);
        $this->assertSame([$dealB['deal']->id], $idsB, 'a colleague at the same firm sees a different set');
        $this->assertNotContains($firmOnly['deal']->id, array_merge($idsA, $idsB), 'a firm with no chosen person grants nobody');
    }

    public function test_roles_on_a_deal_follow_the_columns(): void
    {
        $w = $this->world();
        $d = $this->deal($w, $this->transferTo($w, $w['a']) + ['bond' => $w['a']->id, 'bond_firm' => $w['firm']->id]);
        $this->assertEqualsCanonicalizing(
            ['transfer_attorney', 'bond_attorney'],
            app(AttorneyPortalScope::class)->rolesOn($d['deal'], $this->access($w, $w['a']))
        );
    }

    public function test_another_agencys_deal_never_reaches_this_access(): void
    {
        $w1 = $this->world();
        $this->deal($w1, $this->transferTo($w1, $w1['a']), ['address' => '1 Own Rd']);

        // a second agency that happens to point a deal at the SAME contact id (a corrupted / forged row)
        $agency2 = \App\Models\Agency::create(['name' => 'Other Agency', 'slug' => 'oa-' . uniqid()]);
        $branch2 = \App\Models\Branch::create(['agency_id' => $agency2->id, 'name' => 'B2']);
        $w2 = ['agency' => $agency2, 'branch' => $branch2, 'agent' => \App\Models\User::factory()->create(['agency_id' => $agency2->id, 'branch_id' => $branch2->id, 'role' => 'agent'])];
        $foreign = $this->deal($w2, ['transfer' => $w1['a']->id, 'transfer_firm' => $w1['firm']->id], ['address' => '9 Foreign Rd']);

        $ids = app(AttorneyPortalScope::class)->visibleDeals($this->access($w1, $w1['a']))->pluck('id')->all();
        $this->assertNotContains($foreign['deal']->id, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_finished_deals_stay_for_the_grace_days_then_disappear(): void
    {
        $w = $this->world();
        AttorneyPortalSetting::where('agency_id', $w['agency']->id)->update(['access_grace_days' => 30]);

        $open     = $this->deal($w, $this->transferTo($w, $w['a']));
        $recent   = $this->deal($w, $this->transferTo($w, $w['a']), ['registration_date' => now()->subDays(10)->toDateString()]);
        $old      = $this->deal($w, $this->transferTo($w, $w['a']), ['registration_date' => now()->subDays(40)->toDateString()]);
        $declined = $this->deal($w, $this->transferTo($w, $w['a']), ['accepted_status' => 'D']);

        $scope = app(AttorneyPortalScope::class);
        $ids   = $scope->visibleDeals($this->access($w, $w['a']))->pluck('id')->all();

        $this->assertContains($open['deal']->id, $ids);
        $this->assertContains($recent['deal']->id, $ids, 'inside the grace days');
        $this->assertNotContains($old['deal']->id, $ids, 'past the grace days');
        $this->assertContains($declined['deal']->id, $ids, 'a just-declined deal is inside its grace days');
        $this->assertNull($scope->find($this->access($w, $w['a']), $old['deal']->id));

        // the agency can shorten it
        AttorneyPortalSetting::where('agency_id', $w['agency']->id)->update(['access_grace_days' => 5]);
        $ids = $scope->visibleDeals($this->access($w, $w['a']))->pluck('id')->all();
        $this->assertNotContains($recent['deal']->id, $ids);
    }

    public function test_documents_are_what_the_matrix_says_filtered_to_what_is_filed(): void
    {
        $w = $this->world();
        $d = $this->deal($w, $this->transferTo($w, $w['a']));
        $this->matrix($w['agency']->id, 'otp', ['transfer_attorney']);
        $this->matrix($w['agency']->id, 'mandate', ['transfer_attorney']);
        $this->matrix($w['agency']->id, 'bond_grant', ['bond_attorney']);   // not for the transferring attorney

        $otp     = $this->filedDoc($d, 'otp', 'deal');
        $mandate = $this->filedDoc($d, 'mandate', 'property');
        $grant   = $this->filedDoc($d, 'bond_grant', 'deal');
        $other   = $this->filedDoc($d, 'inspection_report', 'deal');         // type not in the matrix

        $docs = app(AttorneyPortalDocuments::class)->forDeal($d['deal'], ['transfer_attorney'])->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$otp->id, $mandate->id], $docs);
        $this->assertNotContains($grant->id, $docs);
        $this->assertNotContains($other->id, $docs);
    }

    public function test_deal_specific_papers_on_the_property_and_strangers_papers_do_not_leak(): void
    {
        $w = $this->world();
        $d = $this->deal($w, $this->transferTo($w, $w['a']));
        foreach (['otp', 'fica'] as $slug) {
            $this->matrix($w['agency']->id, $slug, ['transfer_attorney']);
        }

        // an OTP from an earlier, failed offer sits on the same property - never linked to THIS deal
        $oldOtp = $this->filedDoc($d, 'otp', 'property');
        // FICA of the deal's own buyer is offered; FICA of a stranger linked to the property is not
        $buyerFica    = $this->filedDoc($d, 'fica', 'contact', $d['buyer']);
        $stranger     = \App\Models\Contact::withoutEvents(fn () => \App\Models\Contact::create([
            'agency_id' => $w['agency']->id, 'branch_id' => $w['branch']->id, 'first_name' => 'Old', 'last_name' => 'Bidder',
            'created_by_user_id' => $w['agent']->id, 'agent_id' => $w['agent']->id,
        ]));
        $d['property']->contacts()->attach($stranger->id, ['role' => 'buyer', 'created_at' => now(), 'updated_at' => now()]);
        $strangerFica = $this->filedDoc($d, 'fica', 'contact', $stranger);

        $docs = app(AttorneyPortalDocuments::class)->forDeal($d['deal'], ['transfer_attorney'])->pluck('id')->all();

        $this->assertContains($buyerFica->id, $docs);
        $this->assertNotContains($oldOtp->id, $docs, 'an OTP only on the property is not this deal\'s paper');
        $this->assertNotContains($strangerFica->id, $docs, 'only the deal\'s own parties');
    }

    public function test_other_agencys_document_is_never_offered(): void
    {
        $w = $this->world();
        $d = $this->deal($w, $this->transferTo($w, $w['a']));
        $this->matrix($w['agency']->id, 'otp', ['transfer_attorney']);
        $foreign = $this->filedDoc($d, 'otp', 'deal');
        \App\Models\Document::withoutGlobalScopes()->where('id', $foreign->id)->update(['agency_id' => $w['agency']->id + 999]);

        $docs = app(AttorneyPortalDocuments::class)->forDeal($d['deal'], ['transfer_attorney'])->pluck('id')->all();
        $this->assertNotContains($foreign->id, $docs);
    }

    public function test_the_log_is_append_only(): void
    {
        $w = $this->world();
        $access = $this->access($w, $w['a']);
        $row = \App\Services\AttorneyPortal\AttorneyPortalLogger::record($access, 'deals_listed');

        $this->expectException(\LogicException::class);
        $row->update(['event' => 'tampered']);
    }
}
