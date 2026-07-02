<?php

namespace Tests\Feature\Communications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Communications\CommunicationWaDevice;
use App\Models\User;
use App\Services\Communications\WahaSessionClient;
use App\Services\Communications\WahaUnavailableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-156 — WhatsApp Capture Linking (My Portal → Tools).
 *
 * Exercises the state machine with a faked WAHA client (no live WAHA in tests):
 * not_linked / awaiting_scan / linked(+device row) / unlink / disabled /
 * waha_down / qr proxy. AT-153 block reuses WaDeviceController's guard.
 */
class WhatsAppLinkTest extends TestCase
{
    use RefreshDatabase;

    private function agent(array $agencyAttrs = []): User
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()] + $agencyAttrs);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);

        return User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => 1,
        ]);
    }

    /** Bind a fake WAHA client with canned behaviour. */
    private function fakeWaha(string $status, ?string $qr = 'PNGBYTES', bool $down = false, ?array $me = null): void
    {
        $fake = new class($status, $qr, $down, $me) extends WahaSessionClient {
            public function __construct(public string $st, public ?string $q, public bool $down, public ?array $me) {}
            public function status(string $session): array
            {
                if ($this->down) throw new WahaUnavailableException('down');
                return ['exists' => $this->st !== 'NO_SESSION', 'status' => $this->st, 'me' => $this->me];
            }
            public function ensureStarted(string $s, string $u, string $sec): array { return $this->status($s); }
            public function restart(string $s, string $u, string $sec): array { return $this->status($s); }
            public function qrPng(string $session): ?string { if ($this->down) throw new WahaUnavailableException('down'); return $this->q; }
            public function remove(string $session): void {}
        };
        $this->app->instance(WahaSessionClient::class, $fake);
    }

    public function test_no_session_reports_not_linked(): void
    {
        $this->fakeWaha('NO_SESSION');
        $this->actingAs($this->agent())
            ->getJson(route('communications.wa-link.status'))
            ->assertOk()->assertJsonPath('state', 'not_linked');
    }

    public function test_link_moves_to_awaiting_scan(): void
    {
        $this->fakeWaha('SCAN_QR_CODE');
        $this->actingAs($this->agent())
            ->postJson(route('communications.wa-link.link'))
            ->assertOk()->assertJsonPath('state', 'awaiting_scan');
    }

    public function test_working_session_reports_linked_and_creates_device_row(): void
    {
        $this->fakeWaha('WORKING', me: ['id' => '27821234567@c.us', 'pushName' => 'Retha']);
        $agent = $this->agent();

        $this->actingAs($agent)
            ->getJson(route('communications.wa-link.status'))
            ->assertOk()->assertJsonPath('state', 'linked')
            ->assertJsonPath('device.number', '27821234567');

        $this->assertDatabaseHas('communication_wa_devices', [
            'user_id' => $agent->id, 'agency_id' => $agent->agency_id,
            'wa_number' => '27821234567', 'active' => 1,
        ]);
        // idempotent — a second poll does not duplicate the row
        $this->actingAs($agent)->getJson(route('communications.wa-link.status'))->assertOk();
        $this->assertSame(1, CommunicationWaDevice::where('user_id', $agent->id)->count());
    }

    public function test_unlink_soft_deletes_device(): void
    {
        $this->fakeWaha('WORKING', me: ['id' => '27821234567@c.us']);
        $agent = $this->agent();
        $this->actingAs($agent)->getJson(route('communications.wa-link.status'))->assertOk();
        $id = CommunicationWaDevice::where('user_id', $agent->id)->value('id');

        $this->actingAs($agent)->postJson(route('communications.wa-link.unlink'))
            ->assertOk()->assertJsonPath('state', 'not_linked');

        $this->assertSoftDeleted('communication_wa_devices', ['id' => $id]);
    }

    public function test_agency_toggle_off_reports_disabled(): void
    {
        $this->fakeWaha('NO_SESSION');
        $agent = $this->agent(['wa_self_link_enabled' => false]);
        $this->actingAs($agent)
            ->getJson(route('communications.wa-link.status'))
            ->assertOk()->assertJsonPath('state', 'disabled');
    }

    public function test_waha_down_degrades_gracefully(): void
    {
        $this->fakeWaha('NO_SESSION', down: true);
        $this->actingAs($this->agent())
            ->getJson(route('communications.wa-link.status'))
            ->assertOk()->assertJsonPath('state', 'waha_down');
    }

    public function test_qr_proxies_png_bytes(): void
    {
        $this->fakeWaha('SCAN_QR_CODE', qr: 'THE-PNG-BYTES');
        $res = $this->actingAs($this->agent())->get(route('communications.wa-link.qr'));
        $res->assertOk();
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertSame('THE-PNG-BYTES', $res->getContent());
    }

    public function test_failed_session_reports_failed(): void
    {
        $this->fakeWaha('FAILED');
        $this->actingAs($this->agent())
            ->getJson(route('communications.wa-link.status'))
            ->assertOk()->assertJsonPath('state', 'failed');
    }
}
