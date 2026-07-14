<?php

declare(strict_types=1);

namespace Tests\Feature\DealV2;

use App\Mail\OtpMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\DealV2\DealDocumentDistribution;
use App\Models\DealV2\DealV2;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-264 — ONE secure link + ONE OTP unlocks a recipient's WHOLE distribution
 * pack (a single doc is a pack of one → same flow). Exercises the public pack
 * routes end-to-end; the per-document routes stay untouched (back-compat).
 */
final class SecureDocumentPackTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private DealV2 $deal;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->agency = Agency::create(['name' => 'Pack Test', 'slug' => 'pack-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id]);

        $this->deal = DealV2::create([
            'reference'         => 'DR2-PACK-1',
            'deal_type'         => 'cash',
            'listing_agent_id'  => $this->user->id,
            'purchase_price'    => 1000000,
            'commission_amount' => 50000,
            'commission_vat'    => 7500,
            'offer_date'        => now()->toDateString(),
            'branch_id'         => $this->branch->id,
            'agency_id'         => $this->agency->id,
            'created_by_id'     => $this->user->id,
        ]);
    }

    private function makeDoc(string $name): Document
    {
        $path = 'docs/' . Str::random(8) . '-' . $name;
        Storage::disk('local')->put($path, 'PDF-BYTES-' . $name);
        return Document::create([
            'agency_id'     => $this->agency->id,
            'original_name' => $name,
            'storage_path'  => $path,
            'disk'          => 'local',
        ]);
    }

    /** @return \Illuminate\Support\Collection<int,DealDocumentDistribution> */
    private function sendPack(string $groupKey, array $names, string $email = 'buyer@example.com', bool $otp = true)
    {
        return collect($names)->map(fn (string $n) => DealDocumentDistribution::create([
            'agency_id'      => $this->agency->id,
            'deal_id'        => $this->deal->id,
            'document_id'    => $this->makeDoc($n)->id,
            'party_role'     => 'buyer',
            'recipient_email' => $email,
            'delivery_mode'  => DealDocumentDistribution::MODE_SECURE_LINK,
            'channel'        => DealDocumentDistribution::CHANNEL_EMAIL,
            'group_key'      => $groupKey,
            'part_no'        => 1,
            'part_of'        => 1,
            'secure_token'   => Str::random(40),
            'otp_required'   => $otp,
            'status'         => DealDocumentDistribution::STATUS_SENT,
            'sent_by_id'     => $this->user->id,
        ]))->values();
    }

    public function test_one_otp_unlocks_every_document_in_the_pack(): void
    {
        Mail::fake();
        $gk = 'grp-' . Str::random(24);
        $dists = $this->sendPack($gk, ['Offer.pdf', 'FICA.pdf', 'Bond.pdf']);

        // Pack landing renders with the PIN gate (not yet verified).
        $this->get(route('deals-v2.secure-doc.pack', $gk))->assertOk()->assertSee('PIN');

        // Request ONE pack PIN; capture the plaintext code from the email.
        $this->post(route('deals-v2.secure-doc.pack.otp', $gk))->assertRedirect();
        $code = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$code) { $code = $m->code; return true; });
        $this->assertNotNull($code, 'a PIN email must be sent');

        // Before verifying, a download is refused (redirects to the gate).
        $this->get(route('deals-v2.secure-doc.pack.download', ['groupKey' => $gk, 'distribution' => $dists[0]->id]))
            ->assertRedirect(route('deals-v2.secure-doc.pack', $gk));

        // Wrong PIN does not verify.
        $this->post(route('deals-v2.secure-doc.pack.verify', $gk), ['code' => '000000'])->assertRedirect();
        $this->get(route('deals-v2.secure-doc.pack.download', ['groupKey' => $gk, 'distribution' => $dists[0]->id]))
            ->assertRedirect(route('deals-v2.secure-doc.pack', $gk));

        // Correct PIN — ONE verification.
        $this->post(route('deals-v2.secure-doc.pack.verify', $gk), ['code' => $code])->assertRedirect();

        // ALL THREE documents now download under that single verification — no re-PIN.
        foreach ($dists as $dist) {
            $this->get(route('deals-v2.secure-doc.pack.download', ['groupKey' => $gk, 'distribution' => $dist->id]))
                ->assertOk();
        }
    }

    public function test_a_single_document_send_uses_the_same_pack_flow(): void
    {
        Mail::fake();
        $gk = 'grp-' . Str::random(24);
        $dists = $this->sendPack($gk, ['Solo.pdf']);

        $this->post(route('deals-v2.secure-doc.pack.otp', $gk))->assertRedirect();
        $code = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$code) { $code = $m->code; return true; });

        $this->post(route('deals-v2.secure-doc.pack.verify', $gk), ['code' => $code])->assertRedirect();
        $this->get(route('deals-v2.secure-doc.pack.download', ['groupKey' => $gk, 'distribution' => $dists[0]->id]))
            ->assertOk();
    }

    public function test_a_distribution_from_another_group_cannot_be_downloaded(): void
    {
        Mail::fake();
        $gk = 'grp-' . Str::random(24);
        $this->sendPack($gk, ['A.pdf']);
        $foreign = $this->sendPack('grp-' . Str::random(24), ['Foreign.pdf'])->first();

        // Verify the first pack.
        $this->post(route('deals-v2.secure-doc.pack.otp', $gk))->assertRedirect();
        $code = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$code) { $code = $m->code; return true; });
        $this->post(route('deals-v2.secure-doc.pack.verify', $gk), ['code' => $code])->assertRedirect();

        // A foreign distribution id under this group's URL is rejected (410).
        $this->get(route('deals-v2.secure-doc.pack.download', ['groupKey' => $gk, 'distribution' => $foreign->id]))
            ->assertStatus(410);
    }

    public function test_a_fully_revoked_pack_is_unavailable(): void
    {
        $gk = 'grp-' . Str::random(24);
        $this->sendPack($gk, ['A.pdf', 'B.pdf'])
            ->each(fn (DealDocumentDistribution $d) => $d->update(['status' => DealDocumentDistribution::STATUS_REVOKED]));

        $this->get(route('deals-v2.secure-doc.pack', $gk))->assertStatus(410);
    }
}
