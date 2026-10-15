<?php

declare(strict_types=1);

namespace Tests\Feature\RentalNotices;

use App\Models\Agency;
use App\Models\LeaseBreach;
use App\Models\LeaseSetting;
use App\Models\RentalNoticeTemplate;
use App\Services\Rentals\LeaseBreachService;
use App\Services\Rentals\RentalNoticeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\LeaseNotices\BuildsNoticeFixture;
use Tests\TestCase;

/**
 * AT-462 round 2 - per-agency breach templates (each with its own remedy days), the payment vs ad hoc screen, the follow-up when the remedy-by
 * date is reached (alert + Next bar + the two answers), the notice-to-vacate period and the proof-of-payment address as agency settings.
 * The fixture agency is Karoo Lettings - nothing of any real agency.
 */
final class BreachTemplatesAndFollowUpTest extends TestCase
{
    use BuildsNoticeFixture;
    use RefreshDatabase;

    private const CLAUSES = "Clause 4.1 - rent is payable on the 1st.\nClause 16.1 - a breach not remedied within 7 days may be cancelled.";

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNoticeFixture();
        Storage::fake('local');
        $this->withoutVite();
    }

    private function paymentTemplate(array $over = []): RentalNoticeTemplate
    {
        return RentalNoticeTemplate::create($over + [
            'agency_id' => $this->agency->id, 'name' => 'Payment breach', 'notice_type' => 'breach_payment', 'is_active' => true, 'default_clause' => self::CLAUSES,
            'body_html' => '<p>NCR: we will list this failure. Amount {{amount}} on {{property_address}}. Pay by {{remedy_deadline}} and send proof to {{payment_proof_email}}.</p><p>{{clause}}</p>',
        ]);
    }

    private function adHocTemplate(array $over = []): RentalNoticeTemplate
    {
        return RentalNoticeTemplate::create($over + [
            'agency_id' => $this->agency->id, 'name' => 'Ad hoc breach', 'notice_type' => 'breach_other', 'is_active' => true, 'default_clause' => 'Clause 16.1 - remedy within 7 days.',
            'body_html' => '<p>Breach: {{reason}}</p><p>{{clause}}</p><p>Remedy by {{remedy_deadline}} and confirm to {{agency_email}}.</p>',
        ]);
    }

    private function vacateTemplate(): RentalNoticeTemplate
    {
        return RentalNoticeTemplate::create([
            'agency_id' => $this->agency->id, 'name' => 'Vacate', 'notice_type' => 'notice_to_vacate', 'is_active' => true,
            'body_html' => '<p>Be out by {{vacate_time}} on {{vacate_date}}.</p>',
        ]);
    }

    private function issueAdHoc(RentalNoticeTemplate $tpl, array $over = [])
    {
        return $this->actingAs($this->agent)->post(route('corex.leases.breaches.store', $this->lease), $over + [
            'rental_notice_template_id' => $tpl->id, 'breach_party' => 'tenant', 'clause' => 'Clause 12.3 - keep the pool clean', 'reason' => 'Pool not maintained', 'intent' => 'send',
        ]);
    }

    private function openBreach(string $deadline): LeaseBreach
    {
        $this->issueAdHoc($this->adHocTemplate())->assertSessionHasNoErrors();
        $breach = LeaseBreach::withoutGlobalScopes()->latest('id')->firstOrFail();
        $breach->forceFill(['remedy_deadline' => $deadline])->save();

        return $breach->fresh();
    }

    // ── 1. templates: CRUD + agency scoping + remedy days ──────────────────

    public function test_an_agency_makes_as_many_breach_templates_as_it_wants_each_with_its_own_remedy_days_and_can_edit_archive_and_restore(): void
    {
        $post = fn (array $d) => $this->actingAs($this->agent)->post(route('corex.rental-notice-templates.store'), $d + ['notice_type' => 'breach_payment', 'body_html' => '<p>{{amount}}</p>']);

        $post(['name' => 'Payment breach', 'remedy_days' => 7, 'default_clause' => self::CLAUSES])->assertSessionHasNoErrors();
        $post(['name' => 'Second payment wording', 'remedy_days' => 14])->assertSessionHasNoErrors();
        $post(['name' => 'Ad hoc breach', 'notice_type' => 'breach_other'])->assertSessionHasNoErrors();
        $this->assertSame(3, RentalNoticeTemplate::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());

        $list = $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.index'))->assertOk()->getContent();
        foreach (['Payment breach', 'Second payment wording', 'Ad hoc breach', '7 days', '14 days', 'Breach notice (ad hoc)'] as $expected) {
            $this->assertStringContainsString($expected, $list);
        }

        $first = RentalNoticeTemplate::withoutGlobalScopes()->where('name', 'Payment breach')->firstOrFail();
        $this->assertSame(7, $first->remedy_days);
        $this->assertSame(self::CLAUSES, $first->default_clause);
        $this->actingAs($this->agent)->put(route('corex.rental-notice-templates.update', $first), ['name' => 'Payment breach', 'notice_type' => 'breach_payment', 'body_html' => '<p>x</p>', 'remedy_days' => 9])->assertSessionHasNoErrors();
        $this->assertSame(9, $first->fresh()->remedy_days);
        $this->actingAs($this->agent)->put(route('corex.rental-notice-templates.update', $first), ['name' => 'Payment breach', 'notice_type' => 'breach_payment', 'body_html' => '<p>x</p>', 'remedy_days' => ''])->assertSessionHasNoErrors();
        $this->assertNull($first->fresh()->remedy_days, 'blank = the agency default');

        // rubbish is refused
        $post(['name' => 'Bad', 'remedy_days' => 0])->assertSessionHasErrors('remedy_days');
        $post(['name' => 'Bad', 'remedy_days' => 400])->assertSessionHasErrors('remedy_days');

        // soft delete only
        $this->actingAs($this->agent)->post(route('corex.rental-notice-templates.archive', $first))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('rental_notice_templates', ['id' => $first->id]);
        $this->assertStringNotContainsString('<td class="px-4 py-2">Payment breach</td>', $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.index'))->getContent());
        $this->assertStringContainsString('Payment breach', $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.index', ['status' => 'archived']))->getContent());
        $this->actingAs($this->agent)->post(route('corex.rental-notice-templates.restore', $first->id))->assertSessionHasNoErrors();
        $this->assertNull($first->fresh()->deleted_at);
    }

    public function test_templates_are_agency_scoped_in_the_list_the_edit_the_update_and_the_archive(): void
    {
        $other = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $theirs = RentalNoticeTemplate::create(['agency_id' => $other->id, 'name' => 'Cape payment breach', 'notice_type' => 'breach_payment', 'body_html' => '<p>x</p>', 'is_active' => true, 'remedy_days' => 3]);

        $this->assertStringNotContainsString('Cape payment breach', $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.index'))->getContent());
        $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.edit', $theirs))->assertNotFound();
        $this->actingAs($this->agent)->put(route('corex.rental-notice-templates.update', $theirs), ['name' => 'Hijack', 'notice_type' => 'breach_payment', 'body_html' => '<p>x</p>'])->assertNotFound();
        $this->actingAs($this->agent)->post(route('corex.rental-notice-templates.archive', $theirs))->assertNotFound();
        $this->assertSame('Cape payment breach', $theirs->fresh()->name);
        $this->assertNull($theirs->fresh()->deleted_at);

        // and the breach screen offers only this agency's own
        $this->paymentTemplate();
        $screen = $this->actingAs($this->agent)->get(route('corex.leases.breaches.create', $this->lease))->assertOk()->getContent();
        $this->assertStringContainsString('Payment breach', $screen);
        $this->assertStringNotContainsString('Cape payment breach', $screen);
    }

    public function test_the_notice_templates_settings_entry_is_searchable_by_breach_words(): void
    {
        $this->grant($this->agent, ['access_settings', 'leases.manage_settings']);
        $html = $this->actingAs($this->agent)->get(route('corex.settings'))->getContent();

        $this->assertStringContainsString('rental-notice-templates', $html);
        foreach (['payment breach', 'ad hoc breach', 'remedy days', 'rental payment proof email', 'days for notice to vacate'] as $word) {
            $this->assertStringContainsString($word, $html, "'{$word}' finds its setting");
        }
    }

    // ── 2. payment vs ad hoc ───────────────────────────────────────────────

    public function test_a_template_an_agency_creates_in_settings_is_selectable_on_the_breach_screen_and_can_be_sent(): void
    {
        $this->paymentTemplate();
        $make = fn (string $name, string $type, array $over = []) => $this->actingAs($this->agent)->post(route('corex.rental-notice-templates.store'), $over + [
            'name' => $name, 'notice_type' => $type, 'body_html' => '<p>{{reason}} - {{clause}}</p>', 'remedy_days' => 10,
        ])->assertSessionHasNoErrors();

        $make('General breach', 'breach_other');
        $make('Older general', 'breach');
        $make('Second payment', 'breach_payment');
        $make('Vacate wording', 'notice_to_vacate');
        $make('Switched off', 'breach_other', ['is_active' => 0]);
        $screen = fn () => $this->actingAs($this->agent)->get(route('corex.leases.breaches.create', $this->lease))->assertOk()->getContent();

        $html = $screen();
        foreach (['Payment breach', 'General breach', 'Older general', 'Second payment'] as $name) {
            $this->assertStringContainsString('>' . $name . '</option>', $html, "{$name} is selectable");
        }
        $this->assertStringNotContainsString('>Vacate wording</option>', $html, 'a notice to vacate is not a breach notice');
        $this->assertStringNotContainsString('>Switched off</option>', $html, 'an inactive template is not offered');
        $this->assertMatchesRegularExpression('/data-kind="other"[^>]*>General breach<\/option>/', $html, 'an ad hoc template opens the clause / reason fields');
        $this->assertMatchesRegularExpression('/data-kind="payment"[^>]*>Second payment<\/option>/', $html);

        // it really can be sent from the screen
        $general = RentalNoticeTemplate::withoutGlobalScopes()->where('name', 'General breach')->firstOrFail();
        $this->issueAdHoc($general)->assertSessionHasNoErrors();
        $breach = LeaseBreach::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('other', $breach->kind);
        $this->assertSame(10, (int) $breach->remedy_business_days, 'its own remedy days');

        // archiving removes it from the screen, restoring brings it back
        $this->actingAs($this->agent)->post(route('corex.rental-notice-templates.archive', $general))->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('>General breach</option>', $screen());
        $this->actingAs($this->agent)->post(route('corex.rental-notice-templates.restore', $general->id))->assertSessionHasNoErrors();
        $this->assertStringContainsString('>General breach</option>', $screen());
    }

    public function test_the_bulk_sheet_lists_payment_templates_only_and_says_where_the_others_are(): void
    {
        $this->paymentTemplate();
        $this->adHocTemplate(['name' => 'General breach']);

        $bulk = $this->actingAs($this->agent)->get(route('corex.breaches.bulk', ['leases' => [$this->lease->id]]))->assertOk()->getContent();

        $this->assertStringContainsString('>Payment breach</option>', $bulk);
        $this->assertStringNotContainsString('>General breach</option>', $bulk);
        $this->assertStringContainsString('data-qa="bulk-templates-note"', $bulk, 'it says why an ad hoc template is not here and where to find it');
        $this->assertStringContainsString('Breach notice</strong> button', $bulk);
    }

    public function test_a_templates_link_reaches_settings_from_the_breach_screens_for_those_who_may_manage_them(): void
    {
        $this->paymentTemplate();
        $link = 'data-qa="breach-templates-link"';
        $href = route('corex.rental-notice-templates.index');

        foreach ([route('corex.leases.breaches.create', $this->lease), route('corex.breaches.index'), route('corex.breaches.bulk', ['leases' => [$this->lease->id]])] as $url) {
            $html = $this->actingAs($this->agent)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString($link, $html, $url);
            $this->assertStringContainsString('href="' . $href . '"', $html, $url);
        }

        // someone who may issue breaches but not manage templates is not shown a link they could not use
        $clerk = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'breach_clerk']);
        $this->grant($clerk, ['leases.view', 'rental_breaches.view', 'rental_breaches.manage']);
        $this->assertStringNotContainsString($link, $this->actingAs($clerk)->get(route('corex.breaches.index'))->assertOk()->getContent());
        $this->assertStringNotContainsString($link, $this->actingAs($clerk)->get(route('corex.leases.breaches.create', $this->lease))->assertOk()->getContent());
    }

    public function test_the_template_form_says_which_screen_offers_which_type(): void
    {
        $html = $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.create'))->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="template-type-help"', $html);
        $this->assertStringContainsString('bulk payment sheet', $html);
    }

    public function test_the_breach_screen_lists_every_template_and_opens_the_right_fields(): void
    {
        $this->paymentTemplate(['remedy_days' => 5]);
        $this->adHocTemplate();

        $html = $this->actingAs($this->agent)->get(route('corex.leases.breaches.create', $this->lease))->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="breach-template"', $html);
        $this->assertStringContainsString('data-kind="payment"', $html);
        $this->assertStringContainsString('data-kind="other"', $html);
        foreach (['data-qa="breach-amount"', 'data-qa="breach-party-field"', 'data-qa="breach-reason-field"', 'data-qa="breach-clause"'] as $field) {
            $this->assertStringContainsString($field, $html);
        }
        $this->assertStringContainsString('data-window="5 business days"', $html, 'each template says how long it allows');
        $this->assertStringContainsString('data-window="20 business days"', $html, 'a template with no days says the agency default');
    }

    public function test_a_payment_template_sends_a_payment_breach_with_the_amount_the_templates_clause_and_its_own_remedy_days(): void
    {
        LeaseSetting::updateOrCreate(['agency_id' => $this->agency->id], ['breach_remedy_business_days' => 20, 'breach_remedy_day_basis' => 'calendar']);
        $tpl = $this->paymentTemplate(['remedy_days' => 5]);

        $this->actingAs($this->agent)->post(route('corex.leases.breaches.store', $this->lease), ['rental_notice_template_id' => $tpl->id, 'amount' => 'R 9 500,00', 'intent' => 'send'])->assertSessionHasNoErrors();

        $breach = LeaseBreach::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('payment', $breach->kind);
        $this->assertSame('tenant', $breach->breach_party);
        $this->assertSame('9500.00', (string) $breach->amount);
        $this->assertSame(5, (int) $breach->remedy_business_days, 'the template\'s own days beat the agency default of 20');
        $this->assertSame(Carbon::today()->addDays(5)->toDateString(), $breach->remedy_deadline->toDateString());
        $html = app(RentalNoticeService::class)->render($tpl, [], $this->lease, $breach, $this->agent);
        $this->assertStringContainsString('NCR: we will list this failure', $html);
        $this->assertStringContainsString('R 9 500.00', $html);
        $this->assertStringContainsString("Clause 4.1 - rent is payable on the 1st.<br>\nClause 16.1", $html);

        // a payment template needs an amount
        $this->actingAs($this->agent)->post(route('corex.leases.breaches.store', $this->lease), ['rental_notice_template_id' => $tpl->id, 'amount' => '', 'intent' => 'send'])->assertSessionHasErrors('amount');
    }

    public function test_an_ad_hoc_template_asks_for_clause_and_reason_and_its_notice_has_no_ncr_sentence(): void
    {
        $this->paymentTemplate();
        $ad = $this->adHocTemplate();

        $this->issueAdHoc($ad, ['clause' => '', 'reason' => ''])->assertSessionHasErrors(['clause', 'reason']);
        $this->issueAdHoc($ad, ['breach_party' => ''])->assertSessionHasErrors('breach_party');
        $this->assertSame(0, LeaseBreach::withoutGlobalScopes()->count());

        $this->issueAdHoc($ad)->assertSessionHasNoErrors();
        $breach = LeaseBreach::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('other', $breach->kind);
        $html = app(RentalNoticeService::class)->render($ad, [], $this->lease, $breach, $this->agent);
        $this->assertStringContainsString('Breach: Pool not maintained', $html);
        $this->assertStringContainsString('Clause 12.3 - keep the pool clean', $html);
        $this->assertStringNotContainsString('NCR', $html);
        $this->assertStringContainsString('desk@karoo.test', $html);

        // and the two templates really do read differently for the same lease
        $payment = $this->paymentTemplate(['name' => 'Another payment']);
        $this->assertNotSame($html, app(RentalNoticeService::class)->render($payment, [], $this->lease, $breach, $this->agent));
    }

    public function test_the_bulk_sheet_uses_the_payment_template_and_its_remedy_days(): void
    {
        $tpl = $this->paymentTemplate(['remedy_days' => 6]);
        $this->adHocTemplate();

        $screen = $this->actingAs($this->agent)->get(route('corex.breaches.bulk', ['leases' => [$this->lease->id]]))->assertOk()->getContent();
        $this->assertStringContainsString('Payment breach', $screen);
        $this->assertStringNotContainsString('Ad hoc breach', $screen, 'only payment templates on the payment sheet');

        $this->actingAs($this->agent)->post(route('corex.breaches.bulk.store'), ['rental_notice_template_id' => $tpl->id, 'amount' => [$this->lease->id => '1200']])->assertOk();
        $this->assertSame(6, (int) LeaseBreach::withoutGlobalScopes()->firstOrFail()->remedy_business_days);
    }

    // ── 3. proof-of-payment address ────────────────────────────────────────

    public function test_the_proof_email_comes_from_the_agency_setting_and_falls_back_to_the_agency_email(): void
    {
        $tpl = $this->paymentTemplate();
        $this->actingAs($this->agent)->post(route('corex.leases.breaches.store', $this->lease), ['rental_notice_template_id' => $tpl->id, 'amount' => '500', 'intent' => 'send'])->assertSessionHasNoErrors();
        $breach = LeaseBreach::withoutGlobalScopes()->firstOrFail();
        $render = fn () => app(RentalNoticeService::class)->render($tpl, [], $this->lease->fresh(), $breach, $this->agent);

        $this->assertStringContainsString('send proof to desk@karoo.test', $render(), 'no setting = the agency email');
        $this->assertSame('desk@karoo.test', LeaseSetting::paymentProofEmailFor($this->agency->id));

        LeaseSetting::updateOrCreate(['agency_id' => $this->agency->id], ['payment_proof_email' => 'rentals@karoo.test']);
        $this->assertStringContainsString('send proof to rentals@karoo.test', $render());
        $this->assertStringNotContainsString('desk@karoo.test', $render());
    }

    public function test_the_two_new_settings_save_without_wiping_each_other_and_are_in_the_wizard(): void
    {
        $this->grant($this->agent, ['leases.manage_settings']);
        $save = fn (array $d) => $this->actingAs($this->agent)->post(route('corex.settings.leases.update'), $d + ['expiry_notice_window_days' => 60]);

        $save(['vacate_notice_days' => 14, 'payment_proof_email' => 'rentals@karoo.test'])->assertSessionHasNoErrors();
        $this->assertSame(14, LeaseSetting::vacateNoticeDaysFor($this->agency->id));
        $this->assertSame('rentals@karoo.test', LeaseSetting::paymentProofEmailFor($this->agency->id));

        $save([])->assertSessionHasNoErrors(); // a wizard step that never rendered them
        $this->assertSame(14, LeaseSetting::vacateNoticeDaysFor($this->agency->id));
        $this->assertSame('rentals@karoo.test', LeaseSetting::paymentProofEmailFor($this->agency->id));

        $save(['vacate_notice_days' => '', 'payment_proof_email' => ''])->assertSessionHasNoErrors();
        $this->assertSame(30, LeaseSetting::vacateNoticeDaysFor($this->agency->id), 'the neutral default');
        $this->assertSame('desk@karoo.test', LeaseSetting::paymentProofEmailFor($this->agency->id));
        $save(['payment_proof_email' => 'not an email'])->assertSessionHasErrors('payment_proof_email');
        $save(['vacate_notice_days' => 0])->assertSessionHasErrors('vacate_notice_days');
        $this->assertStringContainsString('name="payment_proof_email"', $this->actingAs($this->agent)->get(route('corex.settings.leases.edit'))->getContent());

        $found = [];
        $walk = function ($node) use (&$walk, &$found) {
            if (is_array($node)) {
                if (isset($node['key'], $node['explain'], $node['affects'])) {
                    $found[$node['key']] = $node;
                }
                foreach ($node as $child) {
                    $walk($child);
                }
            }
        };
        $walk(config('agency-onboarding-copy'));
        foreach (['vacate_notice_days', 'payment_proof_email'] as $key) {
            $this->assertArrayHasKey($key, $found, $key . ' is in the wizard');
            $this->assertGreaterThan(40, strlen($found[$key]['affects']));
        }
    }

    // ── 4. follow-up when the remedy-by date is reached ────────────────────

    public function test_the_alert_goes_to_both_lease_agents_once_when_the_remedy_by_date_is_reached(): void
    {
        $breach = $this->openBreach(Carbon::today()->toDateString());

        $this->assertSame(0, Artisan::call('breaches:check-remedy-dates', ['--dry-run' => true]));
        $this->assertNull($breach->fresh()->remedy_alert_sent_at, 'a dry run tells no one');
        $this->assertCount(0, $this->agent->fresh()->notifications);

        Artisan::call('breaches:check-remedy-dates');
        $this->assertNotNull($breach->fresh()->remedy_alert_sent_at);
        foreach ([$this->agent, $this->agent2] as $agent) {
            $mine = $agent->fresh()->notifications->filter(fn ($n) => str_contains(json_encode($n->data), 'Breach remedy date reached'));
            $this->assertCount(1, $mine, $agent->name . ' is told');
            $this->assertStringContainsString('4 Vlei Court', json_encode($mine->first()->data), 'the title ends with the property');
        }

        Artisan::call('breaches:check-remedy-dates'); // nothing new
        $this->assertCount(1, $this->agent->fresh()->notifications->filter(fn ($n) => str_contains(json_encode($n->data), 'Breach remedy date reached')));
    }

    public function test_no_alert_before_the_date_or_for_a_remedied_breach(): void
    {
        $early = $this->openBreach(Carbon::today()->addDays(3)->toDateString());
        Artisan::call('breaches:check-remedy-dates');
        $this->assertNull($early->fresh()->remedy_alert_sent_at);
        $this->assertCount(0, $this->agent->fresh()->notifications);

        $early->forceFill(['remedy_deadline' => Carbon::today()->subDay()->toDateString(), 'status' => 'remedied'])->save();
        Artisan::call('breaches:check-remedy-dates');
        $this->assertNull($early->fresh()->remedy_alert_sent_at);
        $this->assertSame(0, app(LeaseBreachService::class)->dueForAlert()->count());
    }

    public function test_the_next_bar_asks_was_it_fixed_with_the_two_actions_only_once_the_date_is_reached(): void
    {
        $breach = $this->openBreach(Carbon::today()->addDays(3)->toDateString());
        $before = $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-qa="next-breach-followup"', $before);

        $breach->forceFill(['remedy_deadline' => Carbon::today()->toDateString()])->save();
        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="next-breach-followup"', $html);
        $this->assertStringContainsString('Breach remedy date reached — was it fixed?', $html);
        $this->assertStringContainsString('data-qa="next-breach-remedied"', $html);
        $this->assertStringContainsString('Breach remedied', $html);
        $this->assertStringContainsString('Send notice to vacate', $html);
        $this->assertStringContainsString(route('corex.breaches.show', $breach->id) . '#vacate-box', $html);
        $this->assertStringContainsString('name="remedied_on"', $html);
    }

    public function test_someone_who_may_not_act_on_breaches_only_sees_the_question(): void
    {
        $this->openBreach(Carbon::today()->toDateString());
        $viewer = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'viewer_only']);
        $this->grant($viewer, ['leases.view']);

        $html = $this->actingAs($viewer)->get(route('corex.leases.show', $this->lease))->assertOk()->getContent();

        $this->assertStringContainsString('Breach remedy date reached — was it fixed?', $html);
        $this->assertStringNotContainsString('data-qa="next-breach-remedied"', $html);
        $this->assertStringNotContainsString('Send notice to vacate', $html);
    }

    public function test_breach_remedied_records_the_date_and_note_closes_the_breach_and_clears_the_next_bar(): void
    {
        $breach = $this->openBreach(Carbon::today()->toDateString());
        $breach->forceFill(['issued_on' => Carbon::today()->subDays(5)->toDateString()])->save(); // sent five days ago, so "put right yesterday" is possible
        $when = Carbon::today()->subDay()->toDateString();

        $res = $this->actingAs($this->agent)->post(route('corex.breaches.remedy', $breach->id), ['remedied_on' => $when, 'note' => 'Paid in full', 'return' => 'lease']);

        $res->assertRedirect(route('corex.leases.show', $this->lease->id));
        $fresh = $breach->fresh();
        $this->assertSame('remedied', $fresh->status);
        $this->assertSame($when, $fresh->remedied_at->toDateString());
        $this->assertSame('Paid in full', $fresh->remedied_note);
        $this->assertSame($this->agent->id, $fresh->remedied_by_user_id);
        $this->assertTrue(\App\Models\LeaseEvent::where('lease_id', $this->lease->id)->where('event_type', \App\Models\LeaseEvent::TYPE_BREACH_REMEDIED)->where('description', 'like', '%Paid in full%')->exists());
        $this->assertStringNotContainsString('data-qa="next-breach-followup"', $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->getContent());
        $this->assertSame(0, app(LeaseBreachService::class)->dueForAlert()->count());

        // closed: it cannot be remedied again or escalated
        $this->actingAs($this->agent)->post(route('corex.breaches.remedy', $breach->id), [])->assertSessionHasErrors('breach');
    }

    public function test_the_remedied_date_cannot_be_in_the_future_or_before_the_breach_was_issued(): void
    {
        $breach = $this->openBreach(Carbon::today()->toDateString());

        $this->actingAs($this->agent)->post(route('corex.breaches.remedy', $breach->id), ['remedied_on' => Carbon::tomorrow()->toDateString()])->assertSessionHasErrors('remedied_on');
        $this->actingAs($this->agent)->post(route('corex.breaches.remedy', $breach->id), ['remedied_on' => Carbon::today()->subDays(5)->toDateString()])->assertSessionHasErrors('remedied_on');
        $this->assertSame('issued', $breach->fresh()->status);
    }

    public function test_send_notice_to_vacate_is_available_from_the_remedy_by_date_and_stays_gated_on_an_unremedied_breach(): void
    {
        $vacate = $this->vacateTemplate();
        $breach = $this->openBreach(Carbon::today()->addDay()->toDateString());
        $post = fn () => $this->actingAs($this->agent)->post(route('corex.breaches.vacate', $breach->id), ['rental_notice_template_id' => $vacate->id, 'intent' => 'send', 'vacate_date' => Carbon::today()->addDays(30)->toDateString(), 'vacate_time' => '12:00']);

        $post()->assertSessionHasErrors('breach'); // the day before the date
        $this->assertSame('issued', $breach->fresh()->status);

        $breach->forceFill(['remedy_deadline' => Carbon::today()->toDateString()])->save(); // the day itself
        $this->assertTrue($breach->fresh()->canIssueVacate());
        $post()->assertSessionHasNoErrors();
        $this->assertSame('escalated', $breach->fresh()->status);

        $other = $this->openBreach(Carbon::today()->toDateString());
        $this->actingAs($this->agent)->post(route('corex.breaches.remedy', $other->id), ['note' => 'fixed'])->assertSessionHasNoErrors();
        $this->actingAs($this->agent)->post(route('corex.breaches.vacate', $other->id), ['rental_notice_template_id' => $vacate->id, 'intent' => 'send', 'vacate_date' => Carbon::today()->addDays(30)->toDateString()])->assertSessionHasErrors('breach');
    }

    // ── 5. notice-to-vacate period ─────────────────────────────────────────

    public function test_the_vacate_date_starts_from_the_agency_setting_days_after_the_day_it_is_sent_and_stays_editable(): void
    {
        $vacate = $this->vacateTemplate();
        $breach = $this->openBreach(Carbon::today()->toDateString());

        $default = $this->actingAs($this->agent)->get(route('corex.breaches.show', $breach->id))->getContent();
        $this->assertStringContainsString('value="' . Carbon::today()->addDays(30)->toDateString() . '"', $default, '30 is the neutral default');

        LeaseSetting::updateOrCreate(['agency_id' => $this->agency->id], ['vacate_notice_days' => 14]);
        $custom = $this->actingAs($this->agent)->get(route('corex.breaches.show', $breach->id))->getContent();
        $this->assertStringContainsString('value="' . Carbon::today()->addDays(14)->toDateString() . '"', $custom);
        $this->assertStringContainsString('14 days from today', $custom);

        // the agent can change it on the notice
        $edited = Carbon::today()->addDays(45)->toDateString();
        $this->actingAs($this->agent)->post(route('corex.breaches.vacate', $breach->id), ['rental_notice_template_id' => $vacate->id, 'intent' => 'send', 'vacate_date' => $edited, 'vacate_time' => '10:00'])->assertSessionHasNoErrors();
        $fresh = $breach->fresh();
        $this->assertSame($edited, $fresh->vacate_date->toDateString());
        $this->assertSame('10:00', $fresh->vacate_time);
        $this->assertSame(Carbon::today()->addDays(14)->toDateString(), app(LeaseBreachService::class)->defaultVacateDate($this->agency->id)->toDateString());
    }

    public function test_the_breach_panel_and_screen_say_the_date_is_reached_not_passed(): void
    {
        $breach = $this->openBreach(Carbon::today()->toDateString());

        $panel = $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->getContent();
        $this->assertStringContainsString('(due today)', $panel);
        $show = $this->actingAs($this->agent)->get(route('corex.breaches.show', $breach->id))->getContent();
        $this->assertStringContainsString('date reached - was it fixed?', $show);
        $this->assertStringContainsString('data-qa="remedied-on"', $show);
    }
}
