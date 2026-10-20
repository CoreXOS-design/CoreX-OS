<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\AgencyApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Manages per-agency website API keys + the master "website is live" switch,
 * surfaced in the Admin → Agencies → API Access panel.
 *
 * One agency, many keys (one per website). The full secret is shown exactly
 * once on create/regenerate (flashed), never stored in plaintext.
 *
 * Spec: .ai/specs/agency-public-api.md §3.5, §7.1, §8
 */
class AgencyApiKeyController extends Controller
{
    /** Generate a new key (= a new website) for the agency. */
    public function store(Request $request, Agency $agency): RedirectResponse
    {
        $this->authorizeAgency($agency);
        $data = $this->validatePayload($request, $agency);

        $minted = AgencyApiKey::mintSecret();

        $key = new AgencyApiKey([
            'name'               => $data['name'],
            'key_prefix'         => $minted['prefix'],
            'secret_hash'        => $minted['hash'],
            'scopes'             => $data['scopes'] ?? [],
            'webhook_url'        => $data['webhook_url'] ?? null,
            'rate_limit_per_min' => $data['rate_limit_per_min'] ?? 120,
            'expires_at'         => $data['expires_at'] ?? null,
            'created_by'         => $request->user()?->id,
        ]);
        $key->agency_id = $agency->id;

        // Mint a webhook signing secret if the website wants to receive events.
        if (in_array(AgencyApiKey::SCOPE_WEBHOOKS_RECEIVE, $key->scopes ?? [], true)) {
            $key->webhook_secret = bin2hex(random_bytes(24));
        }
        $key->save();

        return $this->backToPanel($agency)
            ->with('new_api_key', ['id' => $key->id, 'name' => $key->name, 'plaintext' => $minted['plaintext']])
            ->with('success', "Website API key “{$key->name}” created. Copy the secret now — it won't be shown again.");
    }

    /** Edit a key's name / scopes / webhook URL / rate limit / expiry. */
    public function update(Request $request, Agency $agency, AgencyApiKey $apiKey): RedirectResponse
    {
        $this->authorizeAgency($agency);
        $this->ensureBelongs($agency, $apiKey);
        $data = $this->validatePayload($request, $agency);

        $apiKey->fill([
            'name'               => $data['name'],
            'scopes'             => $data['scopes'] ?? [],
            'webhook_url'        => $data['webhook_url'] ?? null,
            'rate_limit_per_min' => $data['rate_limit_per_min'] ?? $apiKey->rate_limit_per_min,
            'expires_at'         => $data['expires_at'] ?? null,
        ]);

        // Ensure a webhook secret exists if the key now receives webhooks.
        if (in_array(AgencyApiKey::SCOPE_WEBHOOKS_RECEIVE, $apiKey->scopes ?? [], true) && empty($apiKey->webhook_secret)) {
            $apiKey->webhook_secret = bin2hex(random_bytes(24));
        }
        $apiKey->save();

        return $this->backToPanel($agency)->with('success', "“{$apiKey->name}” updated.");
    }

    /** Mint a fresh secret for an existing key (rotates the credential). */
    public function regenerate(Request $request, Agency $agency, AgencyApiKey $apiKey): RedirectResponse
    {
        $this->authorizeAgency($agency);
        $this->ensureBelongs($agency, $apiKey);

        $minted = AgencyApiKey::mintSecret();
        $apiKey->forceFill([
            'key_prefix'  => $minted['prefix'],
            'secret_hash' => $minted['hash'],
            'revoked_at'  => null, // regenerating reactivates
        ])->save();

        return $this->backToPanel($agency)
            ->with('new_api_key', ['id' => $apiKey->id, 'name' => $apiKey->name, 'plaintext' => $minted['plaintext']])
            ->with('success', "Secret for “{$apiKey->name}” regenerated. Copy it now — it won't be shown again.");
    }

    /** Revoke (disable without deleting) — recoverable by regenerate. */
    public function revoke(Request $request, Agency $agency, AgencyApiKey $apiKey): RedirectResponse
    {
        $this->authorizeAgency($agency);
        $this->ensureBelongs($agency, $apiKey);
        $apiKey->forceFill(['revoked_at' => now()])->save();

        return $this->backToPanel($agency)->with('success', "“{$apiKey->name}” revoked.");
    }

    /** Archive the key (soft delete — non-negotiable #1). */
    public function destroy(Request $request, Agency $agency, AgencyApiKey $apiKey): RedirectResponse
    {
        $this->authorizeAgency($agency);
        $this->ensureBelongs($agency, $apiKey);
        $apiKey->delete();

        return $this->backToPanel($agency)->with('success', "“{$apiKey->name}” deleted.");
    }

