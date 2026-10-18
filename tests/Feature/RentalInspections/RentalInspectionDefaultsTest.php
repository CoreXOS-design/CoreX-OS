<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Mail\Distribution\SignedDocumentDistributionMail;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\RentalInspection;
use App\Models\RentalInspectionAuditLog;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionSetting;
use App\Models\RentalInspectionSignature;
use App\Models\RentalInspectionSigningNotice;
use App\Models\User;
use App\Notifications\RentalInspectionSigningReminder;
use App\Services\Rentals\RentalInspectionReportPdfService;
use App\Services\Rentals\RentalInspectionSigningReminderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\RentalInspections\Concerns\BuildsSigningFixture;
use Tests\TestCase;

/**
 * .ai/specs/rental-inspections.md §52 — the decisions taken while Johan was away, one test per rule: each one at its
 * recommended default, each one switchable by the agency, each one undone by switching the setting back.
 */
final class RentalInspectionDefaultsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownFixture();
        parent::tearDown();
    }

    private function rule(string $column, bool $value): void
    {
        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], [$column => $value]);
    }

    private function pdfText(RentalInspection $i): string
    {
        $file = tempnam(sys_get_temp_dir(), 'defpdf') . '.pdf';
        file_put_contents($file, app(RentalInspectionReportPdfService::class)->generate($i->fresh())->output());
        $text = (string) shell_exec('pdftotext -layout ' . escapeshellarg($file) . ' - 2>/dev/null');
        @unlink($file);

        return $text;
    }

    // ═══ the settings themselves ══════════════════════════════════════════════════

    public function test_every_rule_reads_its_recommended_default_until_the_agency_sets_it(): void
    {
        foreach (RentalInspectionSetting::INSPECTION_RULES as $column => $rule) {
            $this->assertSame($rule[0], RentalInspectionSetting::ruleFor($this->agency->id, $column), "{$column} default");
        }
        $this->assertSame(2, RentalInspectionSetting::signingReminderLeadDaysFor($this->agency->id));

        $this->rule('hold_pdf_when_refused', false);
        $this->assertFalse(RentalInspectionSetting::ruleFor($this->agency->id, 'hold_pdf_when_refused'));
        $this->assertTrue(RentalInspectionSetting::ruleFor($this->agency->id, 'cancel_signed_requires_edit'), 'one rule never moves another');
    }

    public function test_the_settings_page_shows_every_rule_and_the_saver_never_wipes_a_rule_a_step_did_not_render(): void
    {
        $html = $this->get(route('corex.settings.rental-inspections.edit'))->assertOk()->getContent();
        foreach (RentalInspectionSetting::INSPECTION_RULES as $column => $rule) {
            $this->assertStringContainsString('name="' . $column . '"', $html);
        }
        $this->assertStringContainsString('name="signing_reminder_lead_days"', $html);

        // A wizard step that renders only the two window fields must not flip any rule back to its default.
        $this->rule('photos_required_to_sign', true);
        $this->rule('cancel_signed_requires_edit', false);
        $this->post(route('corex.settings.rental-inspections.update'), ['fault_report_window_days' => 7, 'out_inspection_signing_window_days' => 9])->assertRedirect();
        $this->assertTrue(RentalInspectionSetting::ruleFor($this->agency->id, 'photos_required_to_sign'));
        $this->assertFalse(RentalInspectionSetting::ruleFor($this->agency->id, 'cancel_signed_requires_edit'));
        $this->assertSame(9, RentalInspectionSetting::signingWindowDaysFor($this->agency->id));

        // …and a rule that IS posted saves, including an explicit off.
        $this->post(route('corex.settings.rental-inspections.update'), [
            'fault_report_window_days' => 7, 'out_inspection_signing_window_days' => 9, 'photos_required_to_sign' => '0', 'cancel_signed_requires_edit' => '1', 'signing_reminder_lead_days' => 4,
        ])->assertRedirect();
        $this->assertFalse(RentalInspectionSetting::ruleFor($this->agency->id, 'photos_required_to_sign'));
        $this->assertTrue(RentalInspectionSetting::ruleFor($this->agency->id, 'cancel_signed_requires_edit'));
        $this->assertSame(4, RentalInspectionSetting::signingReminderLeadDaysFor($this->agency->id));
    }

    public function test_every_rule_is_in_the_setup_wizard_with_an_explanation_and_a_consequence(): void
    {
        $controls = config('agency-onboarding-copy');
        $keys = [];
        array_walk_recursive($controls, function ($v, $k) use (&$keys) {
            if ($k === 'key') {
                $keys[] = $v;
            }
        });
        foreach (array_merge(array_keys(RentalInspectionSetting::INSPECTION_RULES), ['signing_reminder_lead_days']) as $column) {
            $this->assertContains($column, $keys, "{$column} must be a wizard control (CLAUDE.md #10a)");
        }
    }

    // ═══ Q3 · the signing window: days left, and a reminder ═══════════════════════

    public function test_the_signing_window_says_how_many_days_are_left_and_who_still_has_to_sign(): void
    {
        $i = $this->ready();
        $status = $i->fresh()->signingWindowStatus();

        $this->assertSame(7, $status['days_left'], 'the agency\'s own window length (default 7)');
        $this->assertFalse($status['closed']);
        $this->assertSame(3, $status['outstanding'], 'two tenants and the landlord');
        $this->assertStringContainsString('7 days left', $status['label']);
        $this->get(route('corex.rental-inspections.show', $i))->assertOk()->assertSee('7 days left');

        $this->rule('signing_window_reminders_enabled', true);
        RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['out_inspection_signing_window_days' => 2]);
        $this->assertSame(2, $this->readyOther()->signingWindowStatus()['days_left'], 'window length is the agency setting');

        $this->assertNull($this->recording()->signingWindowStatus(), 'nothing to count down before Ready to sign');
    }

    private function readyOther(): RentalInspection
    {
        $i = $this->recording(RentalInspection::TYPE_AD_HOC);

        return $this->readyToSign($i)->fresh();
    }

    public function test_the_agent_is_reminded_before_the_window_closes_and_again_after_once_each(): void
    {
        Notification::fake();
        $i = $this->ready();
        // The reminder goes through the notification gateway, whose per-user cooldown (default 6 real hours) would swallow
        // every simulated "next day" in this test - switch it off so the milestone logic is what is under test.
        \App\Models\CommandCenter\UserDashboardSetting::updateOrCreate(
            ['user_id' => $this->inspector->id],
            array_merge(\App\Models\CommandCenter\UserDashboardSetting::defaults(), ['min_minutes_between_same' => 0])
        );
        $closes = $i->fresh()->signing_deadline_at->copy()->startOfDay();
        $service = app(RentalInspectionSigningReminderService::class);

        $this->assertSame(0, $service->run($closes->copy()->subDays(5))['sent'], 'before the 2-day lead');
        $this->assertSame(1, $service->run($closes->copy()->subDays(2))['sent'], 'at the lead');
        $this->assertSame(0, $service->run($closes->copy()->subDays(1))['sent'], 'the lead reminder is not repeated');
        $this->assertSame(1, $service->run($closes->copy()->addDay())['sent'], 'the day after it closed');
        $this->assertSame(0, $service->run($closes->copy()->addDays(2))['sent'], 'once');

        Notification::assertSentToTimes($this->inspector, RentalInspectionSigningReminder::class, 2);
        Notification::assertNotSentTo($this->admin, RentalInspectionSigningReminder::class);
        $this->assertSame(2, RentalInspectionSigningNotice::withoutGlobalScopes()->where('rental_inspection_id', $i->id)->where('status', 'sent')->count());
        $this->assertSame(2, RentalInspectionAuditLog::where('rental_inspection_id', $i->id)->where('event', RentalInspectionAuditLog::EVENT_SIGNING_REMINDER)->count(), 'each reminder is on the inspection\'s history');
        $this->assertSame('awaiting_signature', $i->fresh()->status, 'nothing happens to the inspection');
    }

    public function test_no_reminder_when_everyone_has_an_outcome_when_the_agency_turned_it_off_or_when_the_window_closed_long_ago(): void
    {
        Notification::fake();
        $service = app(RentalInspectionSigningReminderService::class);

        // Everyone has an outcome → nothing to chase (only the agent's own signature is missing, which never triggers it).
        $signed = $this->ready();
        $this->signByLink($signed, 'tenant', $this->tenant, 'Naledi Dlamini');
        $this->signByLink($signed, 'tenant', $this->tenant2, 'Sipho Khumalo');
        $this->postJson(route('corex.rental-inspections.signatures.store', $signed), ['party_role' => 'landlord', 'disposition' => 'signed', 'party_contact_id' => $this->landlord->id, 'signature_image' => self::PNG])->assertStatus(201);
        $closed = $signed->fresh()->signing_deadline_at->copy()->addDay();
        $this->assertSame(0, $service->run($closed)['sent']);

        // Agency turned the reminders off.
        $this->postJson(route('corex.rental-inspections.reopen', $signed), ['reason' => 'changed the keys count'])->assertOk();
        $this->readyToSign($signed->fresh());
        $this->rule('signing_window_reminders_enabled', false);
        $this->assertSame(0, $service->run($signed->fresh()->signing_deadline_at->copy()->addDay())['sent']);

        // Go-live safety: a report that closed three weeks ago is recorded as skipped, not mailed.
        $this->rule('signing_window_reminders_enabled', true);
        $tally = $service->run($signed->fresh()->signing_deadline_at->copy()->addDays(21));
        $this->assertSame(0, $tally['sent']);
        $this->assertGreaterThanOrEqual(1, $tally['skipped']);
        Notification::assertNothingSent();
    }

    public function test_the_reminder_command_honours_agency_and_dry_run_and_writes_nothing_when_dry(): void
    {
        Notification::fake();
        $i = $this->ready();
        Carbon::setTestNow($i->fresh()->signing_deadline_at->copy()->addDay());

        Artisan::call('rentals:send-signing-window-reminders', ['--agency' => $this->agency->id + 999, '--dry-run' => true]);
        $this->assertStringContainsString('sent: 0', Artisan::output());

        Artisan::call('rentals:send-signing-window-reminders', ['--agency' => $this->agency->id, '--dry-run' => true]);
        $this->assertStringContainsString('sent: 1', Artisan::output());
        Notification::assertNothingSent();
        $this->assertSame(0, RentalInspectionSigningNotice::withoutGlobalScopes()->count());

        Artisan::call('rentals:send-signing-window-reminders', ['--agency' => $this->agency->id]);
        Notification::assertSentTo($this->inspector, RentalInspectionSigningReminder::class);
    }

    // ═══ Q7 · a signed report is not cancelled directly ═══════════════════════════

    public function test_a_signed_report_must_go_through_edit_report_before_it_can_be_cancelled(): void
    {
        $i = $this->ready();
        $this->signByLink($i, 'tenant', $this->tenant, 'Naledi Dlamini');

        $this->post(route('corex.rental-inspections.cancel', $i), ['cancel_reason' => 'wrong tenancy'])->assertSessionHasErrors('rental_inspection');
        $this->assertSame('awaiting_signature', $i->fresh()->status);
        $this->assertCount(1, $this->liveSignatures($i));

        // Edit report (clears the signatures, records why) → now it can be cancelled.
        $this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'booked on the wrong tenancy'])->assertOk();
        $this->post(route('corex.rental-inspections.cancel', $i), ['cancel_reason' => 'wrong tenancy'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $i->fresh()->status);

        // The rule is the agency's: off, a signed report cancels directly (as before).
        $this->rule('cancel_signed_requires_edit', false);
        $j = $this->ready(RentalInspection::TYPE_AD_HOC);
        $this->signByLink($j, 'tenant', $this->tenant, 'Naledi Dlamini');
        $this->post(route('corex.rental-inspections.cancel', $j), ['cancel_reason' => 'x'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $j->fresh()->status);
    }

    // ═══ Q8 · photos on everything: a count always, a hard stop if the agency wants ═

    public function test_items_without_a_photo_are_counted_and_only_stop_ready_to_sign_when_the_agency_says_so(): void
    {
        $i = $this->recording();
        $this->assertSame([$this->item->id], $i->itemsWithoutPhoto()->pluck('id')->all());
        $tail = RentalInspection::tabPayloadFor($this->property)['chain_tail'];
        $this->assertCount(1, $tail->items_without_photo);
        $this->assertSame('Lounge Ceiling', $tail->items_without_photo[0]['label']);

        // Default: a warning only.
        $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $i))->assertOk();
        $i->forceFill(['status' => 'draft', 'signing_deadline_at' => null])->save();

        $this->rule('photos_required_to_sign', true);
        $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $i))->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, '1 item has no photo'));

        // A photo on the item's observation clears it.
        $obs = RentalInspectionObservation::where('rental_inspection_id', $i->id)->first();
        $this->photoOn($i)->forceFill(['rental_inspection_observation_id' => $obs->id])->save();
        $this->assertSame([], $i->fresh()->itemsWithoutPhoto()->pluck('id')->all());
        $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $i))->assertOk();
    }

    public function test_an_item_marked_not_applicable_needs_no_photo(): void
    {
        $i = $this->recording();
        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $i->id, 'rental_inspection_item_id' => $this->item->id,
            'observed_by_user_id' => $this->admin->id, 'condition' => RentalInspectionObservation::CONDITION_NA, 'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ]);

        $this->assertSame([], $i->fresh()->itemsWithoutPhoto()->pluck('id')->all());
    }

    // ═══ Q9 · an empty checklist cannot be signed and sent ════════════════════════

    public function test_an_inspection_with_nothing_to_inspect_cannot_be_marked_ready_to_sign_until_the_agency_allows_it(): void
    {
        RentalInspectionItem::query()->where('property_id', $this->property->id)->delete();
        $i = RentalInspection::create(['agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => 'in', 'created_by_user_id' => $this->admin->id, 'inspector_user_id' => $this->inspector->id]);
        $this->recordAttendanceForEveryParty($i);

        $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $i))->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'nothing to inspect yet'));
        $this->assertSame('draft', $i->fresh()->status);

        $this->rule('empty_checklist_blocks_signing', false);
        $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $i))->assertOk();
    }

    // ═══ Q11 · Routine follows the same checks (agency rule) ══════════════════════

    public function test_a_routine_inspection_needs_every_item_recorded_by_default_and_stays_light_when_the_agency_says_so(): void
    {
        $routine = RentalInspection::create(['agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => 'ad_hoc', 'created_by_user_id' => $this->admin->id, 'inspector_user_id' => $this->inspector->id]);

        $this->assertFalse($routine->isRoutineExemptFromChecks());
        $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $routine))->assertStatus(409)->assertJsonStructure(['ungraded_items']);
        $tail = RentalInspection::tabPayloadFor($this->property)['chain_tail'];
        $this->assertTrue($tail->attendance_required);

        $this->rule('routine_follows_full_checks', false);
        $this->assertTrue($routine->fresh()->isRoutineExemptFromChecks());
        $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $routine))->assertOk();
        $this->assertFalse(RentalInspection::tabPayloadFor($this->property)['chain_tail']->attendance_required);

        // The other types are never exempt, whatever this rule says.
        $in = RentalInspection::create(['agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => 'in', 'created_by_user_id' => $this->admin->id]);
        $this->assertFalse($in->isRoutineExemptFromChecks());
    }

    // ═══ Q10 · the report says who it is from ═════════════════════════════════════

    public function test_the_pdf_and_the_public_report_carry_the_agency_name_and_the_agents_contact_details(): void
    {
        $this->agency->forceFill(['name' => 'Cape Rentals'])->save();
        $this->inspector->forceFill(['designation' => 'property_practitioner', 'cell' => '082 555 0101'])->save();
        $i = $this->recording();

        $text = $this->pdfText($i);
        $this->assertStringContainsString('Cape Rentals', $text);
        $this->assertStringContainsString('Ivan Inspector', $text);
        $this->assertStringContainsString('Property Practitioner', $text);
        $this->assertStringContainsString('825550101', preg_replace('/\D/', '', $text));
        $this->assertStringContainsString('ivan@cape.test', $text);

        $link = app(\App\Services\Distribution\SignedDocumentDistributionService::class)->ensurePublicLink($i->fresh());
        $this->guest();
        $page = $this->get($link)->assertOk();
        $page->assertSee('Cape Rentals')->assertSee('Ivan Inspector')->assertSee('Property Practitioner');
        $this->assertStringContainsString('825550101', preg_replace('/\D/', '', $page->getContent()));
        $this->actingAs($this->admin);

        // Off: neither the PDF nor the page carries them.
        $this->rule('report_shows_agency_branding', false);
        $this->assertStringNotContainsString('825550101', preg_replace('/\D/', '', $this->pdfText($i)));
        $this->guest();
        $this->get($link)->assertOk()->assertDontSee('Property Practitioner');
    }

    public function test_the_second_agency_sees_its_own_name_never_another_agencys(): void
    {
        $other = \App\Models\Agency::create(['name' => 'Karoo Lettings', 'slug' => 'karoo-' . uniqid()]);
        $this->agency->forceFill(['name' => 'Cape Rentals'])->save();
        $i = $this->recording();
        $branding = $i->reportBranding();

        $this->assertSame('Cape Rentals', $branding['agency_name']);
        $this->assertNotSame($other->name, $branding['agency_name']);
        $this->assertSame('Ivan Inspector', $branding['agent']['name']);
    }

    // ═══ Q12 · a person who refused does not get the PDF attached ═════════════════

    public function test_the_completion_email_to_a_person_who_refused_carries_the_link_but_not_the_pdf(): void
    {
        $i = $this->ready();
        $this->signByLink($i, 'tenant', $this->tenant, 'Naledi Dlamini');
        $this->postJson(route('corex.rental-inspections.signatures.store', $i), ['party_role' => 'tenant', 'disposition' => 'refused', 'party_contact_id' => $this->tenant2->id, 'refusal_reason_preset' => 'refused_no_reason'])->assertStatus(201);
        $this->postJson(route('corex.rental-inspections.signatures.store', $i), ['party_role' => 'landlord', 'disposition' => 'signed', 'party_contact_id' => $this->landlord->id, 'signature_image' => self::PNG])->assertStatus(201);
        $this->postJson(route('corex.rental-inspections.signatures.store', $i), ['party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG])->assertStatus(201);

        $copies = app(\App\Services\Rentals\RentalInspectionCopiesService::class);
        $this->complete($i);
        $this->assertSame(['sipho@example.co.za'], $copies->emailsHoldingPdf($i->fresh()));

        $attachmentsByRecipient = [];
        Mail::assertSent(SignedDocumentDistributionMail::class, function (SignedDocumentDistributionMail $m) use (&$attachmentsByRecipient) {
            $attachmentsByRecipient[$m->recipientName] = $m->pdfPath !== null;

            return true;
        });
        $this->assertNotEmpty($attachmentsByRecipient);
        $this->assertFalse($attachmentsByRecipient['Sipho Khumalo'] ?? true, 'the person who refused gets no PDF');
        $this->assertTrue($attachmentsByRecipient['Naledi Dlamini'] ?? false, 'a person who signed does');

        // Agency rule off → nobody is held back.
        $this->rule('hold_pdf_when_refused', false);
        $this->assertSame([], $copies->emailsHoldingPdf($i->fresh()));
    }

    // ═══ Q5 · the lease's other agents see the booking on their calendar ═══════════

    public function test_a_booked_inspection_is_on_the_calendar_of_the_leases_owner_and_tenant_agent_and_follows_cancel_and_archive(): void
    {
        $ownerAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Olivia Owner']);
        $tenantAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'name' => 'Tessa Tenant']);
        $this->lease->forceFill(['owner_agent_user_id' => $ownerAgent->id, 'tenant_agent_user_id' => $tenantAgent->id])->save();

        $i = RentalInspection::schedule($this->property->fresh(), RentalInspection::TYPE_AD_HOC, $this->admin, ['scheduled_for' => now()->addDays(5)->toDateString(), 'inspector_user_id' => $this->inspector->id]);
        $events = fn () => CalendarEvent::withoutGlobalScopes()->where('source_type', RentalInspection::class)->where('source_id', $i->id)->get();

        $this->assertEqualsCanonicalizing([$this->inspector->id, $ownerAgent->id, $tenantAgent->id], $events()->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['pending'], $events()->pluck('status')->unique()->values()->all());

        // Rescheduling does not duplicate; the inspector who is also a lease agent gets one event only.
        $this->lease->forceFill(['owner_agent_user_id' => $this->inspector->id])->save();
        $i->fresh()->reschedule(['scheduled_for' => now()->addDays(8)->toDateString()], $this->admin, 'moved');
        $this->assertCount(2 + 0, $events()->where('status', 'pending'), 'inspector once + tenant\'s agent; the owner agent entry was dismissed');
        $this->assertSame('dismissed', $events()->firstWhere('user_id', $ownerAgent->id)->status);

        $i->fresh()->delete();
        $this->assertSame(['dismissed'], $events()->pluck('status')->unique()->values()->all());
        $i->fresh()->restore();
        $this->assertSame(2, $events()->where('status', 'pending')->count());

        // Rule off: the extras are dismissed, the inspector's stays.
        $this->rule('calendar_include_lease_agents', false);
        $i->fresh()->reschedule(['scheduled_for' => now()->addDays(9)->toDateString()], $this->admin, 'again');
        $this->assertSame([$this->inspector->id], $events()->where('status', 'pending')->pluck('user_id')->map(fn ($id) => (int) $id)->all());
    }
}
