<?php

namespace App\Http\Resources\WebsiteApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Public agency branding + website settings for agency websites. The site
 * pulls this to render its header, contact block, social links, and theme.
 *
 * Spec: .ai/specs/agency-public-api.md §3.7, §5
 */
class AgencyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Public contact block — website-specific values, falling back to the
        // agency's internal contact details. Blank entries are dropped so the
        // website never renders an empty field.
        $contact = array_filter([
            'email'   => $this->website_contact_email ?: $this->email,
            'phone'   => $this->website_contact_phone ?: $this->phone,
            'address' => $this->website_address ?: $this->address,
        ], fn ($v) => filled($v));

        // Social links — only the networks that are actually set.
        $social = array_filter([
            'facebook'  => $this->website_social_facebook,
            'instagram' => $this->website_social_instagram,
            'linkedin'  => $this->website_social_linkedin,
            'youtube'   => $this->website_social_youtube,
        ], fn ($v) => filled($v));

        // Open hours — normalise and keep only rows with at least one value.
        $openHours = collect($this->website_open_hours ?? [])
            ->map(fn ($row) => [
                'days'  => trim((string) ($row['days'] ?? '')),
                'hours' => trim((string) ($row['hours'] ?? '')),
            ])
            ->filter(fn ($row) => $row['days'] !== '' || $row['hours'] !== '')
            ->values()
            ->all();

        // Top-level: drop any null section so blank data never reaches the site.
        return array_filter([
            'name'         => $this->name,
            'trading_name' => $this->trading_name,
            'logo_url'     => $this->logo_path
                ? Storage::disk('public')->url(ltrim($this->logo_path, '/'))
                : null,

            'branding' => [
                'sidebar_color' => $this->sidebar_color,
                'icon_color'    => $this->icon_color,
                'default_color' => $this->default_color,
                'button_color'  => $this->button_color,
            ],

            'contact'    => $contact ?: null,
            'social'     => $social ?: null,
            'open_hours' => $openHours ?: null,

            'show' => [
                'agents'   => (bool) $this->website_show_agents,
                'listings' => (bool) $this->website_show_listings,
                'branches' => (bool) $this->website_show_branches,
            ],

            // How /agents is ordered ('alphabetical' | 'custom'). The /agents
            // response is already sorted accordingly — this is just informational.
            'agent_order_mode' => $this->website_agent_order_mode ?: 'alphabetical',

            // How /branches is ordered ('alphabetical' | 'custom'). The /branches
            // response is already sorted accordingly — this is just informational.
            'branch_order_mode' => $this->website_branch_order_mode ?: 'alphabetical',
        ], fn ($v) => !is_null($v));
    }
}