    /**
     * "Add all Active listings" — enable this website for every active listing
     * in the agency, in one batched action (the launch-day button).
     */
    public function bulkActivate(Request $request, Agency $agency, AgencyApiKey $apiKey): RedirectResponse
    {
        $this->authorizeAgency($agency);
        $this->ensureBelongs($agency, $apiKey);

        $summary = app(\App\Services\Syndication\Website\WebsiteSyndicationService::class)
            ->bulkActivateActive($apiKey);

        return $this->backToPanel($agency)->with(
            'success',
            "“{$apiKey->name}”: {$summary['enabled']} active listing(s) enabled" .
            ($summary['already_live'] > 0 ? ", {$summary['already_live']} already live" : '') . '.'
        );
    }

    /**
     * "Show all agents on website" — set show_on_website=true for every active,
     * non-owner staff member in the agency. Agency-wide (agents aren't per-site),
     * so they appear on every website. Saving each fires the UserObserver →
     * agent.published webhook for the newly-shown agents.
     */
    public function publishAllAgents(Request $request, Agency $agency): RedirectResponse
    {
        $this->authorizeAgency($agency);

        $agents = \App\Models\User::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->where('agency_id', $agency->id)
            ->where('is_active', true)
            ->agencyMembers()
            ->where('show_on_website', false)
            ->get();

        foreach ($agents as $agent) {
            $agent->update(['show_on_website' => true]); // fires UserObserver → agent.published
        }

        $already = \App\Models\User::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->where('agency_id', $agency->id)->where('is_active', true)->agencyMembers()
            ->where('show_on_website', true)->count();

        return $this->backToPanel($agency)->with(
            'success',
            "{$agents->count()} agent(s) now shown on the website" .
            ($agents->count() === 0 && $already > 0 ? " (all {$already} were already shown)" : '') . '.'
        );
    }

