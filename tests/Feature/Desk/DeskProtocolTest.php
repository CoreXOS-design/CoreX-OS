<?php

declare(strict_types=1);

namespace Tests\Feature\Desk;

use App\Events\Desk\DeskBroadcast;
use App\Models\DeskSession;
use App\Services\Desk\DeskSessionService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

/**
 * CoreX Desk - the channel/event protocol (spec .ai/specs/corex-desk.md section 7.6). The phase-3 browser extension speaks this
 * protocol unchanged, so the event names, payload keys and the channel name are a contract: this test pins the server side of it,
 * and checks that the spec document and both browser clients still use the same names.
 */
final class DeskProtocolTest extends DeskTestCase
{
    private DeskSessionService $desk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->desk = app(DeskSessionService::class);
        Event::fake([DeskBroadcast::class]);
    }

    /** @return array<int, DeskBroadcast> */
    private function sent(): array
    {
        return Event::dispatched(DeskBroadcast::class)->map(fn ($args) => $args[0])->values()->all();
    }

    public function test_every_server_event_goes_to_the_sessions_private_channel_with_the_documented_payload(): void
    {
        $agent = $this->agentIn($this->agencyA);
        $support = $this->owner();
        [$session, $code] = $this->desk->start($agent);
        $this->desk->join($support, $code);
        $this->desk->highlight($session, $support, '[data-tour="x"]', 'Note');
        $this->desk->clearHighlight($session, $support);
        $this->desk->pointer($session, $support, true);
        $this->desk->end($session, DeskSession::ENDED_BY_SUPPORT, $support);

        $byName = collect($this->sent())->keyBy('name');
        $this->assertSame(['desk.joined', 'desk.highlight', 'desk.highlight_cleared', 'desk.pointer_state', 'desk.ended'], $byName->keys()->all());

        $expected = [
            'desk.joined' => ['support_name', 'joined_at'],
            'desk.highlight' => ['selector', 'note'],
            'desk.highlight_cleared' => [],
            'desk.pointer_state' => ['on'],
            'desk.ended' => ['by'],
        ];
        foreach ($expected as $name => $keys) {
            $event = $byName[$name];
            $this->assertSame($keys, array_keys($event->broadcastWith()), "{$name} payload keys");
            $this->assertSame($name, $event->broadcastAs());
            $channels = $event->broadcastOn();
            $this->assertCount(1, $channels);
            $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
            $this->assertSame('private-desk.session.' . $session->id, $channels[0]->name, "{$name} channel");
        }
        $this->assertSame(['selector' => '[data-tour="x"]', 'note' => 'Note'], $byName['desk.highlight']->broadcastWith());
        $this->assertSame(['on' => true], $byName['desk.pointer_state']->broadcastWith());
        $this->assertSame(['by' => 'support'], $byName['desk.ended']->broadcastWith());
    }

    public function test_no_event_carries_anything_from_the_agents_screen_or_the_code(): void
    {
        $agent = $this->agentIn($this->agencyA);
        [$session, $code] = $this->desk->start($agent);
        $this->desk->join($this->owner(), $code);
        $this->desk->end($session, DeskSession::ENDED_BY_AGENT, $agent);

        foreach ($this->sent() as $event) {
            $json = json_encode($event->broadcastWith());
            $this->assertStringNotContainsString($code, $json, 'the code never travels on the channel');
            $this->assertArrayNotHasKey('html', $event->broadcastWith());
        }
    }

    public function test_the_event_ends_reasons_are_the_documented_set(): void
    {
        $agent = $this->agentIn($this->agencyA);
        $reasons = [];
        foreach ([DeskSession::ENDED_BY_AGENT, DeskSession::ENDED_BY_SUPPORT, DeskSession::ENDED_BY_LOGOUT, DeskSession::ENDED_BY_TIMEOUT, DeskSession::ENDED_BY_EXPIRED, DeskSession::ENDED_BY_DISABLED, DeskSession::ENDED_BY_REPLACED] as $by) {
            [$s] = $this->desk->start($agent);
            $this->desk->end($s, $by);
            $reasons[] = $s->fresh()->ended_by;
        }
        $this->assertSame(['agent', 'support', 'logout', 'timeout', 'expired', 'disabled', 'replaced'], $reasons);
    }

    public function test_the_spec_documents_every_event_whisper_and_endpoint_the_clients_use(): void
    {
        $spec = (string) file_get_contents(base_path('.ai/specs/corex-desk.md'));

        foreach (['desk.joined', 'desk.highlight', 'desk.highlight_cleared', 'desk.pointer_state', 'desk.ended',
            'client-frame', 'client-resync', 'client-pointer', 'private-desk.session.{id}', 'protocol version', 'gzip', 'data-desk-mask'] as $term) {
            $this->assertStringContainsString($term, $spec, "the protocol section must mention {$term}");
        }
    }

    public function test_the_browser_clients_use_the_names_in_the_protocol(): void
    {
        $agent = (string) file_get_contents(resource_path('js/desk/agent.js'));
        $console = (string) file_get_contents(resource_path('js/desk/console.js'));

        // Agent: listens for the server events, answers resync, receives the pointer, sends frames, on the session's private channel.
        foreach (["'.desk.joined'", "'.desk.ended'", "'.desk.highlight'", "'.desk.highlight_cleared'", "'.desk.pointer_state'",
            "listenForWhisper('pointer'", "listenForWhisper('resync'", "whisper('frame'", "'desk.session.'"] as $needle) {
            $this->assertStringContainsString($needle, $agent, "agent.js must use {$needle}");
        }
        // Support console: receives frames, asks for a resync, sends the pointer, same channel name.
        foreach (["listenForWhisper('frame'", "whisper('resync'", "whisper('pointer'", "'.desk.ended'", "'desk.session.'"] as $needle) {
            $this->assertStringContainsString($needle, $console, "console.js must use {$needle}");
        }
        // Both ends use the shared chunk size / codec.
        $frames = (string) file_get_contents(resource_path('js/desk/frames.js'));
        $this->assertStringContainsString('export const CHUNK = 7000', $frames);
    }

    public function test_masking_contract_the_agent_client_masks_passwords_and_desk_mask_fields_and_blocks_its_own_ui(): void
    {
        $agent = (string) file_get_contents(resource_path('js/desk/agent.js'));

        $this->assertStringContainsString("el.type === 'password'", $agent);
        $this->assertStringContainsString("[data-desk-mask]", $agent);
        $this->assertStringContainsString("blockClass: 'rr-block'", $agent);
        $this->assertStringContainsString('maskTextSelector', $agent);

        // Desk's own UI is never part of what support sees.
        $partial = (string) file_get_contents(resource_path('views/layouts/partials/desk-client.blade.php'));
        foreach (['corex-desk-bar', 'corex-desk-modal', 'corex-desk-pointer'] as $id) {
            $this->assertMatchesRegularExpression('/id="' . $id . '" class="rr-block"/', $partial, "{$id} must carry rr-block");
        }
    }

    public function test_bank_account_inputs_carry_the_mask_marker(): void
    {
        foreach ([
            'views/corex/contacts/show.blade.php' => ['bank_account_number', 'bank_branch_code'],
            'views/payroll/employees/show.blade.php' => ['account_number', 'branch_code'],
            'views/payroll/employees/create.blade.php' => ['account_number', 'branch_code'],
            'views/staff-take-on/wizard/_step_tax_banking.blade.php' => ['account_number', 'branch_code'],
        ] as $file => $names) {
            $html = (string) file_get_contents(resource_path($file));
            foreach ($names as $name) {
                $this->assertMatchesRegularExpression('/name="' . $name . '" data-desk-mask/', $html, "{$file}: {$name}");
            }
        }
        $this->assertStringContainsString("str_contains(\$name, 'bank_account')) data-desk-mask", (string) file_get_contents(resource_path('views/components/rental-application-field.blade.php')));
    }
}
