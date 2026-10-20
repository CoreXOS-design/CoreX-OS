<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionScan;
use App\Models\RentalInspectionScanMark;
use App\Models\User;
use App\Services\AI\AnthropicGateway;
use App\Services\Rentals\RentalInspectionFormPdfService;
use App\Services\Rentals\RentalInspectionNoteReadService;
use App\Services\Rentals\RentalInspectionScanReaderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use Tests\TestCase;

/**
 * AT-472 / rental-inspection-form.md §13.14 - Johan, 20 Oct 2026. The scan review screen carries each row's note and photos, saved as they are
 * captured through the capture screen's own endpoints; the AI-read handwriting pre-fills the note box; Apply sends the confirmed note and
 * enforces "Damaged needs a note"; a signed or completed inspection makes the screen read-only.
 *
 * The AI is never called here: the read step runs against a canned transcriber, and the gateway test uses Http::fake.
 */
final class RentalInspectionScanReviewNotesPhotosTest extends TestCase
{
    use RefreshDatabase;

    private const DPI = 220;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private RentalInspection $inspection;
    private RentalInspectionScan $scan;
    /** @var \Illuminate\Support\Collection<int, RentalInspectionItem> */
    private $items;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        Storage::fake('local');

        $this->agency = Agency::create(['name' => 'Review Notes Agency', 'slug' => 'rn-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Review Notes Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now(), 'created_by_user_id' => $this->agent->id,
        ]);
        $room = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'type' => 'Bedroom', 'label' => 'Bedroom 1',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        $this->items = collect();
        foreach ([1, 2, 3] as $i) {
            $this->items->push(RentalInspectionItem::create([
                'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $room->id,
                'kind' => RentalInspectionItem::KIND_SPACE, 'label' => "Item {$i}", 'space_type' => 'Bedroom',
                'source' => 'manual', 'sort_order' => $i, 'created_by_user_id' => $this->agent->id,
            ]));
        }
        $this->inspection = RentalInspection::start($this->property, RentalInspection::TYPE_IN, $this->agent);
        $this->scan = $this->scanOn($this->inspection);
    }

    private function scanOn(RentalInspection $inspection): RentalInspectionScan
    {
        return RentalInspectionScan::create([
            'agency_id' => $inspection->agency_id, 'branch_id' => $this->branch->id, 'rental_inspection_id' => $inspection->id,
            'original_filename' => 'scan.pdf', 'storage_path' => 'x/scan.pdf', 'mime_type' => 'application/pdf',
            'status' => RentalInspectionScan::STATUS_NEEDS_REVIEW, 'uploaded_by_user_id' => $this->agent->id,
        ]);
    }

    private function mark(RentalInspectionItem $item, array $attrs = []): RentalInspectionScanMark
    {
        return RentalInspectionScanMark::create($attrs + [
            'agency_id' => $this->agency->id, 'rental_inspection_scan_id' => $this->scan->id, 'rental_inspection_item_id' => $item->id,
            'page_number' => 1, 'detected_condition_key' => 'good', 'detected_confidence' => 1.0, 'ambiguous' => false,
        ]);
    }

    private function review(): string
    {
        return $this->get(route('corex.rental-inspections.scans.review', [$this->inspection, $this->scan]))->assertOk()->getContent();
    }

    private function apply(array $conditions, array $notes = [], ?string $key = null)
    {
        return $this->post(route('corex.rental-inspections.scans.apply', [$this->inspection, $this->scan]), array_filter([
            'condition_keys' => $conditions, 'notes' => $notes, 'apply_key' => $key,
        ], fn ($v) => $v !== null && $v !== []));
    }

    private function observationsFor(RentalInspectionItem $item)
    {
        return RentalInspectionObservation::where('rental_inspection_id', $this->inspection->id)->where('rental_inspection_item_id', $item->id)->orderBy('id')->get();
    }

    // ── The review screen ───────────────────────────────────────────────────────────────────────

    public function test_each_row_has_a_note_box_prefilled_with_the_detected_note_and_its_confidence(): void
    {
        $this->mark($this->items[0], ['detected_condition_key' => 'damaged', 'detected_note' => 'Cracked tiles', 'detected_note_confidence' => 0.87]);

        $html = $this->review();

        $this->assertMatchesRegularExpression('/<textarea[^>]*name="notes\[\d+\]"[^>]*>Cracked tiles<\/textarea>/', $html);
        $this->assertStringContainsString('Read from scan (87%)', $html);
        $this->assertStringContainsString('data-scan-add-photo', $html, 'the photo strip has an add control');
        $this->assertStringContainsString('<option value="damaged" selected>Damaged</option>', $html, 'the reader\'s clear tick is pre-selected');
        $this->assertStringContainsString('js/corex-scan-review.js', $html);
        $this->assertStringContainsString('js/corex-photo-batch-uploader.js', $html, 'the existing uploader is reused');
    }

    public function test_the_note_and_photo_controls_stay_closed_until_a_condition_is_picked(): void
    {
        $this->mark($this->items[0], ['detected_condition_key' => null, 'ambiguous' => true, 'detected_note' => 'Cracked tiles', 'detected_note_confidence' => 0.9]);
        $this->mark($this->items[1], ['detected_condition_key' => 'good']);

        $html = $this->review();

        $this->assertSame(1, preg_match_all('/data-scan-controls\s+style="display: none;"/', $html), 'only the row with no condition starts closed');
        $this->assertSame(2, substr_count($html, 'data-scan-controls'));
        // still posted with the form: the ambiguous row's detected note rides along once a condition is picked
        $this->assertStringContainsString('Cracked tiles</textarea>', $html);
    }

    public function test_a_row_flagged_as_two_boxes_ticked_has_a_select_to_choose_any_condition(): void
    {
        $this->mark($this->items[0], ['detected_condition_key' => null, 'ambiguous' => true]);

        $html = $this->review();

        $this->assertStringContainsString('data-qa="row-state-unsure"', $html);
        foreach (['good', 'fair', 'damaged', 'not_working', 'missing', 'other', 'n_a'] as $key) {
            $this->assertStringContainsString('<option value="' . $key . '"', $html);
        }
    }

    public function test_the_row_shows_what_is_already_recorded_note_and_photos(): void
    {
        $observation = RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $this->inspection->id, 'rental_inspection_item_id' => $this->items[0]->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => 'damaged', 'notes' => 'Door handle loose', 'source' => 'in_inspection',
        ]);
        RentalInspectionPhoto::create([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $this->inspection->id, 'rental_inspection_observation_id' => $observation->id,
            'storage_path' => '/storage/legacy-photo.jpg', 'uploaded_by_user_id' => $this->agent->id,
        ]);
        $this->mark($this->items[0], ['detected_condition_key' => 'fair']);

        $html = $this->review();

        $this->assertStringContainsString('on screen: Damaged', $html);
        $this->assertStringContainsString('Door handle loose', $html);
        $this->assertStringContainsString('src="/storage/legacy-photo.jpg"', $html);
        $this->assertMatchesRegularExpression('/<textarea[^>]*>Door handle loose<\/textarea>/', $html, 'with no AI-read note, the box starts on the recorded note');
    }

    public function test_a_completed_inspection_makes_the_whole_screen_read_only(): void
    {
        $this->mark($this->items[0], ['detected_note' => 'Cracked tiles', 'detected_note_confidence' => 0.9]);
        $this->inspection->forceFill(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()])->save();

        $html = $this->review();

        $this->assertStringContainsString('data-qa="scan-read-only"', $html);
        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringNotContainsString('name="notes[', $html);
        $this->assertStringNotContainsString('data-scan-add-photo', $html);
        $this->assertStringNotContainsString('data-qa="apply-proposed"', $html);
        $this->assertStringNotContainsString('corex-scan-review.js', $html);
    }

    // ── Apply ───────────────────────────────────────────────────────────────────────────────────

    public function test_apply_saves_the_confirmed_note_including_an_ai_prefilled_one_the_agent_never_touched(): void
    {
        $mark = $this->mark($this->items[0], ['detected_condition_key' => 'damaged', 'detected_note' => 'Cracked tiles', 'detected_note_confidence' => 0.87]);

        $this->apply([$mark->id => 'damaged'], [$mark->id => 'Cracked tiles'])->assertSessionHasNoErrors();

        $obs = $this->observationsFor($this->items[0]);
        $this->assertCount(1, $obs);
        $this->assertSame('damaged', $obs[0]->condition);
        $this->assertSame('Cracked tiles', $obs[0]->notes);
        $this->assertSame($obs[0]->id, $mark->fresh()->applied_observation_id);
    }

    public function test_apply_refuses_a_condition_that_needs_a_note_when_there_is_none_exactly_like_the_capture_endpoint(): void
    {
        $mark = $this->mark($this->items[0], ['detected_condition_key' => 'damaged']);
        $good = $this->mark($this->items[1], ['detected_condition_key' => 'good']);

        $this->apply([$mark->id => 'damaged', $good->id => 'good'], [$mark->id => '   '])->assertSessionHasErrors('scan');

        $this->assertCount(0, $this->observationsFor($this->items[0]), 'Damaged without a note records nothing');
        $this->assertNull($mark->fresh()->applied_observation_id);
        $this->assertCount(1, $this->observationsFor($this->items[1]), 'the rest of the page still applies');
        $this->assertStringContainsString('a note is required for Damaged', session('errors')->first('scan'));

        // the capture endpoint says the same thing about the same input
        $this->postJson(route('corex.rental-inspections.observations.store', $this->inspection), [
            'rental_inspection_item_id' => $this->items[0]->id, 'condition' => 'damaged', 'notes' => null, 'source' => 'in_inspection',
        ])->assertStatus(422);
        $this->apply([$mark->id => 'damaged'], [$mark->id => 'Cracked tiles'])->assertSessionHasNoErrors();
        $this->assertCount(1, $this->observationsFor($this->items[0]));
    }

    public function test_the_same_apply_press_sent_twice_records_once(): void
    {
        $mark = $this->mark($this->items[0], ['detected_condition_key' => 'fair']);
        $key = (string) \Illuminate\Support\Str::uuid();

        $this->apply([$mark->id => 'fair'], [], $key)->assertSessionHasNoErrors();
        // a retry of the very same press arriving after the mark was left unlinked (a lost response, a second tab)
        $mark->fresh()->forceFill(['applied_observation_id' => null])->save();
        $this->apply([$mark->id => 'fair'], [], $key)->assertSessionHasNoErrors();

        $this->assertCount(1, $this->observationsFor($this->items[0]));
        $this->assertNotNull($mark->fresh()->applied_observation_id);
    }

    public function test_a_note_already_saved_through_the_capture_endpoint_is_linked_not_recorded_again(): void
    {
        $mark = $this->mark($this->items[0], ['detected_condition_key' => 'damaged']);
        // what the review screen's note box does 800 ms after typing
        $this->postJson(route('corex.rental-inspections.observations.store', $this->inspection), [
            'rental_inspection_item_id' => $this->items[0]->id, 'condition' => 'damaged', 'notes' => 'Cracked tiles', 'source' => 'in_inspection',
            'client_idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
        ])->assertOk();

        $this->apply([$mark->id => 'damaged'], [$mark->id => 'Cracked tiles'])->assertSessionHasNoErrors();

        $obs = $this->observationsFor($this->items[0]);
        $this->assertCount(1, $obs, 'saving as it is captured is not tied to Apply, and Apply adds nothing on top');
        $this->assertSame($obs[0]->id, $mark->fresh()->applied_observation_id);
    }

    public function test_an_applied_row_can_be_corrected_but_the_same_thing_again_changes_nothing(): void
    {
        $mark = $this->mark($this->items[0], ['detected_condition_key' => 'good']);
        $this->apply([$mark->id => 'good'])->assertSessionHasNoErrors();

        $this->apply([$mark->id => 'fair'])->assertSessionHasNoErrors();
        $obs = $this->observationsFor($this->items[0]);
        $this->assertSame(['good', 'fair'], $obs->pluck('condition')->all(), 'append-only: the correction is a new observation');
        $this->assertSame('fair', $mark->fresh()->confirmed_condition_key);
        $this->assertSame($obs[1]->id, $mark->fresh()->applied_observation_id);

        $this->apply([$mark->id => 'fair'])->assertSessionHasNoErrors();
        $this->assertCount(2, $this->observationsFor($this->items[0]));
    }

    public function test_apply_by_an_agent_who_may_only_see_their_own_inspections_is_refused_on_someone_elses(): void
    {
        $mark = $this->mark($this->items[0]);
        \App\Models\Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        foreach (['rental_inspections.view', 'rental_inspections.create'] as $key) {
            \App\Models\RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'own']);
        }
        \App\Services\PermissionService::clearCache();
        $stranger = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        $this->actingAs($stranger)
            ->get(route('corex.rental-inspections.scans.review', [$this->inspection, $this->scan]))->assertNotFound();
        $this->actingAs($stranger)
            ->post(route('corex.rental-inspections.scans.apply', [$this->inspection, $this->scan]), ['condition_keys' => [$mark->id => 'good']])
            ->assertNotFound();
        $this->assertCount(0, $this->observationsFor($this->items[0]));
    }

    // ── §13.15: three tick states, the count line, the always-visible note, the unreadable note ──────

    public function test_each_row_is_read_to_check_or_not_picked_up_and_the_top_line_counts_them(): void
    {
        $this->mark($this->items[0], ['detected_condition_key' => 'good', 'detected_confidence' => 0.9, 'tick_boxes' => 1]);                  // read with confidence
        $this->mark($this->items[1], ['detected_condition_key' => null, 'ambiguous' => true, 'detected_confidence' => 0.86, 'tick_boxes' => 2]); // two boxes
        $this->mark($this->items[2], ['detected_condition_key' => null, 'ambiguous' => true, 'detected_confidence' => 0.1, 'tick_boxes' => 0]);  // nothing picked up

        $html = $this->review();

        $this->assertStringContainsString('data-qa="row-state-read"', $html);
        $this->assertStringContainsString('Check: two boxes ticked', $html);
        $this->assertStringContainsString('data-qa="row-state-missed"', $html);
        $this->assertStringContainsString('Not picked up', $html);
        $this->assertStringContainsString('1 read, 1 to check, 1 not picked up', $html);
        $this->assertStringNotContainsString('Only conditions wait', $html, 'no other helper text');
        $this->assertSame(2, preg_match_all('/data-scan-controls\s+style="display: none;"/', $html), 'to check and not picked up start blank, controls closed');
    }

    public function test_a_faint_tick_is_to_check_and_the_agencys_own_line_decides_it(): void
    {
        $mark = $this->mark($this->items[0], ['detected_condition_key' => 'fair', 'detected_confidence' => 0.6, 'tick_boxes' => 1]);

        $this->assertSame('read', $mark->tickState(0.5));
        $this->assertSame('unsure', $mark->tickState(0.7));
        $this->assertStringContainsString('1 read, 0 to check, 0 not picked up', $this->review());

        \App\Models\RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['omr_unsure_below' => 0.7]);
        $html = $this->review();
        $this->assertStringContainsString('0 read, 1 to check, 0 not picked up', $html);
        $this->assertStringContainsString('data-qa="row-low-confidence"', $html);
    }

    public function test_a_mark_read_before_the_box_count_existed_is_still_told_apart_by_its_confidence(): void
    {
        $twoBoxes = $this->mark($this->items[0], ['detected_condition_key' => null, 'ambiguous' => true, 'detected_confidence' => 0.86]);
        $nothing = $this->mark($this->items[1], ['detected_condition_key' => null, 'ambiguous' => true, 'detected_confidence' => 0.2]);

        $this->assertSame('unsure', $twoBoxes->tickState(0.5));
        $this->assertSame('missed', $nothing->tickState(0.5));
    }

    public function test_a_detected_note_is_always_visible_even_on_a_row_with_no_condition_and_marked_read_from_the_scan(): void
    {
        $this->mark($this->items[0], ['detected_condition_key' => null, 'ambiguous' => true, 'tick_boxes' => 2, 'detected_note' => 'Cracked Tiles!', 'detected_note_confidence' => 0.95]);

        $html = $this->review();

        $this->assertMatchesRegularExpression('/<textarea[^>]*>Cracked Tiles!<\/textarea>/', $html);
        $this->assertSame(0, preg_match('/data-scan-controls[^>]*>.{0,400}?data-scan-note/s', $html), 'the note box is not inside the closed controls');
        $this->assertSame(1, preg_match('/data-scan-note-area.*?Read from scan \(95%\).*?<textarea/s', $html), 'the badge sits on the note box');
        $this->assertStringContainsString('ds-badge-info', $html, 'an existing CoreX badge, no new style');
    }

    public function test_a_note_the_agent_already_has_recorded_is_shown_and_never_marked_read_from_the_scan(): void
    {
        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $this->inspection->id, 'rental_inspection_item_id' => $this->items[0]->id,
            'observed_by_user_id' => $this->agent->id, 'condition' => 'damaged', 'notes' => 'Cracked tiles - typed by the agent', 'source' => 'in_inspection',
        ]);
        $this->mark($this->items[0], ['detected_condition_key' => 'damaged', 'tick_boxes' => 1, 'detected_note' => 'Cracked Tiles!', 'detected_note_confidence' => 0.95]);

        $html = $this->review();

        $this->assertMatchesRegularExpression('/<textarea[^>]*>Cracked tiles - typed by the agent<\/textarea>/', $html);
        $this->assertStringNotContainsString('Read from scan', $html, 'once it is the agent\'s note it no longer shows as read from the scan');
    }

    public function test_a_note_area_with_ink_the_ai_could_not_read_says_so_at_the_note_box(): void
    {
        $this->mark($this->items[0], ['detected_condition_key' => 'damaged', 'tick_boxes' => 1, 'note_ink' => true, 'detected_note' => null]);                                  // empty result
        $this->mark($this->items[1], ['detected_condition_key' => 'damaged', 'tick_boxes' => 1, 'note_ink' => true, 'detected_note' => 'smudge?', 'detected_note_confidence' => 0.3]); // below the line
        $this->mark($this->items[2], ['detected_condition_key' => 'good', 'tick_boxes' => 1, 'note_ink' => false]);                                                               // no ink: nothing to say

        $html = $this->review();

        $this->assertSame(2, substr_count($html, "Couldn't read the handwritten note. Type it in."));
        $this->assertStringNotContainsString('smudge?', $html, 'a reading below the agency\'s line is not filled in');
        $this->assertStringNotContainsString('Read from scan', $html);
    }

    public function test_the_agencys_note_confidence_line_decides_what_fills_the_box(): void
    {
        $mark = $this->mark($this->items[0], ['detected_condition_key' => 'damaged', 'tick_boxes' => 1, 'note_ink' => true, 'detected_note' => 'Cracked tiles', 'detected_note_confidence' => 0.6]);

        $this->assertSame('Cracked tiles', $mark->usableDetectedNote(0.5));
        $this->assertNull($mark->usableDetectedNote(0.7));
        $this->assertTrue($mark->noteUnreadable(0.7));
        $this->assertFalse($mark->noteUnreadable(0.5));
    }

    public function test_the_two_new_thresholds_are_agency_settings_with_defaults_and_reach_the_wizard(): void
    {
        $this->assertSame(0.5, \App\Models\RentalInspectionSetting::omrUnsureBelowFor($this->agency->id));
        $this->assertSame(0.5, \App\Models\RentalInspectionSetting::noteReadMinConfidenceFor($this->agency->id));

        $this->post(route('corex.settings.rental-inspections.update'), ['fault_report_window_days' => 14, 'out_inspection_signing_window_days' => 7, 'omr_unsure_below' => '0.65', 'note_read_min_confidence' => '0.4'])->assertSessionHasNoErrors();

        $this->assertSame(0.65, \App\Models\RentalInspectionSetting::omrUnsureBelowFor($this->agency->id));
        $this->assertSame(0.4, \App\Models\RentalInspectionSetting::noteReadMinConfidenceFor($this->agency->id));

        $controls = collect(config('agency-onboarding-copy.leases.controls'))->pluck('key')->all();
        $this->assertContains('omr_unsure_below', $controls);
        $this->assertContains('note_read_min_confidence', $controls);
    }

    // ── §13.16: one sticky header with the actions, the live status and the blockers ───────────────

    public function test_the_actions_and_the_status_sit_in_one_sticky_header_and_nothing_is_at_the_bottom(): void
    {
        $this->mark($this->items[0], ['detected_condition_key' => 'good', 'tick_boxes' => 1]);

        $html = $this->review();

        $header = $this->between($html, 'id="scan-header"', '<form');
        $this->assertStringContainsString('position: sticky; top: 0', $header);
        $this->assertStringContainsString('data-qa="apply-proposed"', $header, 'Apply is in the header');
        $this->assertStringContainsString('data-qa="open-original"', $header);
        $this->assertStringContainsString('data-qa="scan-summary"', $header, 'the count line is in the header');
        $this->assertSame(1, substr_count($html, 'data-qa="apply-proposed"'));
        $formStart = (int) strpos($html, 'id="scan-review"');
        $this->assertStringNotContainsString('apply-proposed', substr($html, (int) strpos($html, '</form>', $formStart)), 'nothing after the review form');
    }

    public function test_apply_is_disabled_with_the_reason_shown_while_a_blocker_remains(): void
    {
        $this->mark($this->items[0], ['detected_condition_key' => 'good', 'tick_boxes' => 1]);                                                                        // fine
        $this->mark($this->items[1], ['detected_condition_key' => null, 'ambiguous' => true, 'tick_boxes' => 2]);                                                      // needs a condition
        $this->mark($this->items[2], ['detected_condition_key' => 'damaged', 'detected_confidence' => 1.0, 'tick_boxes' => 1]);                                        // Damaged, no note

        $html = $this->review();
        $header = $this->between($html, 'id="scan-header"', '<form');

        $this->assertMatchesRegularExpression('/data-scan-apply\s+disabled/', $header, 'Apply is disabled');
        $this->assertStringContainsString('1 row needs a condition', $header);
        $this->assertStringContainsString('1 Damaged row needs a note', $header);
        $this->assertStringContainsString('data-scan-next="condition"', $header, 'each blocker is a link that steps to its rows');
        $this->assertStringContainsString('data-scan-next="note:damaged"', $header);
        $this->assertStringContainsString('data-notes-required="damaged,not_working,missing,other"', $html);
        foreach ($this->items as $item) {
            $this->assertStringContainsString('id="scan-row-' . RentalInspectionScanMark::where('rental_inspection_item_id', $item->id)->value('id') . '"', $html, 'every row can be scrolled to');
        }
    }

    public function test_apply_is_enabled_and_no_blocker_is_shown_when_every_row_is_set(): void
    {
        $this->mark($this->items[0], ['detected_condition_key' => 'good', 'tick_boxes' => 1]);
        $this->mark($this->items[1], ['detected_condition_key' => 'damaged', 'tick_boxes' => 1, 'detected_note' => 'Cracked tiles', 'detected_note_confidence' => 0.9]);

        $html = $this->review();
        $header = $this->between($html, 'id="scan-header"', '<form');

        $this->assertDoesNotMatchRegularExpression('/data-scan-apply\s+disabled/', $header);
        $this->assertStringNotContainsString('data-scan-next', $header, 'a Damaged row whose note was read has no blocker');
    }

    public function test_a_server_refusal_after_apply_is_a_link_to_its_row(): void
    {
        $mark = $this->mark($this->items[0], ['detected_condition_key' => 'damaged', 'tick_boxes' => 1]);

        $this->apply([$mark->id => 'damaged'], [$mark->id => ''])->assertSessionHasErrors('scan');
        // the flash is one-shot: read it from the apply response, then render the page with it
        $response = $this->apply([$mark->id => 'damaged'], [$mark->id => '']);
        $response->assertSessionHas('scan_refused', fn ($rows) => $rows[0]['mark'] === $mark->id && $rows[0]['focus'] === 'note');

        $page = $this->withSession(['scan_refused' => [['mark' => $mark->id, 'focus' => 'note', 'text' => 'Item 1: a note is required for Damaged.']]])
            ->get(route('corex.rental-inspections.scans.review', [$this->inspection, $this->scan]))->getContent();
        $header = $this->between($page, 'id="scan-header"', '<form');
        $this->assertStringContainsString('href="#scan-row-' . $mark->id . '"', $header);
        $this->assertStringContainsString('data-scan-goto="' . $mark->id . '"', $header);
        $this->assertStringContainsString('data-focus="note"', $header);
        $this->assertStringContainsString('Item 1: a note is required for Damaged.', $header);
    }

    private function between(string $html, string $from, string $to): string
    {
        $a = strpos($html, $from);
        $this->assertNotFalse($a, "'{$from}' is on the page");
        $b = strpos($html, $to, $a);

        return substr($html, $a, ($b === false ? strlen($html) : $b) - $a);
    }

    // ── The AI read of the handwriting (a canned answer - Anthropic is never called) ───────────────

    public function test_the_read_step_is_off_by_default(): void
    {
        $this->assertFalse(app(RentalInspectionNoteReadService::class)->enabled());
    }

    public function test_the_read_step_fills_only_the_rows_that_have_handwriting_with_a_canned_answer(): void
    {
        config(['services.anthropic.inspection_note_reading' => true, 'services.anthropic.enabled' => true]);
        $fake = $this->cannedTranscriber(['text' => 'Cracked tiles', 'confidence' => 0.91]);
        $this->app->instance(RentalInspectionNoteReadService::class, $fake);

        $scan = $this->scanFromGeneratedForm(inkNoteOfItemIndex: 1);

        app(RentalInspectionScanReaderService::class)->process($scan->fresh());

        $this->assertSame(RentalInspectionScan::STATUS_NEEDS_REVIEW, $scan->fresh()->status, $scan->fresh()->failure_reason ?? '');
        $this->assertCount(1, $fake->received, 'only the row with ink in its note area is sent');
        $inked = RentalInspectionScanMark::where('rental_inspection_scan_id', $scan->id)->where('rental_inspection_item_id', $this->items[1]->id)->first();
        $this->assertSame('Cracked tiles', $inked->detected_note);
        $this->assertSame(0.91, $inked->detected_note_confidence);
        $this->assertTrue($inked->note_ink, 'the row had handwriting in its note area');
        $this->assertSame(1, $inked->tick_boxes, 'one box ticked');
        $this->assertFalse(RentalInspectionScanMark::where('rental_inspection_scan_id', $scan->id)->where('rental_inspection_item_id', $this->items[0]->id)->value('note_ink'));
        $this->assertNull(RentalInspectionScanMark::where('rental_inspection_scan_id', $scan->id)->where('rental_inspection_item_id', $this->items[0]->id)->value('detected_note'));
        $this->assertCount(0, RentalInspectionObservation::where('rental_inspection_id', $this->inspection->id)->get(), 'reading never writes to the inspection');
    }

    public function test_an_empty_ai_answer_on_a_row_with_ink_becomes_a_note_to_type_in(): void
    {
        config(['services.anthropic.inspection_note_reading' => true, 'services.anthropic.enabled' => true]);
        $this->app->instance(RentalInspectionNoteReadService::class, $this->cannedTranscriber(['text' => '', 'confidence' => 0.0]));

        $scan = $this->scanFromGeneratedForm(inkNoteOfItemIndex: 1);
        app(RentalInspectionScanReaderService::class)->process($scan->fresh());

        $inked = RentalInspectionScanMark::where('rental_inspection_scan_id', $scan->id)->where('rental_inspection_item_id', $this->items[1]->id)->first();
        $this->assertTrue($inked->note_ink);
        $this->assertNull($inked->detected_note);
        $html = $this->get(route('corex.rental-inspections.scans.review', [$this->inspection, $scan]))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, "Couldn't read the handwritten note. Type it in."), 'only the row that had ink says so');
    }

    public function test_with_the_switch_off_no_note_is_read_and_nothing_is_sent(): void
    {
        $fake = $this->cannedTranscriber(['text' => 'Cracked tiles', 'confidence' => 0.91]);
        $this->app->instance(RentalInspectionNoteReadService::class, $fake);

        $scan = $this->scanFromGeneratedForm(inkNoteOfItemIndex: 1);
        app(RentalInspectionScanReaderService::class)->process($scan->fresh());

        $this->assertSame([], $fake->received);
        $this->assertNull(RentalInspectionScanMark::where('rental_inspection_scan_id', $scan->id)->whereNotNull('detected_note')->first());
        $this->assertSame(RentalInspectionScan::STATUS_NEEDS_REVIEW, $scan->fresh()->status);
    }

    public function test_an_ai_that_fails_leaves_the_scan_and_its_ticks_untouched(): void
    {
        config(['services.anthropic.inspection_note_reading' => true, 'services.anthropic.enabled' => true]);
        $failing = new class(app(AnthropicGateway::class)) extends RentalInspectionNoteReadService {
            public function transcribe(array $crops): array
            {
                throw new \RuntimeException('Anthropic is down');
            }
        };
        $this->app->instance(RentalInspectionNoteReadService::class, $failing);

        $scan = $this->scanFromGeneratedForm(inkNoteOfItemIndex: 1);
        app(RentalInspectionScanReaderService::class)->process($scan->fresh());

        $this->assertSame(RentalInspectionScan::STATUS_NEEDS_REVIEW, $scan->fresh()->status);
        $this->assertSame(3, RentalInspectionScanMark::where('rental_inspection_scan_id', $scan->id)->count());
        $this->assertNull(RentalInspectionScanMark::where('rental_inspection_scan_id', $scan->id)->whereNotNull('detected_note')->first());
    }

    public function test_the_gateway_read_asks_haiku_first_and_sonnet_only_below_the_confidence_line(): void
    {
        config(['services.anthropic.api_key' => 'test-key', 'services.anthropic.enabled' => true]);
        $reply = fn (string $text, float $confidence) => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['text' => $text, 'confidence' => $confidence])]],
            'usage' => ['input_tokens' => 400, 'output_tokens' => 20], 'model' => 'x',
        ], 200);
        Http::fake(['*' => Http::sequence()
            ->pushResponse($reply('Crack in wall', 0.95))      // crop a, Haiku: sure
            ->pushResponse($reply('Motor nt working', 0.55))   // crop b, Haiku: unsure -> Sonnet
            ->pushResponse($reply('Motor not working', 0.93)), // crop b, Sonnet
        ]);

        $png = fn () => (function () { $i = new Imagick(); $i->newImage(40, 12, new ImagickPixel('white')); $i->setImageFormat('png'); return $i->getImageBlob() . random_bytes(4); })();
        $answers = app(RentalInspectionNoteReadService::class)->transcribe([
            'a' => ['png' => $png(), 'label' => 'Lounge - Walls', 'agency_id' => $this->agency->id],
            'b' => ['png' => $png(), 'label' => 'Garage - Garage door', 'agency_id' => $this->agency->id],
        ]);

        $this->assertSame('Crack in wall', $answers['a']['text']);
        $this->assertSame('Motor not working', $answers['b']['text'], 'the Sonnet answer replaces the unsure Haiku one');
        $this->assertSame(0.93, $answers['b']['confidence']);
        Http::assertSentCount(3);
        $this->assertSame(3, \App\Models\AI\AiUsageEvent::where('source', \App\Models\AI\AiUsageEvent::SOURCE_IMAGE_ANALYSIS)->count(), 'every call is on the AI usage ledger');
    }

    // ── helpers for the read step ───────────────────────────────────────────────────────────────

    private function cannedTranscriber(array $answer): RentalInspectionNoteReadService
    {
        return new class(app(AnthropicGateway::class), $answer) extends RentalInspectionNoteReadService {
            /** @var array<int|string, array> */
            public array $received = [];

            public function __construct(AnthropicGateway $gateway, private array $canned)
            {
                parent::__construct($gateway);
            }

            public function transcribe(array $crops): array
            {
                $this->received = $crops;

                return array_map(fn () => $this->canned + ['model' => 'canned', 'cost_zar' => 0.0], $crops);
            }
        };
    }

    /** A scan of the real generated form: item 0's and 1's ticks inked, plus a block of "handwriting" in the note area of one row. */
    private function scanFromGeneratedForm(int $inkNoteOfItemIndex): RentalInspectionScan
    {
        $form = app(RentalInspectionFormPdfService::class)->generate($this->inspection, $this->agent);
        $manifest = $form->manifest_json;
        $scale = self::DPI / 72.0;

        $page = new Imagick();
        $page->setResolution(self::DPI, self::DPI);
        $page->readImage(Storage::disk('local')->path($form->pdf_storage_path) . '[0]');
        $page->setImageColorspace(Imagick::COLORSPACE_GRAY);
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('black'));

        $byItem = [];
        foreach ($manifest['boxes'] as $box) {
            if ((int) $box['page'] === 1) {
                $byItem[(int) $box['rental_inspection_item_id']][] = $box;
            }
        }
        foreach ($this->items as $i => $item) {
            $boxes = $byItem[$item->id];
            usort($boxes, fn ($a, $b) => $a['x'] <=> $b['x']);
            $tick = $boxes[0]; // "good"
            $draw->rectangle($tick['x'] * $scale, $tick['y'] * $scale, ($tick['x'] + $tick['width']) * $scale, ($tick['y'] + $tick['height']) * $scale);
            if ($i === $inkNoteOfItemIndex) {
                $last = end($boxes);
                $nx = ($last['x'] + 24) * $scale;
                $ny = ($last['y'] - 1) * $scale;
                $draw->rectangle($nx, $ny, $nx + 60 * $scale, $ny + 5 * $scale); // a thick stroke of handwriting
            }
        }
        $page->drawImage($draw);
        $page->setImageFormat('png');
        $tmp = tempnam(sys_get_temp_dir(), 'rnote') . '.png';
        $page->writeImage($tmp);
        $file = new \Illuminate\Http\UploadedFile($tmp, 'scan.png', 'image/png', null, true);

        return RentalInspectionScan::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'rental_inspection_id' => $this->inspection->id,
            'original_filename' => 'scan.png', 'storage_path' => $file->store("properties/{$this->property->id}/rental-inspection-scans", 'local'),
            'mime_type' => 'image/png', 'status' => RentalInspectionScan::STATUS_PROCESSING, 'uploaded_by_user_id' => $this->agent->id,
        ]);
    }
}
