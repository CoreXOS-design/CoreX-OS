<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Models\User;
use App\Services\SellerOutreach\SellerOutreachComposerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Seller-outreach WhatsApp number normalisation (send/link-build path).
 *
 * normalisePhone() must turn EVERY stored SA number form into valid wa.me
 * international digits (27XXXXXXXXX) — including the dropped-leading-0 bug case
 * "733981843" that previously built an invalid wa.me target — and null out
 * genuinely-bad input so no malformed link is ever shipped. Because this runs
 * at link-build time, hardening it fixes every send with no data migration.
 */
final class OutreachPhoneNormalisationTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        // AT-117 §4a — submit is gated by the agency send-window (default Mon-Fri
        // 08:00-20:00). Freeze to a fixed weekday daytime so the submit tests are
        // not flaky depending on when the suite runs.
        Carbon::setTestNow(Carbon::parse('2026-07-08 12:00:00', 'Africa/Johannesburg'));
        [$this->agencyId, $this->userId] = $this->seedAgency();
        $this->seedDefaultTemplate($this->agencyId);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{0:string, 1:?string}> */
    public static function phoneCases(): array
    {
        return [
            '10-digit leading 0'         => ['0733981843',      '27733981843'],
            '9-digit no leading 0 (bug)' => ['733981843',       '27733981843'],
            '+27 with spaces'            => ['+27 73 398 1843', '27733981843'],
            '27 with spaces no plus'     => ['27 73 398 1843',  '27733981843'],
            'already international'       => ['27733981843',     '27733981843'],
            'dashes and parens local'    => ['(073) 398-1843',  '27733981843'],
            '0027 international'          => ['0027733981843',   '27733981843'],
            'too short → null'           => ['12345',           null],
            'too long → null'            => ['073398184300',    null],
            'empty → null'               => ['',                null],
        ];
    }

    #[DataProvider('phoneCases')]
    public function test_recipient_phone_is_normalised_on_the_send_path(string $input, ?string $expected): void
    {
        $contact = $this->contactWithPhone($input);

        $ctx = app(SellerOutreachComposerService::class)->composeContext(
            agencyId: $this->agencyId,
            contact:  $contact,
            property: null,
            channel:  'whatsapp',
            agent:    User::find($this->userId),
        );

        $this->assertSame(
            $expected,
            $ctx->recipientPhone,
            "input [{$input}] should normalise to " . var_export($expected, true)
        );
    }

    /** End-to-end: the dropped-0 bug case now builds a valid wa.me/27… client_url. */
    public function test_dropped_leading_zero_number_builds_valid_wa_me_link(): void
    {
        $contact = $this->contactWithPhone('733981843');

        $resp = $this->actingAs(User::find($this->userId))
            ->postJson(route('seller-outreach.composer.submit', $contact), [
                'channel' => 'whatsapp',
                'body'    => 'Hi, demand is strong. {tracking_link} Reply STOP to opt out.',
            ]);

        $resp->assertOk();
        $this->assertStringContainsString('https://wa.me/27733981843?text=', (string) $resp->json('client_url'));

        $send = SellerOutreachSend::withoutGlobalScopes()->findOrFail($resp->json('send_id'));
        $this->assertSame('27733981843', $send->recipient_phone_snapshot);
    }

    /** A genuinely-bad number is blocked — no malformed link is recorded/sent. */
    public function test_bad_number_is_blocked_not_sent_with_broken_link(): void
    {
        $contact = $this->contactWithPhone('12345');

        $resp = $this->actingAs(User::find($this->userId))
            ->postJson(route('seller-outreach.composer.submit', $contact), [
                'channel' => 'whatsapp',
                'body'    => 'Hi. {tracking_link} Reply STOP.',
            ]);

        $resp->assertStatus(422);
        $this->assertSame(0, SellerOutreachSend::withoutGlobalScopes()->where('contact_id', $contact->id)->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function contactWithPhone(string $phone): Contact
    {
        return Contact::create([
            'agency_id'     => $this->agencyId,
            'branch_id'     => $this->agencyId,
            'first_name'    => 'Phone',
            'last_name'     => 'Case',
            'phone'         => $phone,
            'email'         => 'p-' . Str::random(8) . '@example.test',
            // structured address → composeContext builds in address-only mode.
            'street_number' => '14',
            'street_name'   => 'Marine Drive',
            'suburb'        => 'Margate',
        ]);
    }

    /** @return array{0:int,1:int} */
    private function seedAgency(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
            'phone' => '+27821110000',
        ]);
        return [$agencyId, $user->id];
    }

    private function seedDefaultTemplate(int $agencyId): void
    {
        DB::table('seller_outreach_templates')->insert([
            'agency_id'              => $agencyId,
            'name'                   => 'Initial outreach — sale',
            'channel'                => 'whatsapp',
            'subject'                => null,
            'body'                   => "Hi {seller_name}, this is {agent_name} from {agency_name} about {property_address}. {tracking_link} To stop, tap {opt_out_link} or reply STOP.",
            'description'            => 'test default',
            'is_active'              => true,
            'is_default_for_channel' => true,
            'created_at'             => now(), 'updated_at' => now(),
        ]);
    }
}
