<?php

namespace App\Http\Resources\WebsiteApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Public branch (office) shape for an agency website. A branch carries its own
 * trading identity — trading name, address, phone override, email, logo — and
 * the public agents that fall under it. Each branch also reports how many
 * syndicated listings sit under it so the site can label/route "X properties".
 *
 * The agents + counts are attached by BranchesController (website_agents,
 * website_agent_count, website_listing_count) so this resource stays a pure
 * shaper and never triggers its own scoped queries.
 *
 * Spec: .ai/specs/agency-public-api.md §5 (branches).
 */
class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Branch-specific contact block. These are the per-branch *overrides*:
        // blank means "this branch uses the agency default", so we drop blanks
        // and let the site fall back to /agency. Trading name falls back to the
        // branch's internal name so a branch always has a display label.
        $out = array_filter([
            'trading_name'           => $this->trading_name ?: $this->name,
            'tagline'                => $this->tagline,
            'address'                => $this->address,
            'phone'                  => $this->phone,
            'phone_label'            => $this->phone_label,
            'phone_secondary'        => $this->phone_secondary,
            'phone_secondary_label'  => $this->phone_secondary_label,
            'email'                  => $this->email,
            'ppra_number'            => $this->ppra_number,
            'logo_url'               => $this->logo_path
                ? Storage::disk('public')->url(ltrim($this->logo_path, '/'))
                : null,
        ], fn ($v) => filled($v));

        // id always present so the site can key + deep-link /agents?branch_id=…
        // and /listings?branch_id=… ; counts always present (0 is meaningful).
        return [
            'id' => $this->id,
        ] + $out + [
            'agent_count'   => (int) ($this->website_agent_count ?? 0),
            'listing_count' => (int) ($this->website_listing_count ?? 0),
            'agents'        => AgentResource::collection($this->website_agents ?? collect()),
        ];
    }
}
