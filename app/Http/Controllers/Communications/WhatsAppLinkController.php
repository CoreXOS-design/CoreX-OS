<?php

namespace App\Http\Controllers\Communications;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Communications\AgentCaptureConsent;
use App\Models\Communications\CommunicationWaDevice;
use App\Services\Communications\WahaSessionClient;
use App\Services\Communications\WahaUnavailableException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AT-156 — WhatsApp Capture Linking (My Portal → Tools).
 *
 * An agency agent links their own WhatsApp capture device in-app: the server
 * drives the WAHA session and proxies the pairing QR (the WAHA API key never
 * leaves the server). On WORKING we file the `communication_wa_devices` row for
 * the agent. Replaces the interim token-URL QR page.
 *
 * Spec: .ai/specs/whatsapp-link.md. Gated by `access_communication` +
 * `agency.required`. AT-153 enforced: super-admin / agency-less cannot link.
 */
class WhatsAppLinkController extends Controller
{
    public function __construct(private WahaSessionClient $waha)
    {
    }

    /** Current state — the single source the Tools-tab section polls. */
    public function status(Request $request)
    {
        [$agency, $blocked] = $this->context($request);
        if ($blocked) {
            return response()->json(['state' => $blocked, 'consent' => $this->consentSummary($request)]);
        }

        $session = $this->resolveSessionName($request, $agency);

        try {
            $waha = $this->waha->status($session);
        } catch (WahaUnavailableException $e) {
            Log::warning('AT-156 WAHA unavailable on status', ['session' => $session, 'err' => $e->getMessage()]);
            return response()->json(['state' => 'waha_down', 'session' => $session] + $this->deviceEnvelope($request, $session));
        }

        $state = $this->mapState($waha['status']);

        if ($state === 'linked') {
            $this->ensureDeviceRow($request, $agency, $session, $waha['me'] ?? null);
        }

        return response()->json([
            'state'       => $state,
            'session'     => $session,
            'waha_status' => $waha['status'],
        ] + $this->deviceEnvelope($request, $session) + ['consent' => $this->consentSummary($request)]);
    }

