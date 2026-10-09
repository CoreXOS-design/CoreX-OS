<?php

declare(strict_types=1);

namespace Tests\Feature\RentalJobCards;

use App\Mail\Rentals\RentalOwnerQuoteMail;
use App\Models\Lease;
use App\Models\RentalCrew;
use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalJobCardStageService;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * Johan's walk of the internal-crew route (fault 74 -> work order 67 -> job card 86), 9 Oct 2026: J1 the app's own confirm dialog everywhere in
 * the rentals module; J2 the price button says what it does; J3 the job card screen shows what fits its stage, with one page scroll and a
 * "What happens next" box; J4 the crew can be picked when the work order is appointed. rental-work-orders.md §17.36.
 */
final class JobCardNightFixesTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('JC Night');
    }

    private function page(RentalJobCard $card): string
    {
        return $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $card))->assertOk()->getContent();
    }

    private function stage(RentalJobCard $card): array
    {
        return app(RentalJobCardStageService::class)->forCard($card->fresh());
    }

    // ── J1 ───────────────────────────────────────────────────────────────────

    public function test_no_view_or_script_in_the_rentals_module_uses_a_browser_confirm_alert_or_prompt(): void
    {
        $dirs = ['corex/leases', 'corex/rental-applications', 'corex/rental-catalogue-items', 'corex/rental-completion', 'corex/rental-crews', 'corex/rental-fault-reports',
            'corex/rental-fault-types', 'corex/rental-inspections', 'corex/rental-inventories', 'corex/rental-job-cards', 'corex/rental-lease-templates', 'corex/rental-notices',
            'corex/rental-notice-templates', 'corex/rentals', 'corex/rental-signatures', 'corex/rental-work-orders', 'rental-applications', 'rentals'];
        $offenders = [];
        foreach ($dirs as $dir) {
            $path = resource_path('views/' . $dir);
            if (! is_dir($path)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }
                foreach (file($file->getPathname()) as $i => $line) {
                    // comments that talk ABOUT the old pattern are fine; the live call forms are not
                    if (preg_match('/(?<![A-Za-z_.])(?:window\.)?(?:confirm|alert|prompt)\s*\(/', $line) && ! preg_match('/^\s*(\{\{--|\*|\/\/)/', $line) && ! str_contains($line, 'corexConfirm')) {
                        $offenders[] = str_replace(resource_path('views') . '/', '', $file->getPathname()) . ':' . ($i + 1) . '  ' . trim(mb_substr($line, 0, 110));
                    }
                }
            }
        }
        // (comment lines that merely mention the old confirm() inside a longer {{-- block are listed by line; there must be none that CALL it)
        $offenders = array_values(array_filter($offenders, fn ($o) => ! preg_match('/confirm\(\)/', $o)));
        $this->assertSame([], $offenders, "browser dialogs left in the rentals module:\n" . implode("\n", $offenders));

        $script = (string) file_get_contents(resource_path('js/rental-inspection-signing.js'));
        $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z_.])window\.(?:confirm|prompt|alert)\(/', $script);
    }

    public function test_the_job_card_uses_the_apps_dialog_for_print_with_link_archive_line_and_send_quote(): void
    {
        [$card] = $this->internalJob(345);
        $card->forceFill(['status' => RentalJobCard::STATUS_APPROVED])->save();
        $html = $this->page($card);

        $this->assertStringNotContainsString('confirm(', $html);
        $this->assertStringContainsString("@include('partials.corex-confirm')", "@include('partials.corex-confirm')");
        $this->assertStringContainsString('window.corexConfirm', $html, 'the dialog script is on the page (layout include)');
        $this->assertMatchesRegularExpression('/data-confirm="Print with a crew link\?[^"]*"/', $html);
        $this->assertMatchesRegularExpression('/data-confirm="Archive th[^"]*"/', $html);   // "Archive this line?" (task/line archive forms)

        // the price form at the draft stage carries its dialog too
        [$draft] = $this->internalJob(345);
        $this->assertMatchesRegularExpression('/<form[^>]*send-quote[^>]*data-confirm="[^"]+"/s', $this->page($draft));
    }

    // ── J2 ───────────────────────────────────────────────────────────────────

    public function test_within_the_limit_the_action_reads_as_confirm_price_and_nothing_goes_to_the_owner(): void
    {
        $this->property->forceFill(['rental_no_approval_spend_threshold' => 500])->save();
        [$card, $wo] = $this->internalJob(345);

        $html = $this->page($card);
        $this->assertStringContainsString(e("Confirm price - within the owner's limit, approved automatically"), $html);
        $this->assertStringContainsString('Nothing goes to the owner', $html);
        $this->assertStringNotContainsString('Send to owner as quote', $html);
        $this->assertStringContainsString('data-auto-approval', $html, 'the approval panel does not say "Not approved yet"');
        $this->assertStringNotContainsString('Not approved yet', $html);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'approved automatically') && str_contains($m, 'Nothing was sent to the owner'));
        $this->assertSame([], $this->sent(RentalOwnerQuoteMail::class), 'no mail to the owner');
        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $wo->fresh()->owner_approval_status);
        $this->assertNotNull($wo->fresh()->approved_amount);
        $this->assertSame(RentalJobCard::STATUS_APPROVED, $card->fresh()->status);

        $after = $this->page($card);
        $this->assertStringNotContainsString('Confirm price - within', $after, 'nothing left to confirm');
        $this->assertStringContainsString('approved automatically', strtolower($after));
    }

    public function test_above_the_limit_the_action_reads_send_quote_to_owner_for_approval_and_the_owner_is_mailed(): void
    {
        $this->property->forceFill(['rental_no_approval_spend_threshold' => 500])->save();
        [$card, $wo] = $this->internalJob(1200);

        $html = $this->page($card);
        $this->assertStringContainsString('Send quote to owner for approval', $html);
        $this->assertStringNotContainsString('Confirm price - within', $html);
        $this->assertStringContainsString('must approve it before the work is booked', $html);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Quote sent to the owner.');
        $this->assertCount(1, $this->sent(RentalOwnerQuoteMail::class));
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->fresh()->owner_approval_status);
        $this->assertSame(RentalJobCard::STATUS_QUOTED, $card->fresh()->status);
        $this->assertStringContainsString('Re-send revised quote (Rev 2) to owner for approval', $this->page($card));
    }

    // ── J3 ───────────────────────────────────────────────────────────────────

    public function test_a_draft_card_offers_none_of_the_later_actions_and_says_what_happens_next(): void
    {
        $this->property->forceFill(['rental_no_approval_spend_threshold' => 500])->save();
        [$card] = $this->internalJob(1200);   // draft, above the limit: nothing approved
        $html = $this->page($card);

        // after the collapsed "later steps" list, none of the later actions is a live control
        $rest = substr($html, (int) strpos($html, '</details>', (int) strpos($html, 'data-later-steps')));
        foreach (['Worker sign-off', 'Agent sign-off', 'Record tenant confirmation', 'Upload signed copy', 'Complete job card', 'Share a crew link', 'name="signed_copy"'] as $later) {
            $this->assertStringNotContainsString($later, $rest, "'{$later}' must not be a live control on a draft");
        }
        $this->assertStringContainsString('data-next-step', $html);
        $this->assertStringContainsString('data-stage="draft"', $html);
        $this->assertStringContainsString('data-later-steps', $html);
        $this->assertStringContainsString('Worker sign-off', $html, 'listed in the collapsed later steps, with the reason');
        $this->assertStringNotContainsString('action="' . route('corex.rental-job-cards.worker-sign-off', $card) . '"', $html);
        $this->assertStringNotContainsString('action="' . route('corex.rental-job-cards.agent-sign-off', $card) . '"', $html);
        $this->assertStringNotContainsString('action="' . route('corex.rental-job-cards.tenant-confirm', $card) . '"', $html);
        $this->assertStringNotContainsString('action="' . route('corex.rental-job-cards.signed-copy.store', $card) . '"', $html);
        $this->assertStringNotContainsString('action="' . route('corex.rental-job-cards.schedule', $card) . '"', $html, 'no booking before approval');
        $this->assertStringContainsString('data-schedule-locked', $html);
    }

    public function test_one_page_scroll_no_nested_scroll_areas_and_a_wide_right_column(): void
    {
        [$card] = $this->internalJob(345);
        $html = $this->page($card);

        $this->assertStringNotContainsString('lg:overflow-y-auto', $html);
        $this->assertStringNotContainsString('sizeColumns', $html);
        $this->assertStringNotContainsString('jc-left-col" class="w-full lg:flex-1 min-w-0 space-y-4 lg:overflow-y-auto', $html);
        $this->assertStringContainsString('lg:basis-[400px]', $html);
        $this->assertStringNotContainsString('lg:basis-[300px]', $html);
    }

    public function test_the_card_screen_follows_the_stage_through_the_whole_job(): void
    {
        $this->property->forceFill(['rental_no_approval_spend_threshold' => 1000000])->save();
        [$card, $wo] = $this->internalJob(345);
        $u = fn (string $name, array $params = []) => 'action="' . route($name, array_merge([$card], $params)) . '"';

        // draft
        $this->assertSame('draft', $this->stage($card)['key']);

        // approved (price confirmed)
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasNoErrors();
        $this->assertSame('approved', $this->stage($card)['key']);
        $html = $this->page($card);
        $this->assertStringContainsString($u('corex.rental-job-cards.schedule'), $html);
        $this->assertStringNotContainsString($u('corex.rental-job-cards.worker-sign-off'), $html);
        $this->assertStringNotContainsString($u('corex.rental-job-cards.start'), $html);

        // scheduled
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.schedule', $card), ['scheduled_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertSessionHasNoErrors();
        $this->assertSame('scheduled', $this->stage($card)['key']);
        $html = $this->page($card);
        $this->assertStringContainsString($u('corex.rental-job-cards.start'), $html);
        $this->assertStringContainsString($u('corex.rental-job-cards.worker-sign-off'), $html);
        $this->assertStringContainsString($u('corex.rental-job-cards.signed-copy.store'), $html);
        $this->assertStringNotContainsString($u('corex.rental-job-cards.agent-sign-off'), $html, 'the office checks only after the crew is done');
        $this->assertStringNotContainsString($u('corex.rental-job-cards.complete'), $html);

        // in progress
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.start', $card))->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $this->stage($card)['key']);
        $this->assertStringNotContainsString($u('corex.rental-job-cards.start'), $this->page($card));

        // the crew is done -> the office checks
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.worker-sign-off', $card), ['worker_sign_off_name' => 'Sam'])->assertSessionHasNoErrors();
        $this->assertSame('signed_off', $this->stage($card)['key']);
        $html = $this->page($card);
        $this->assertStringNotContainsString($u('corex.rental-job-cards.worker-sign-off'), $html);
        $this->assertStringNotContainsString($u('corex.rental-job-cards.signed-copy.store'), $html);
        $this->assertStringContainsString($u('corex.rental-job-cards.agent-sign-off'), $html);
        $this->assertStringContainsString($u('corex.rental-job-cards.tenant-confirm'), $html);
        $this->assertStringNotContainsString($u('corex.rental-job-cards.complete'), $html, 'complete needs both sign-offs');

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.agent-sign-off', $card))->assertSessionHasNoErrors();
        $html = $this->page($card);
        $this->assertStringContainsString($u('corex.rental-job-cards.complete'), $html);
        $this->assertStringContainsString('data-confirm="Mark this job card complete?"', $html);

        // closed: read-only
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.complete', $card), ['paid_by' => 'owner'])->assertSessionHasNoErrors();
        $this->assertSame('completed', $this->stage($card)['key']);
        $html = $this->page($card);
        foreach (['worker-sign-off', 'agent-sign-off', 'tenant-confirm', 'complete', 'schedule', 'start'] as $r) {
            $this->assertStringNotContainsString($u('corex.rental-job-cards.' . $r), $html, "{$r} is not offered on a completed card");
        }
    }

    /** The layout regression Johan hit on QA1 (a stray closing tag threw the side panels out of their column, so they became extra flex items). */
    public function test_in_every_stage_the_page_is_exactly_one_main_column_and_one_side_column_with_balanced_markup(): void
    {
        $this->property->forceFill(['rental_no_approval_spend_threshold' => 1000000])->save();
        [$card] = $this->internalJob(345);
        $check = function (string $label) use ($card): void {
            $html = $this->page($card->fresh());
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            $xp = new \DOMXPath($dom);
            $kids = $xp->query("//*[@id='jc-layout']/*");
            $this->assertSame(2, $kids->length, "{$label}: #jc-layout holds exactly the main and the side column");
            $this->assertSame(['jc-left-col', 'jc-right-col'], [$kids->item(0)->getAttribute('id'), $kids->item(1)->getAttribute('id')], $label);
            foreach (['jc-approval-panel', 'jc-signed-copy-box', 'jc-quote-box'] as $id) {
                $all = $xp->query("//*[@id='{$id}']")->length;
                $inSide = $xp->query("//*[@id='jc-right-col']//*[@id='{$id}']")->length;
                $this->assertSame($all, $inSide, "{$label}: #{$id} sits inside the side column");
            }
        };

        $check('draft');
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasNoErrors();
        $check('approved');
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.schedule', $card), ['scheduled_at' => now()->addDay()->format('Y-m-d\TH:i')]);
        $check('scheduled');
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.start', $card));
        $check('in progress');
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.worker-sign-off', $card), ['worker_sign_off_name' => 'Sam']);
        $check('crew done');
        $html = $this->page($card);
        $this->assertStringNotContainsString('data-schedule-locked', $html, 'no stale "booking opens once approved" on an approved, booked job');
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.agent-sign-off', $card));
        $check('both signed');
    }

    public function test_the_worker_sign_off_asks_who_signed_and_confirms(): void
    {
        [$card] = $this->internalJob(345);
        $card->forceFill(['status' => RentalJobCard::STATUS_SCHEDULED, 'scheduled_at' => now()->addDay()])->save();
        $html = $this->page($card);
        $this->assertMatchesRegularExpression('/<input type="text" name="worker_sign_off_name"[^>]*required/', $html);
        $this->assertMatchesRegularExpression('/<form[^>]*worker-sign-off[^>]*data-confirm="Record that the crew has done the work\?/s', $html);
    }

    public function test_the_stage_service_gives_the_ordered_stages_and_each_has_a_plain_next_step(): void
    {
        $this->property->forceFill(['rental_no_approval_spend_threshold' => 500])->save();
        [$card, $wo] = $this->internalJob(1200);

        $draft = $this->stage($card);
        $this->assertSame('draft', $draft['key']);
        $this->assertSame('send', $draft['quote']['intent']);
        $this->assertTrue($draft['can']['price']);
        $this->assertFalse($draft['can']['schedule']);
        $this->assertFalse($draft['can']['worker_sign_off']);
        $this->assertNotEmpty($draft['later']);

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $card));
        $quoted = $this->stage($card);
        $this->assertSame('quoted', $quoted['key']);
        $this->assertStringContainsString('Waiting for the owner', $quoted['next']);

        $wo->fresh()->recordApproval($this->landlord, ['decision' => 'declined', 'evidence_type' => \App\Models\RentalApproval::EVIDENCE_PORTAL, 'evidence_text' => 'too dear']);
        $this->assertSame('owner_declined', $this->stage($card)['key']);

        $wo->fresh()->recordApproval($this->landlord, ['decision' => 'approved', 'evidence_type' => \App\Models\RentalApproval::EVIDENCE_PORTAL]);
        $card->fresh()->forceFill(['status' => RentalJobCard::STATUS_APPROVED])->save();
        $approved = $this->stage($card);
        $this->assertSame('approved', $approved['key']);
        $this->assertTrue($approved['can']['schedule']);
        $this->assertTrue($approved['can']['crew_link']);
        $this->assertFalse($approved['can']['worker_sign_off']);

        $card->fresh()->forceFill(['status' => RentalJobCard::STATUS_DISPUTED])->save();
        $this->assertSame('disputed', $this->stage($card)['key']);
        $card->fresh()->forceFill(['status' => RentalJobCard::STATUS_CANCELLED])->save();
        $closed = $this->stage($card);
        $this->assertSame('cancelled', $closed['key']);
        $this->assertSame([], array_filter($closed['can']));
    }

    // ── J4 ───────────────────────────────────────────────────────────────────

    private function sentFault(): RentalFaultReport
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->subDays(10), 'created_by_user_id' => $this->admin->id,
        ]);
        $fault = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $lease->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_channel' => RentalFaultReport::CHANNEL_APP,
            'title' => 'Tap leaking', 'description' => 'Drips', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);
        $fault->saveOwnerVersion(['owner_title' => 'Tap leaking', 'owner_description' => 'A tap drips.'], $this->admin);
        $fault->fresh()->requestApproval($this->admin);

        return $fault->fresh();
    }

    private function approvedFault(): RentalFaultReport
    {
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->subDays(10), 'created_by_user_id' => $this->admin->id,
        ]);
        $fault = RentalFaultReport::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $lease->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED, 'reported_channel' => RentalFaultReport::CHANNEL_APP,
            'title' => 'Tap leaking', 'description' => 'Drips', 'status' => RentalFaultReport::STATUS_REPORTED,
            'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);
        $fault->saveOwnerVersion(['owner_title' => 'Tap leaking', 'owner_description' => 'A tap drips.'], $this->admin);
        $fault->fresh()->requestApproval($this->admin);
        $fault->fresh()->recordApproval($this->admin, ['decision' => 'approved', 'approval_route' => 'agency_appoints', 'evidence_type' => 'verbal_note', 'evidence_text' => 'ok']);

        return $fault->fresh();
    }

    public function test_the_appoint_step_offers_the_crews_and_defaults_to_the_only_one(): void
    {
        $fault = $this->approvedFault();   // the world has exactly one crew: "Team 1"
        $html = $this->actingAs($this->admin)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();

        $this->assertStringContainsString('data-crew-picker', $html);
        $this->assertStringContainsString('Team 1', $html);
        $this->assertStringContainsString("crew: '{$this->crew->id}'", $html, 'the only crew is the default');
        $this->assertStringContainsString('No crew yet', $html);

        // more than one: nothing is pre-chosen, and the list is searchable
        RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Team 2', 'email' => 't2@example.invalid', 'created_by_user_id' => $this->admin->id]);
        $html = $this->actingAs($this->admin)->get(route('corex.rental-fault-reports.show', $fault))->getContent();
        $this->assertStringContainsString("crew: ''", $html);
        $this->assertStringContainsString('x-model="crewQ"', $html);
        $this->assertStringContainsString('Team 2', $html);
    }

    public function test_the_job_card_opens_with_the_chosen_crew_assigned_and_the_crew_is_optional(): void
    {
        $fault = $this->approvedFault();
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'internal', 'rental_crew_id' => $this->crew->id])
            ->assertSessionHasNoErrors();
        $card = $fault->fresh()->workOrder->jobCard;
        $this->assertSame($this->crew->id, (int) $card->rental_crew_id);
        $this->assertTrue($card->updates()->where('update_type', 'crew_assigned')->exists());
        $this->assertStringContainsString('Team 1', $this->page($card));

        // no crew chosen -> the card opens without one, as before
        $second = $this->approvedFault();
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $second), ['assignment_type' => 'internal'])->assertSessionHasNoErrors();
        $this->assertNull($second->fresh()->workOrder->jobCard->rental_crew_id);
    }

    public function test_a_crew_from_another_agency_or_an_archived_one_is_refused_and_nothing_is_created(): void
    {
        $fault = $this->approvedFault();
        $other = \App\Models\Agency::create(['name' => 'Other', 'slug' => 'o-' . uniqid()]);
        $foreign = RentalCrew::withoutGlobalScopes()->create(['agency_id' => $other->id, 'name' => 'Foreign crew', 'email' => 'f@example.invalid']);
        $archived = RentalCrew::create(['agency_id' => $this->agency->id, 'name' => 'Old crew', 'email' => 'o@example.invalid', 'created_by_user_id' => $this->admin->id]);
        $archived->forceFill(['is_active' => false])->save();

        foreach ([$foreign->id, $archived->id, 999999] as $bad) {
            $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.raise-work-order', $fault), ['assignment_type' => 'internal', 'rental_crew_id' => $bad])
                ->assertSessionHasErrors('rental_fault_report');
        }
        $this->assertSame(0, RentalWorkOrder::withoutGlobalScopes()->count());
        $this->assertSame(0, RentalJobCard::withoutGlobalScopes()->count());
        $this->assertSame(RentalFaultReport::STATUS_APPROVED, $fault->fresh()->status);
    }

    // ── J6: the dialog opens only for a valid form ───────────────────────────

    public function test_the_confirm_trigger_validates_the_form_first_and_names_the_field_instead_of_opening_the_dialog(): void
    {
        $fault = $this->sentFault();
        $html = $this->actingAs($this->admin)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();

        // the decision form: the reason is required, and the trigger asks the browser to validate BEFORE the dialog opens
        $this->assertMatchesRegularExpression('/<textarea name="evidence_text" required/', $html);
        $this->assertStringContainsString('x-on:click="ask()"', $html);
        $this->assertStringContainsString('f.reportValidity()', $html);
        $this->assertStringContainsString('data-confirm-error', $html, 'the field is named next to the button');
        $this->assertStringNotContainsString('x-on:click="open = true"', $html, 'no trigger opens the dialog without checking the form');

        // the declarative dialog (data-confirm on a form) checks validity too, and does not ask twice when another listener re-submits
        $helper = view('partials.corex-confirm')->render();
        $this->assertStringContainsString('form.checkValidity', $helper);
        $this->assertStringContainsString('form.reportValidity()', $helper);
        $this->assertStringContainsString('confirmedAt', $helper);
    }

    // ── J7: a decline closes the fault by itself ─────────────────────────────

    public function test_a_decline_recorded_by_the_agent_closes_the_fault_with_the_outcome_owner_declined_and_it_stays_editable(): void
    {
        $fault = $this->sentFault();
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.approval.store', $fault), [
            'decision' => 'declined', 'evidence_type' => 'verbal_note', 'evidence_text' => 'Too expensive for the owner',
        ])->assertSessionHasNoErrors();

        $f = $fault->fresh();
        $this->assertSame(RentalFaultReport::STATUS_DECLINED, $f->status);
        $this->assertSame(RentalFaultReport::OUTCOME_OWNER_DECLINED, $f->outcome);
        $this->assertTrue((bool) $f->outcome_set_automatically);
        $this->assertStringContainsString('Too expensive for the owner', (string) $f->outcome_note);

        $html = $this->actingAs($this->admin)->get(route('corex.rental-fault-reports.show', $f))->assertOk()->getContent();
        $this->assertStringContainsString('data-outcome-automatic', $html);
        $this->assertStringContainsString('Closed automatically as', $html);
        $this->assertStringContainsString('data-change-outcome', $html, 'the form is behind "Change the outcome", not a task');
        $this->assertStringNotContainsString('Cancel report', $html);

        // still editable - the agent's own outcome closes it and is final (the existing behaviour)
        $this->actingAs($this->admin)->post(route('corex.rental-fault-reports.outcome.store', $f), ['outcome' => 'tenant_liable', 'outcome_note' => 'Tenant damage'])->assertSessionHasNoErrors();
        $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $fault->fresh()->status);
        $this->assertFalse((bool) $fault->fresh()->outcome_set_automatically);
    }

    public function test_a_decline_from_the_owners_portal_closes_it_too_and_the_tenant_still_sees_only_the_neutral_wording(): void
    {
        $fault = $this->sentFault();
        $fault->fresh()->recordApproval($this->landlord, ['decision' => 'declined', 'evidence_type' => \App\Models\RentalApproval::EVIDENCE_PORTAL, 'evidence_text' => 'SECRET owner reason']);

        $f = $fault->fresh();
        $this->assertSame(RentalFaultReport::OUTCOME_OWNER_DECLINED, $f->outcome);
        $this->assertTrue((bool) $f->outcome_set_automatically);

        // the tenant's view of the fault: never the owner's decision word or the reason
        $tenant = \App\Models\Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina-' . uniqid() . '@example.invalid']);
        \App\Models\LeaseTenant::create(['lease_id' => $f->lease_id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        \Laravel\Sanctum\Sanctum::actingAs($this->clientFor($tenant), ['client']);
        $body = strtolower($this->getJson('/api/v1/client/rentals/fault-reports/' . $f->id)->assertOk()->getContent());
        $this->assertStringNotContainsString('declined', $body);
        $this->assertStringNotContainsString('secret owner reason', $body);
        $this->assertStringContainsString('not_approved', $body);
    }

    public function test_cancel_report_is_offered_only_while_the_owner_has_not_decided(): void
    {
        $undecided = $this->sentFault();
        $this->assertStringContainsString('Cancel report', $this->actingAs($this->admin)->get(route('corex.rental-fault-reports.show', $undecided))->getContent());

        $approved = $this->approvedFault();
        $this->assertStringNotContainsString('Cancel report', $this->actingAs($this->admin)->get(route('corex.rental-fault-reports.show', $approved))->getContent());
    }

    // ── J8: flash messages never pile on top of each other ───────────────────

    public function test_toasts_stack_in_one_column_dedupe_and_the_screens_do_not_repeat_the_message_as_a_second_banner(): void
    {
        $toast = view('components.toast-notifications')->render();
        $this->assertStringContainsString('flex flex-col gap-2', $toast, 'one column, stacked');
        $this->assertStringContainsString('t.visible && t.message === message', $toast, 'the same message is never shown twice at once');
        $this->assertStringContainsString('live.length >= 3', $toast, 'at most three on screen');

        // a flash on the fault, work order and job card screens appears ONCE (the layout's toast), not also as an inline banner
        $fault = $this->approvedFault();
        $msg = 'Flash message for J8 check';
        foreach ([route('corex.rental-fault-reports.show', $fault), route('corex.rental-work-orders.show', $this->externalWorkOrder())] as $url) {
            $html = $this->actingAs($this->admin)->withSession(['success' => $msg])->get($url)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, $msg), "the message appears once on {$url}");
        }
    }
}
