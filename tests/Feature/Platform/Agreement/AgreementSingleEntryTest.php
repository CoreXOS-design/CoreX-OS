<?php

namespace Tests\Feature\Platform\Agreement;

use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Signer;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformEsign\Agreement\AgreementFidelity;
use App\Services\PlatformEsign\Agreement\AgreementFields;
use App\Services\PlatformEsign\Agreement\AgreementLayout;
use App\Services\PlatformEsign\Agreement\AgreementRenderer;
use App\Services\PlatformEsign\Agreement\AgreementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Single entry (spec §11.20): bank details are typed ONCE, in the Netcash mandate; section 5 of the agreement mirrors them read-only.
 * Everything else that used to be typed in both documents (address, contact cell, place, date) is typed once in Part A and mirrored on the mandate.
 */
class AgreementSingleEntryTest extends TestCase
{
    use RefreshDatabase;

    private const BANK = ['m_holder' => 'Caprivi Realty (Pty) Ltd', 'm_bank' => 'First National Bank', 'm_branch_no' => '250655', 'm_account' => '62123456789', 'm_account_type' => 'savings'];

    protected function tearDown(): void
    {
        Role::clearCache();
        Storage::disk('local')->deleteDirectory('platform-esign');
        parent::tearDown();
    }

    private function sent(): array
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();
        $owner = User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'name' => 'Johan Reichel']);
        Mail::fake();
        $doc = app(AgreementService::class)->send(['name' => 'Pat Principal', 'email' => 'pat@caprivi.test', 'take_on_month' => now()->format('Y-m')], $owner->id);

        return [$doc, Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('token')];
    }

    private function save(string $token, array $values, int $rev = 0)
    {
        return $this->postJson(route('platform-esign.agreement.save', $token), ['rev' => $rev, 'values' => $values]);
    }

    public function test_section_five_mirrors_the_mandate_bank_fields(): void
    {
        [$doc, $token] = $this->sent();
        $this->save($token, self::BANK)->assertOk();
        $d = $doc->fresh();
        $this->assertSame('Caprivi Realty (Pty) Ltd', $d->form_data['da_holder']);
        $this->assertSame('First National Bank', $d->form_data['da_bank']);
        $this->assertSame('250655', $d->form_data['da_branch_code']);
        $this->assertSame('62123456789', $d->form_data['da_account']);
        $this->assertSame('savings', $d->form_data['da_type']);
    }

    public function test_the_server_rejects_direct_recipient_input_in_the_mirrored_fields(): void
    {
        [$doc, $token] = $this->sent();
        $rev = $this->save($token, self::BANK + ['address' => '1 Beach Rd', 'billing_cell' => '0825550123', 'sig_place' => 'Margate', 'sig_date' => '2026-10-06'])->json('rev');
        $this->save($token, ['da_holder' => 'Hacker', 'da_bank' => 'Evil Bank', 'da_branch_code' => '000000', 'da_account' => '1111', 'da_type' => 'current',
            'm_place' => 'Nowhere', 'm_date' => '2000-01-01'], $rev)->assertOk();
        $d = $doc->fresh();
        foreach (['da_holder' => 'Caprivi Realty (Pty) Ltd', 'da_bank' => 'First National Bank', 'da_branch_code' => '250655', 'da_account' => '62123456789', 'da_type' => 'savings',
            'm_place' => 'Margate', 'm_date' => '2026-10-06'] as $k => $v) {
            $this->assertSame($v, $d->form_data[$k], $k);
        }
    }

    public function test_the_recipient_page_shows_them_read_only_with_tips_that_link_into_the_mandate(): void
    {
        [$doc, $token] = $this->sent();
        $this->save($token, self::BANK)->assertOk();
        $html = $this->get(route('platform-esign.agreement.show', $token))->assertOk()->getContent();

        foreach (['da_holder', 'da_bank', 'da_branch_code', 'da_account', 'da_type', 'm_place', 'm_date'] as $k) {
            $this->assertStringNotContainsString('name="' . $k . '"', $html, "$k is not typeable");
        }
        foreach (['m_holder', 'm_bank', 'm_branch_no', 'm_account', 'address', 'billing_cell', 'sig_place', 'sig_date', 'm_address', 'm_contact'] as $k) {
            $this->assertStringContainsString('name="' . $k . '"', $html, "$k is the one place it is typed");
        }
        $this->assertSame(4, substr_count($html, 'Fills in automatically from the <a href="#fld-m_'), 'one tip per section 5 row (holder, bank + branch code, account, type)');
        $this->assertSame(5, substr_count($html, 'data-mirror-of="m_'), 'the five section 5 inputs: holder, bank, branch code, account, type (shows the label)');
        $this->assertMatchesRegularExpression('/value="Caprivi Realty \(Pty\) Ltd" readonly tabindex="-1" data-derived="1" data-mirror-of="m_holder"/', $html);
        $this->assertMatchesRegularExpression('/value="Savings" readonly[^>]*data-mirror-of="m_account_type"/', $html);
        foreach (['fld-m_holder', 'fld-m_bank', 'fld-m_account', 'fld-m_account_type-current', 'fld-sig_place', 'fld-sig_date'] as $id) {
            $this->assertStringContainsString('data-goto="' . $id . '"', $html);
            $this->assertMatchesRegularExpression('/id="' . preg_quote($id, '/') . '"/', $html, "link target $id exists");
        }
        $this->assertStringContainsString('Filled in from your details above — change it here if the debit order needs a different address.', $html);
        $this->assertStringContainsString('Filled in from your details above — change it here if the debit order needs a different number.', $html);
        $this->assertStringContainsString('from the <a href="#fld-sig_date" data-goto="fld-sig_date">date</a> in section 6.', $html);
    }

    public function test_the_mandate_value_is_printed_in_section_five_on_the_review_wet_ink_and_sealed_renderings(): void
    {
        [$doc, $token] = $this->sent();
        $this->save($token, self::BANK + ['address' => '1 Beach Rd', 'billing_cell' => '0825550123', 'sig_place' => 'Margate'])->assertOk();
        $svc = app(AgreementService::class);
        $doc = Document::findOrFail($doc->id);
        $ctx = $svc->context($doc);
        $r = app(AgreementRenderer::class);
        foreach (['rr', 'preview', 'wet', 'pdf'] as $mode) {
            $a = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(implode("\n", $r->blocks($doc->wording, 'part_a', $mode, $ctx)))));
            $this->assertStringContainsString('Account holder Caprivi Realty (Pty) Ltd', $a, $mode);
            $this->assertStringContainsString('First National Bank 250655', $a, $mode);
            $this->assertStringContainsString('Account number 62123456789', $a, $mode);
            $this->assertStringContainsString('Account type Savings', $a, $mode);
            $m = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(implode("\n", $r->blocks($doc->wording, 'mandate', $mode, $ctx)))));
            $this->assertStringContainsString('Address: 1 Beach Rd', $m, $mode);
            $this->assertStringContainsString('Contact Number: 0825550123', $m, $mode);
            $this->assertStringNotContainsString('Fills in automatically', $a . $m, $mode);
        }
        $layout = app(AgreementLayout::class)->ensure($doc->wording);
        $text = preg_replace('/\s+/', ' ', AgreementFidelity::pdfText($svc->wetCopy($doc, $svc->agencySigner($doc), null), (int) $layout['total']));
        $this->assertSame(2, substr_count($text, '62123456789'), 'the account number prints in section 5 and in the mandate');
        $this->assertStringNotContainsString('Fills in automatically', $text);
    }

    public function test_the_mandate_address_and_contact_follow_part_a_while_untouched(): void
    {
        [$doc, $token] = $this->sent();
        $rev = $this->save($token, ['address' => '1 Beach Rd', 'billing_cell' => '0825550123'])->json('rev');
        $svc = app(AgreementService::class);
        $ctx = $svc->context(Document::findOrFail($doc->id));
        $this->assertSame('1 Beach Rd', $ctx['values']['m_address']);
        $this->assertSame('0825550123', $ctx['values']['m_contact']);
        $this->assertSame([], $ctx['follow_own']);
        $this->assertArrayNotHasKey('m_address', array_filter($doc->fresh()->form_data), 'a followed value is not stored as the recipient\'s own');

        // Part A changes -> the mandate follows
        $this->save($token, ['address' => '9 New Street', 'billing_cell' => '0831112222'], $rev)->assertOk();
        $ctx = $svc->context(Document::findOrFail($doc->id));
        $this->assertSame('9 New Street', $ctx['values']['m_address']);
        $this->assertSame('0831112222', $ctx['values']['m_contact']);
    }

    public function test_a_value_the_recipient_types_on_the_mandate_sticks_and_stops_following(): void
    {
        [$doc, $token] = $this->sent();
        $rev = $this->save($token, ['address' => '1 Beach Rd', 'billing_cell' => '0825550123'])->json('rev');
        $rev = $this->save($token, ['m_address' => 'PO Box 9, Margate', 'm_contact' => '0397770000'], $rev)->assertOk()->json('rev');
        $this->assertSame('PO Box 9, Margate', $doc->fresh()->form_data['m_address'], 'the server accepts the recipient\'s own value');
        $this->assertSame('0397770000', $doc->fresh()->form_data['m_contact']);

        $this->save($token, ['address' => '9 New Street', 'billing_cell' => '0831112222'], $rev)->assertOk();
        $svc = app(AgreementService::class);
        $ctx = $svc->context(Document::findOrFail($doc->id));
        $this->assertSame('PO Box 9, Margate', $ctx['values']['m_address'], 'no longer follows Part A');
        $this->assertSame('0397770000', $ctx['values']['m_contact']);
        $this->assertEqualsCanonicalizing(['m_address', 'm_contact'], $ctx['follow_own']);

        $html = $this->get(route('platform-esign.agreement.show', $token))->getContent();
        $this->assertMatchesRegularExpression('/name="m_address"[^>]*data-follow="address" data-own="1"/', $html);
        $this->assertMatchesRegularExpression('/name="m_contact"[^>]*data-follow="billing_cell" data-own="1"/', $html);
        $this->assertStringNotContainsString('name="m_address" id="fld-m_address" data-field="m_address" data-follow="address" readonly', $html);

        // both PDFs / the review print whatever the mandate field holds
        $r = app(AgreementRenderer::class);
        foreach (['wet', 'pdf', 'rr', 'preview'] as $mode) {
            $m = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(implode("\n", $r->blocks($doc->wording, 'mandate', $mode, $ctx)))));
            $this->assertStringContainsString('Address: PO Box 9, Margate', $m, $mode);
            $this->assertStringContainsString('Contact Number: 0397770000', $m, $mode);
            $this->assertStringNotContainsString('Filled in from your details above', $m, $mode);
        }
    }

    public function test_clearing_the_mandate_field_makes_it_follow_part_a_again(): void
    {
        [$doc, $token] = $this->sent();
        $rev = $this->save($token, ['address' => '1 Beach Rd', 'billing_cell' => '0825550123', 'm_address' => 'PO Box 9', 'm_contact' => '0397770000'])->json('rev');
        $rev = $this->save($token, ['m_address' => '', 'm_contact' => ''], $rev)->assertOk()->json('rev');
        $svc = app(AgreementService::class);
        $ctx = $svc->context(Document::findOrFail($doc->id));
        $this->assertSame('1 Beach Rd', $ctx['values']['m_address']);
        $this->assertSame('0825550123', $ctx['values']['m_contact']);
        $this->assertSame([], $ctx['follow_own']);

        $this->save($token, ['address' => '9 New Street'], $rev)->assertOk();
        $this->assertSame('9 New Street', $svc->context(Document::findOrFail($doc->id))['values']['m_address'], 'follows again');
    }

    public function test_a_value_typed_equal_to_part_a_keeps_following(): void
    {
        [$doc, $token] = $this->sent();
        $rev = $this->save($token, ['address' => '1 Beach Rd', 'm_address' => '1 Beach Rd'])->json('rev');
        $this->assertSame('', trim((string) ($doc->fresh()->form_data['m_address'] ?? '')));
        $this->save($token, ['address' => '2 Other Rd'], $rev)->assertOk();
        $this->assertSame('2 Other Rd', app(AgreementService::class)->context(Document::findOrFail($doc->id))['values']['m_address']);
    }

    public function test_the_signed_record_holds_the_effective_mandate_address_and_contact(): void
    {
        [$doc, $token] = $this->sent();
        $svc = app(AgreementService::class);
        $signer = $svc->agencySigner($doc);
        $sample = \App\Services\PlatformEsign\Agreement\AgreementSample::ctx($doc->wording);
        $vals = $sample['values'] + ['sigA' => $sample['sigs']['agency'], 'sigM' => $sample['sigs']['mandate']];
        unset($vals['m_address'], $vals['m_contact']);
        $svc->save($doc, $signer, $vals, (int) $doc->fresh()->form_rev);
        $svc->setInitials($doc->fresh(), $signer, 'jw');
        foreach (range(1, $svc->totalPages($doc)) as $p) {
            $svc->initialPage($doc->fresh(), $signer->fresh(), $p, null);
        }
        $this->assertSame([], $svc->submit($doc->fresh(), $signer->fresh(), $vals, ['id_number' => '8001015009087', 'consent' => 1], null, 't'));
        $d = $doc->fresh();
        $this->assertSame($sample['values']['address'], $d->form_data['m_address'], 'signed with the followed address');
        $this->assertSame($sample['values']['billing_cell'], $d->form_data['m_contact']);
    }

    public function test_submit_validates_the_mandate_fields_once_and_never_the_mirrored_copy(): void
    {
        $errors = AgreementFields::validateRecipient(['da_account' => '', 'm_account' => ''], \App\Services\PlatformEsign\Agreement\AgreementPricing::DEFAULT_RATES, array_keys(AgreementFields::MIRRORS));
        $this->assertArrayHasKey('m_account', $errors);
        $this->assertArrayNotHasKey('da_account', $errors);
        $this->assertArrayNotHasKey('da_type', $errors);
        $this->assertArrayHasKey('da_account', AgreementFields::validateRecipient(['da_account' => ''], \App\Services\PlatformEsign\Agreement\AgreementPricing::DEFAULT_RATES), 'legacy agreements still validate section 5');
    }

    public function test_an_agreement_sent_before_this_change_keeps_both_places_typeable(): void
    {
        [$doc, $token] = $this->sent();
        $rr = $doc->rr_data;
        unset($rr['single_entry']);
        $doc->update(['rr_data' => $rr]);
        $this->save($token, ['da_holder' => 'Typed in section 5', 'm_holder' => 'Typed on the mandate'])->assertOk();
        $d = $doc->fresh();
        $this->assertSame('Typed in section 5', $d->form_data['da_holder']);
        $this->assertSame('Typed on the mandate', $d->form_data['m_holder']);
        $html = $this->get(route('platform-esign.agreement.show', $token))->getContent();
        $this->assertStringContainsString('name="da_holder"', $html);
        $this->assertStringNotContainsString('Fills in automatically from the <a href', $html);
    }
}
