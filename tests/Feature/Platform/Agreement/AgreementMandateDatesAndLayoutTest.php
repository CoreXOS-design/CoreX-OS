<?php

namespace Tests\Feature\Platform\Agreement;

use App\Models\DevSetting;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Signer;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformEsign\Agreement\AgreementSample;
use App\Services\PlatformEsign\Agreement\AgreementService;
use App\Services\PlatformEsign\Agreement\AgreementSettings;
use App\Services\PlatformEsign\Agreement\AgreementTakeOn;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Netcash mandate (page 19, "A. Authority/Mandate") — 27 Oct 2026:
 *  (1) the address / bank rows flow for any number of lines (no fixed-height text rows) on screen;
 *  (2) the mandate Date, the first payment date and the collection day are never typed by the signer: the signing date is stamped by the
 *      server when the agency signs (and the countersign date when RR countersigns); the first debit and the day come from the take-on month
 *      and two platform settings (free months, collection day), frozen onto each agreement at send;
 *  (3) once both parties have signed the agreement is locked.
 */
class AgreementMandateDatesAndLayoutTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Feature\Platform\Agreement\Concerns\PinsTierPricing;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Role::clearCache();
        Storage::disk('local')->deleteDirectory('platform-esign');
        parent::tearDown();
    }

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'name' => 'Johan Reichel']);
    }

    private function svc(): AgreementService
    {
        return app(AgreementService::class);
    }

    private function send(User $owner, array $extra = []): Document
    {
        Mail::fake();

        return $this->svc()->send($extra + ['name' => 'Pat Principal', 'email' => 'pat@caprivi.test', 'take_on_month' => now()->format('Y-m')], $owner->id);
    }

    private function token(Document $doc): string
    {
        return Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('token');
    }

    private function fullValues(Document $doc, array $override = []): array
    {
        return $override + AgreementSample::ctx($doc->wording)['values'] + ['sigA' => self::PNG, 'sigM' => self::PNG];
    }

    private function agencySigns(Document $doc, array $override = []): void
    {
        $token = $this->token($doc);
        $this->postJson(route('platform-esign.agreement.initials', $token), ['initials' => 'pp'])->assertOk();
        foreach (range(1, $this->svc()->totalPages($doc)) as $p) {
            $this->postJson(route('platform-esign.agreement.initial-page', [$token, $p]))->assertOk();
        }
        $this->postJson(route('platform-esign.agreement.submit', $token), ['values' => $this->fullValues($doc, $override), 'id_number' => '8001015009087', 'consent' => 1])->assertOk();
    }

    private function countersign(User $owner, Document $doc, array $values = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($owner)->postJson(route('platform-esign.agreements.countersign.store', $doc->id), [
            'values' => $values + ['rr_name' => 'Johan Reichel', 'rr_capacity' => 'Director', 'rr_place' => 'Southbroom', 'sigR' => self::PNG],
            'initials' => 'JR', 'pages' => range(1, $this->svc()->totalPages($doc)),
        ]);
    }

    // ── (2) the dates ──────────────────────────────────────────────────────

    public function test_the_signing_date_is_stamped_by_the_server_on_the_day_the_agency_signs_whatever_was_typed(): void
    {
        Carbon::setTestNow('2026-10-14 09:00:00');
        $doc = $this->send($this->owner());
        $token = $this->token($doc);

        // typed (or tampered) signing dates in an autosave are ignored — the stored entries never hold them
        $this->postJson(route('platform-esign.agreement.save', $token), ['rev' => 0, 'values' => ['sig_date' => '2000-01-01', 'm_date' => '1999-12-31']])->assertOk();
        $this->assertSame('', (string) (((array) $doc->fresh()->form_data)['sig_date'] ?? ''));
        $this->assertSame('', (string) (((array) $doc->fresh()->form_data)['m_date'] ?? ''));

        Carbon::setTestNow('2026-10-20 15:30:00'); // signs six days later
        $this->agencySigns($doc, ['sig_date' => '2000-01-01', 'm_date' => '1999-12-31']);

        $d = $doc->fresh();
        $this->assertSame('2026-10-20', $d->form_data['sig_date'], 'Part A signing date = the day of signing');
        $this->assertSame('2026-10-20', $d->form_data['m_date'], 'the mandate Date = the same day');
    }

    public function test_the_page_shows_the_date_read_only_and_the_browser_never_writes_it(): void
    {
        Carbon::setTestNow('2026-10-14 09:00:00');
        $doc = $this->send($this->owner());
        $html = $this->get(route('platform-esign.agreement.show', $this->token($doc)))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*id="fld-sig_date"[^>]*readonly[^>]*>/', $html, 'Part A date is read-only');
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="fld-sig_date"[^>]*data-required/', $html, 'the signer does not fill it in');
        $this->assertMatchesRegularExpression('/<input[^>]*readonly[^>]*data-mirror-of="sig_date"[^>]*>/', $html, 'the mandate Date mirrors it, read-only');
        $this->assertStringNotContainsString('name="m_date"', $html, 'the mandate Date is not an input of its own');
        $this->assertStringNotContainsString("['sig_date', 'm_date'].forEach", $html, 'the page script no longer writes the signing date');
    }

    public function test_the_countersign_date_is_the_day_rr_countersigns_not_what_was_typed(): void
    {
        Carbon::setTestNow('2026-10-14 09:00:00');
        $owner = $this->owner();
        $doc = $this->send($owner);
        $this->agencySigns($doc);

        Carbon::setTestNow('2026-10-22 11:00:00');
        $this->countersign($owner, $doc, ['rr_date' => '2001-02-03'])->assertOk();

        $d = $doc->fresh();
        $this->assertSame('2026-10-22', $d->rr_data['rr_date']);
        $this->assertSame('completed', $d->status);
    }

    public function test_the_countersign_screen_shows_its_date_read_only(): void
    {
        Carbon::setTestNow('2026-10-14 09:00:00');
        $owner = $this->owner();
        $doc = $this->send($owner);
        $this->agencySigns($doc);

        $html = $this->actingAs($owner)->get(route('platform-esign.agreements.countersign', $doc->id))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*id="fld-rr_date"[^>]*readonly[^>]*>/', $html);
    }

    public static function takeOns(): array
    {
        return [
            'October -> first debit 1 November' => ['2026-10', '2026-11-01'],
            'December -> first debit 1 January, next year' => ['2026-12', '2027-01-01'],
        ];
    }

    /** @dataProvider takeOns */
    public function test_the_first_debit_and_day_are_derived_before_sending_and_merged_into_the_mandate(string $month, string $firstDebit): void
    {
        Carbon::setTestNow('2026-10-14 09:00:00');
        $doc = $this->send($this->owner(), ['take_on_month' => $month]);

        $f = $doc->form_data;
        $this->assertSame($firstDebit, $f['m_first_payment']);
        $this->assertSame('1', $f['m_day'], 'default collection day is the 1st');
        $this->assertSame($month . '-01', $f['start_date']);

        // the signer can type neither
        $this->postJson(route('platform-esign.agreement.save', $this->token($doc)), ['rev' => 0, 'values' => ['m_first_payment' => '2030-01-01', 'm_day' => '15']])->assertOk();
        $this->assertSame($firstDebit, $doc->fresh()->form_data['m_first_payment']);
        $this->assertSame('1', $doc->fresh()->form_data['m_day']);
    }

    public function test_the_page_shows_both_read_only_and_the_sticky_header_carries_the_summary(): void
    {
        Carbon::setTestNow('2026-12-10 09:00:00');
        $doc = $this->send($this->owner(), ['take_on_month' => '2026-12']);
        $html = $this->get(route('platform-esign.agreement.show', $this->token($doc)))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*value="1 January 2027"[^>]*readonly[^>]*data-derived="1"/', $html, 'the first payment date is shown read-only');
        $this->assertStringContainsString('1 January 2027', $html);
        $this->assertStringContainsString('Starts December 2026 · first debit 1 January 2027 · collected on the 1st of each month', $html);
    }

    public function test_the_owners_read_only_review_shows_the_same_summary(): void
    {
        Carbon::setTestNow('2026-12-10 09:00:00');
        $owner = $this->owner();
        $doc = $this->send($owner, ['take_on_month' => '2026-12']);
        $this->actingAs($owner)->get(route('platform-esign.agreements.review', $doc->id))->assertOk()
            ->assertSee('Starts December 2026 · first debit 1 January 2027 · collected on the 1st of each month');
    }

    public function test_free_months_and_collection_day_are_platform_settings_with_defaults_one_and_one(): void
    {
        $this->assertSame(1, AgreementSettings::get('free_months'));
        $this->assertSame(1, AgreementSettings::get('collection_day'));
        $this->assertSame(1, AgreementSettings::defaults()['free_months']);
        $this->assertSame(1, AgreementSettings::defaults()['collection_day']);

        AgreementSettings::save(array_merge(AgreementSettings::all(), ['free_months' => 2, 'collection_day' => 5]));
        $this->assertSame(2, AgreementSettings::get('free_months'));
        $this->assertSame(5, AgreementSettings::get('collection_day'));
    }

    public function test_a_changed_setting_applies_to_the_next_agreement_and_never_moves_one_already_sent(): void
    {
        Carbon::setTestNow('2026-10-14 09:00:00');
        $owner = $this->owner();
        $before = $this->send($owner, ['take_on_month' => '2026-10']);

        DevSetting::set('platform_esign.agreement_free_months', '2');
        DevSetting::set('platform_esign.agreement_collection_day', '5');
        $after = $this->send($owner, ['take_on_month' => '2026-10']);

        $this->assertSame('2026-11-01', $before->fresh()->form_data['m_first_payment']);
        $this->assertSame('1', $before->fresh()->form_data['m_day']);
        $this->assertSame('2026-12-01', $after->fresh()->form_data['m_first_payment'], 'two free months: billing from 1 December');
        $this->assertSame('5', $after->fresh()->form_data['m_day']);

        // re-setting the take-on month of the older one keeps ITS frozen values
        $this->svc()->setTakeOn($before, '2026-11', $owner->id);
        $this->assertSame('2026-12-01', $before->fresh()->form_data['m_first_payment']);
        $this->assertSame('1', $before->fresh()->form_data['m_day']);
    }

    public function test_the_collection_day_can_be_overridden_for_one_agreement_and_is_validated(): void
    {
        Carbon::setTestNow('2026-10-14 09:00:00');
        $owner = $this->owner();
        $doc = $this->send($owner, ['collection_day' => '7']);
        $this->assertSame('7', $doc->fresh()->form_data['m_day']);
        $this->assertSame(7, $doc->fresh()->rr_data['collection_day']);

        foreach (['0', '29', 'x'] as $bad) {
            try {
                $this->send($owner, ['collection_day' => $bad]);
                $this->fail("$bad accepted");
            } catch (\DomainException $e) {
                $this->assertStringContainsString('1 to 28', $e->getMessage());
            }
        }
        $this->assertSame('Starts October 2026 · first debit 1 November 2026 · collected on the 7th of each month', AgreementTakeOn::summary($doc->fresh()->rr_data));
    }

    public function test_the_send_screen_prefills_this_month_and_a_debit_day_and_both_are_editable(): void
    {
        Carbon::setTestNow('2026-10-14 09:00:00');
        $owner = $this->owner();
        $this->actingAs($owner)->get(route('platform-esign.agreements.create'))->assertOk()
            ->assertSee('value="2026-10" data-start', false)
            ->assertSee('name="collection_day"', false);
        $html = $this->actingAs($owner)->get(route('platform-esign.agreements.create'))->getContent();
        $this->assertMatchesRegularExpression('/<option value="2026-10"[^>]*selected/', $html, 'the month of sending is preselected');
        $this->assertMatchesRegularExpression('/name="collection_day"[^>]*value="1"/', $html);
    }

    public function test_an_agreement_sent_before_the_settings_existed_keeps_one_free_month_and_the_first(): void
    {
        $this->assertSame(['free_months' => 1, 'collection_day' => 1], AgreementTakeOn::params(['take_on_month' => '2026-10']));
        $this->assertSame('2026-11-01', AgreementTakeOn::derive('2026-10', ['take_on_month' => '2026-10'])['billing_start']);
        $this->assertSame('2027-01-01', AgreementTakeOn::derive('2026-12')['billing_start']);
    }

    // ── (1) rows flow ──────────────────────────────────────────────────────

    public function test_the_mandate_rows_have_no_fixed_height_on_screen(): void
    {
        Carbon::setTestNow('2026-10-14 09:00:00');
        $doc = $this->send($this->owner());
        $six = "Block C, Unit 14\n15 Marine Drive\nSouthbroom\nMargate\nKwaZulu-Natal\n4275";
        $this->postJson(route('platform-esign.agreement.save', $this->token($doc)), ['rev' => 0, 'values' => ['m_address' => $six]])->assertOk();

        $html = $this->get(route('platform-esign.agreement.show', $this->token($doc)))->assertOk()->getContent();

        // the row: min-height + auto height, never a fixed height, and it wraps
        $this->assertMatchesRegularExpression('/\.agr p\.mf \{[^}]*min-height: 48px; height: auto; white-space: normal;/', $html);
        $this->assertDoesNotMatchRegularExpression('/\.agr p\.mf \{[^}]*[^-]height: 48px/', $html);
        // the address box grows with its lines
        $this->assertMatchesRegularExpression('/\.agr p\.mf \.mf-v textarea\.fld \{[^}]*height: auto;[^}]*field-sizing: content/', $html);
        $this->assertDoesNotMatchRegularExpression('/textarea\.fld \{[^}]*[^-]height: 40px/', $html);
        // and the textarea carries all six lines, with the grow hook
        $this->assertMatchesRegularExpression('/<textarea[^>]*data-autosize="1"[^>]*data-field="m_address"[^>]*>' . preg_quote("Block C, Unit 14\n15 Marine Drive", '/') . '/', $html);
        $this->assertSame(6, substr_count(htmlspecialchars_decode((string) preg_replace('/^.*<textarea[^>]*data-field="m_address"[^>]*>(.*?)<\/textarea>.*$/s', '$1', $html)), "\n") + 1);
    }

    public function test_a_signed_six_line_address_prints_every_line_in_the_pdf_pages_and_the_read_only_review(): void
    {
        Carbon::setTestNow('2026-10-14 09:00:00');
        $owner = $this->owner();
        $doc = $this->send($owner);
        $six = "Block C, Unit 14\n15 Marine Drive\nSouthbroom\nMargate\nKwaZulu-Natal\n4275";
        $this->agencySigns($doc, ['m_address' => $six]);

        $html = $this->actingAs($owner)->get(route('platform-esign.agreements.review', $doc->id))->assertOk()->getContent();
        foreach (['Block C, Unit 14', '15 Marine Drive', 'Southbroom', 'Margate', 'KwaZulu-Natal', '4275'] as $line) {
            $this->assertStringContainsString($line, $html);
        }
        $this->assertMatchesRegularExpression('/\.agr p\.mf \{[^}]*min-height: 48px; height: auto;/', $html, 'the read-only review flows too');
    }

    // ── (3) both parties signed = locked ───────────────────────────────────

    public function test_once_both_parties_have_signed_nothing_can_edit_resend_re_sign_re_date_or_void_it(): void
    {
        Carbon::setTestNow('2026-10-14 09:00:00');
        $owner = $this->owner();
        $doc = $this->send($owner);
        $token = $this->token($doc);
        $this->agencySigns($doc);
        $this->countersign($owner, $doc)->assertOk();
        $this->assertSame('completed', $doc->fresh()->status);
        $frozen = $doc->fresh();

        // the agency: no autosave, no second submit
        // (a signed agreement's old agency link is rotated on completion, so it is 404; if it were reachable the guard answers 422 — either way nothing is written)
        $this->assertContains($this->postJson(route('platform-esign.agreement.save', $token), ['rev' => $frozen->form_rev, 'values' => ['m_holder' => 'Changed']])->status(), [404, 422]);
        $this->assertContains($this->postJson(route('platform-esign.agreement.submit', $token), ['values' => $this->fullValues($doc), 'id_number' => '8001015009087', 'consent' => 1])->status(), [404, 422]);
        $this->assertNotNull($this->svc()->blockedReason($frozen, Signer::where('document_id', $doc->id)->where('role_key', 'r1')->first()));

        // the owner: no countersign again, no take-on change, no resend, no void
        $this->countersign($owner, $doc)->assertStatus(422);
        foreach ([
            fn () => $this->svc()->setTakeOn($doc, now()->addMonth()->format('Y-m'), $owner->id),
            fn () => $this->svc()->resend($doc->fresh(), $owner->id),
            fn () => app(\App\Services\PlatformEsign\EsignService::class)->void($doc->fresh(), 'changed my mind', $owner->id),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('a completed agreement accepted a change');
            } catch (\DomainException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $after = $doc->fresh();
        $this->assertSame('completed', $after->status);
        $this->assertSame($frozen->form_data, $after->form_data);
        $this->assertSame($frozen->rr_data, $after->rr_data);
    }
}