    /** Server-side QR proxy — streams the PNG; key stays server-side. */
    public function qr(Request $request)
    {
        [$agency, $blocked] = $this->context($request);
        if ($blocked) {
            abort(403, 'WhatsApp linking is not available for this account.');
        }
        $session = $this->resolveSessionName($request, $agency);

        try {
            $png = $this->waha->qrPng($session);
        } catch (WahaUnavailableException $e) {
            return response('WhatsApp service unavailable', 503);
        }

        if ($png === null) {
            return response()->noContent(); // 204 — not currently pairable
        }

        return response($png, 200, [
            'Content-Type'           => 'image/png',
            'Cache-Control'          => 'private, max-age=0, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Start/create the agent's session (idempotent — double-clicks absorbed). */
    public function link(Request $request)
    {
        [$agency, $blocked] = $this->context($request);
        if ($blocked === 'blocked') {
            return response()->json(['state' => 'blocked', 'message' => $this->blockedMessage()], 422);
        }
        if ($blocked === 'disabled') {
            return response()->json(['state' => 'disabled'], 422);
        }

        $session = $this->resolveSessionName($request, $agency);

        try {
            $this->waha->ensureStarted($session, $this->webhookUrl(), $this->secret());
        } catch (WahaUnavailableException $e) {
            Log::warning('AT-156 WAHA unavailable on link', ['session' => $session, 'err' => $e->getMessage()]);
            return response()->json(['state' => 'waha_down'], 200);
        }

        return $this->status($request);
    }

    /** Restart a FAILED session from the UI. */
    public function restart(Request $request)
    {
        [$agency, $blocked] = $this->context($request);
        if ($blocked) {
            return response()->json(['state' => $blocked], 422);
        }
        $session = $this->resolveSessionName($request, $agency);

        try {
            $this->waha->restart($session, $this->webhookUrl(), $this->secret());
        } catch (WahaUnavailableException $e) {
            return response()->json(['state' => 'waha_down'], 200);
        }

        return $this->status($request);
    }

    /** Unlink — soft-delete the device (recoverable) + WAHA logout + audit. */
    public function unlink(Request $request)
    {
        [$agency, $blocked] = $this->context($request);
        if ($blocked === 'blocked') {
            return response()->json(['state' => 'blocked'], 422);
        }
        $session = $this->resolveSessionName($request, $agency);

        $devices = CommunicationWaDevice::where('user_id', $request->user()->id)
            ->where('waha_session', $session)
            ->get();

        foreach ($devices as $device) {
            $device->forceFill(['active' => false])->save();
            $device->delete(); // soft
            Log::info('AT-156 wa-device unlinked', [
                'device_id' => $device->id,
                'user_id'   => $request->user()->id,
                'agency_id' => $device->agency_id,
                'session'   => $session,
                'ip'        => $request->ip(),
            ]);
        }

        $this->waha->remove($session); // best-effort — unlink proceeds regardless

        return response()->json(['state' => 'not_linked'] + $this->deviceEnvelope($request, $session));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @return array{0:?Agency,1:?string} [agency, blockedState]
     *   blockedState = 'blocked' (AT-153) | 'disabled' (agency toggle) | null
     */
    private function context(Request $request): array
    {
        $user = $request->user();

        // AT-153 — capture ownership must be a real agency agent (mirror WaDeviceController).
        if ($user->isOwnerRole() || ! $user->effectiveAgencyId()) {
            return [null, 'blocked'];
        }

        $agency = Agency::find($user->effectiveAgencyId());
        if ($agency && $agency->wa_self_link_enabled === false) {
            return [$agency, 'disabled'];
        }

        return [$agency, null];
    }

    /**
     * The session name to use for WAHA calls for THIS user.
     *
     * STORED-NAME AUTHORITATIVE (AT-158 fix, 2026-07-06): an already-linked
     * device keeps its stored `waha_session` — WAHA posts inbound under the name
     * the session was started with, and ingest maps by that stored name
     * (WaSessionWebhookController → CommunicationWaDevice::forWahaSession). So a
     * linked user's page/status/restart/unlink always resolve to the SAME stored
     * name — it keeps capturing, zero re-links, even after the generation format
     * changes. Only a BRAND-NEW link (no active device) gets the env-safe
     * generated name below.
     */
    private function resolveSessionName(Request $request, ?Agency $agency): string
    {
        $stored = CommunicationWaDevice::where('user_id', $request->user()->id)
            ->where('active', true)
            ->whereNotNull('waha_session')
            ->orderByDesc('id')
            ->value('waha_session');

        return $stored ?: $this->generateSessionName($request, $agency);
    }

    /**
     * Generate a NEW env-safe session name. Format:
     *   {env}-{agency-prefix}-agent-{userId}   e.g. production-agency1-agent-22
     *
     * BUG-CLASS KILL: the environment marker is derived from CODE/CONFIG
     * (WAHA_SESSION_ENV, defaulting to APP_ENV) — NEVER from a DB field. Staging
     * is a clone of the live DB, so an agency's `wa_session_prefix` (DB) is
     * copied across environments and cannot distinguish them; APP_ENV differs per
     * environment (.env is not cloned), so a fresh link on staging can never
     * generate a name that collides with live's — even immediately after a
     * staging refresh from a live DB copy. The agency/prefix concept is retained
     * as the middle segment.
     */
    private function generateSessionName(Request $request, ?Agency $agency): string
    {
        $prefix = $agency && $agency->wa_session_prefix
            ? $agency->wa_session_prefix
            : 'agency' . ($agency->id ?? 0);
        $prefix = Str::slug($prefix) ?: 'agency';

        return $this->envMarker() . '-' . $prefix . '-agent-' . $request->user()->id;
    }

    /** Environment marker for session names — code/config-derived, never DB. */
    private function envMarker(): string
    {
        $marker = (string) config('communications.waha.session_env', config('app.env', 'app'));

        return Str::slug($marker) ?: 'app';
    }

    private function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . '/communications/wa/webhook';
    }

    private function secret(): string
    {
        return (string) config('communications.waha.webhook_secret', '');
    }

    /** WAHA status → app state. */
    private function mapState(string $wahaStatus): string
    {
        return match (strtoupper($wahaStatus)) {
            'WORKING'                    => 'linked',
            'SCAN_QR_CODE', 'STARTING'   => 'awaiting_scan',
            'FAILED'                     => 'failed',
            default                      => 'not_linked', // NO_SESSION, STOPPED, UNKNOWN
        };
    }

    /** Ensure a device row for the linked agent (idempotent). */
    private function ensureDeviceRow(Request $request, ?Agency $agency, string $session, ?array $me): void
    {
        $user = $request->user();
        $number = null;
        if ($me) {
            $raw = (string) ($me['id'] ?? '');
            $number = $raw !== '' ? preg_replace('/@.*$/', '', $raw) : ($me['pushName'] ?? null);
        }

        $device = CommunicationWaDevice::where('user_id', $user->id)
            ->where('waha_session', $session)
            ->first();

        if ($device) {
            $device->forceFill(['active' => true, 'last_seen_at' => now()]);
            if ($number) {
                $device->wa_number = $number;
            }
            $device->save();
            return;
        }

        CommunicationWaDevice::create([
            'agency_id'    => $user->effectiveAgencyId(),
            'user_id'      => $user->id,
            'wa_number'    => $number,
            'waha_session' => $session,
            'active'       => true,
            'last_seen_at' => now(),
        ]);
    }

    /** Device summary for the linked state. */
    private function deviceEnvelope(Request $request, string $session): array
    {
        $device = CommunicationWaDevice::where('user_id', $request->user()->id)
            ->where('waha_session', $session)
            ->first();

        return ['device' => $device ? [
            'number'       => $device->wa_number,
            'linked_since' => optional($device->created_at)->toDayDateTimeString(),
            'last_seen'    => optional($device->last_seen_at)->diffForHumans(),
        ] : null];
    }

    /** Per-agent AT-136 consent rollup (per-contact rows → counts). */
    private function consentSummary(Request $request): array
    {
        $rows = AgentCaptureConsent::forAgent($request->user()->id)->get();

        return [
            'opted_in'  => $rows->where('status', AgentCaptureConsent::STATUS_OPTED_IN)->count(),
            'pending'   => $rows->where('status', AgentCaptureConsent::STATUS_PENDING)->count(),
            'opted_out' => $rows->where('status', AgentCaptureConsent::STATUS_OPTED_OUT)->count(),
        ];
    }

    private function blockedMessage(): string
    {
        return 'WhatsApp capture linking is only available to agency agents. '
             . 'A platform/owner account cannot own captured threads — sign in as the agency agent whose WhatsApp will be captured.';
    }
}