    /**
     * "Test connection" — answers "is this website connected?" for one key.
     * Checks both directions: website → CoreX (has the site called the API with
     * this key, per last_used_at) and CoreX → website (a signed `connection.test`
     * POST to the webhook URL, sent synchronously). The test POST is NOT logged
     * in agency_webhook_deliveries, so it never enters the retry sweep.
     *
     * Spec: .ai/specs/agency-public-api.md §7.1a
     */
    public function testConnection(Request $request, Agency $agency, AgencyApiKey $apiKey): \Illuminate\Http\JsonResponse
    {
        $this->authorizeAgency($agency);
        $this->ensureBelongs($agency, $apiKey);

        $checks = [];
        $add = function (string $label, string $status, string $detail) use (&$checks) {
            $checks[] = ['label' => $label, 'status' => $status, 'detail' => $detail];
        };

        // 1. Master switch.
        $agency->website_enabled
            ? $add('Website switch', 'ok', 'The website is live — CoreX is sharing data.')
            : $add('Website switch', 'fail', 'The website is offline. Click “Make website live” above, otherwise the site receives no data.');

        // 2. Key status.
        $apiKey->isActive()
            ? $add('API key', 'ok', 'This key is active.')
            : $add('API key', 'fail', 'This key is ' . $apiKey->statusLabel() . ' — the website cannot connect with it. Regenerate the secret to reactivate it.');

        // 3. Website → CoreX.
        if ($apiKey->last_used_at) {
            $add('Website → CoreX', 'ok', 'The website last connected ' . $apiKey->last_used_at->diffForHumans() . ' (' . $apiKey->last_used_at->format('Y-m-d H:i') . ').');
        } else {
            $add('Website → CoreX', 'fail', 'The website has never connected with this key. Check the key has been put into the website’s settings.');
        }

        // 4. CoreX → website (webhook push).
        if (!in_array(AgencyApiKey::SCOPE_WEBHOOKS_RECEIVE, $apiKey->scopes ?? [], true)) {
            $add('CoreX → website', 'skip', 'Not set up — this key does not receive pushed updates (the website fetches data itself).');
        } elseif (!$apiKey->webhook_url) {
            $add('CoreX → website', 'warn', 'No webhook URL set, so CoreX cannot push changes to the website. Add it under “Edit scopes / webhook”.');
        } elseif (!$apiKey->webhook_secret) {
            $add('CoreX → website', 'fail', 'No webhook signing secret on this key. Save the key once under “Edit scopes / webhook” to create one.');
        } else {
            $body = json_encode([
                'event'       => 'connection.test',
                'occurred_at' => now()->toIso8601String(),
                'agency_id'   => $agency->id,
                'data'        => ['message' => 'CoreX connection test — no action needed.'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            try {
                $response = \Illuminate\Support\Facades\Http::withHeaders([
                    'Content-Type'      => 'application/json',
                    'X-CoreX-Signature' => hash_hmac('sha256', $body, $apiKey->webhook_secret),
                    'X-CoreX-Event'     => 'connection.test',
                ])->timeout(10)->withBody($body, 'application/json')->post($apiKey->webhook_url);

                $code = $response->status();
                if ($response->successful()) {
                    $add('CoreX → website', 'ok', "The website received a test update and accepted it (HTTP {$code}).");
                } elseif (in_array($code, [401, 403], true)) {
                    $add('CoreX → website', 'fail', "The website was reached but rejected the update (HTTP {$code}). The webhook signing secret on the website probably doesn't match — copy it again from “Reveal webhook signing secret”.");
                } elseif ($code === 404) {
                    $add('CoreX → website', 'fail', 'The website was reached but the webhook URL was not found (HTTP 404). Check the webhook URL.');
                } else {
                    $add('CoreX → website', 'warn', "The website was reached but answered HTTP {$code}. The address works; the website may not recognise test messages, or it has an error.");
                }
            } catch (\Throwable $e) {
                $add('CoreX → website', 'fail', 'Could not reach the website: ' . \Illuminate\Support\Str::limit($e->getMessage(), 200));
            }
        }

        $failed  = collect($checks)->contains('status', 'fail');
        $warned  = collect($checks)->contains('status', 'warn');

        return response()->json([
            'success' => !$failed,
            'message' => $failed ? 'Not fully connected — see below.' : ($warned ? 'Connected, with a warning — see below.' : 'Connected — everything checks out.'),
            'checks'  => $checks,
        ]);
    }

    /** Master "website is live" switch for the agency (visibility layer 1). */
    public function toggleWebsite(Request $request, Agency $agency): RedirectResponse
    {
        $this->authorizeAgency($agency);
        $data = $request->validate(['website_enabled' => 'required|boolean']);
        $agency->forceFill(['website_enabled' => (bool) $data['website_enabled']])->save();

        $state = $agency->website_enabled ? 'live' : 'offline';

        return $this->backToPanel($agency)->with('success', "Agency website is now {$state}.");
    }

    // ---- helpers -----------------------------------------------------------

    private function validatePayload(Request $request, Agency $agency): array
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name'               => 'required|string|max:100',
            'scopes'             => 'nullable|array',
            'scopes.*'           => ['string', Rule::in(array_keys(AgencyApiKey::SCOPES))],
            'webhook_url'        => 'nullable|url|max:255',
            'rate_limit_per_min' => 'nullable|integer|min:1|max:6000',
            'expires_at'         => 'nullable|date',
        ], [
            'name.required' => 'Give the website a name (e.g. “Production website”).',
            'webhook_url.url' => 'The webhook URL must be a valid URL (https://…).',
        ]);

        // On failure, redirect back to the API Access tab (with errors + input)
        // so the user lands where they were, not the default Company tab.
        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException(
                $validator,
                redirect()->route('agencies.edit', $agency)
                    ->withFragment('api-access')
                    ->withInput($request->all())
                    ->withErrors($validator)
            );
        }

        return $validator->validated();
    }

    /** A key must belong to the agency in the route — otherwise 404. */
    private function ensureBelongs(Agency $agency, AgencyApiKey $apiKey): void
    {
        if ((int) $apiKey->agency_id !== (int) $agency->id) {
            abort(404);
        }
    }

    /**
     * Defense-in-depth tenant scope check: the route-group permission gate
     * (manage_performance_settings + agency_api.manage) is grantable to an
     * ordinary agency admin, not just platform owners, and Agency itself
     * carries no tenant scope — so route-model binding won't 404 a foreign
     * agency the way it does for child tables. Mirrors
     * CompanySettingsController::authorizeAgency() / AgencyController::authorizeAgencyScope().
     */
    private function authorizeAgency(Agency $agency): void
    {
        $user = auth()->user();
        if (!$user) {
            abort(403);
        }
        if ($user->isOwnerRole()) {
            return;
        }
        if ((int) $user->effectiveAgencyId() !== (int) $agency->id) {
            abort(403, 'You can only manage your own agency.');
        }
    }

    private function backToPanel(Agency $agency): RedirectResponse
    {
        return redirect()->route('agencies.edit', $agency)->withFragment('api-access');
    }
}
