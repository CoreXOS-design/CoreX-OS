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
        $this->ensureBelongs($agency, $apiKey);
        $apiKey->forceFill(['revoked_at' => now()])->save();

        return $this->backToPanel($agency)->with('success', "“{$apiKey->name}” revoked.");
    }

    /** Archive the key (soft delete — non-negotiable #1). */
    public function destroy(Request $request, Agency $agency, AgencyApiKey $apiKey): RedirectResponse
    {
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
     * "Push all Sold listings" — enable this website for every SOLD listing in
     * the agency, in one batched action. Mirrors bulkActivate but for sold stock.
     */
    public function bulkActivateSold(Request $request, Agency $agency, AgencyApiKey $apiKey): RedirectResponse
    {
        $this->ensureBelongs($agency, $apiKey);

        $summary = app(\App\Services\Syndication\Website\WebsiteSyndicationService::class)
            ->bulkActivateSold($apiKey);

        $message = $summary['scanned'] === 0
            ? "“{$apiKey->name}”: no sold listings to push."
            : "“{$apiKey->name}”: {$summary['enabled']} sold listing(s) pushed to the website" .
              ($summary['already_live'] > 0 ? ", {$summary['already_live']} already live" : '') . '.';

        return $this->backToPanel($agency)->with('success', $message);
    }

    /**
     * "Show all agents on website" — set show_on_website=true for every active,
     * non-owner staff member in the agency. Agency-wide (agents aren't per-site),
     * so they appear on every website. Saving each fires the UserObserver →
     * agent.published webhook for the newly-shown agents.
     */
    public function publishAllAgents(Request $request, Agency $agency): RedirectResponse
    {
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

    /** Master "website is live" switch for the agency (visibility layer 1). */
    public function toggleWebsite(Request $request, Agency $agency): RedirectResponse
    {
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

    private function backToPanel(Agency $agency): RedirectResponse
    {
        return redirect()->route('agencies.edit', $agency)->withFragment('api-access');
    }
}
