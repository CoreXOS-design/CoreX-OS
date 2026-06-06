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
        return [
            'name'         => $this->name,
            'trading_name' => $this->trading_name,
            'tagline'      => $this->website_tagline ?: $this->tagline,
            'about'        => $this->website_about,
            'logo_url'     => $this->logo_path
                ? Storage::disk('public')->url(ltrim($this->logo_path, '/'))
                : null,

            'branding' => [
                'sidebar_color' => $this->sidebar_color,
                'icon_color'    => $this->icon_color,
                'default_color' => $this->default_color,
                'button_color'  => $this->button_color,
            ],

            'contact' => [
                'email'   => $this->website_contact_email ?: $this->email,
                'phone'   => $this->website_contact_phone ?: $this->phone,
                'address' => $this->address,
            ],

            'social' => [
                'facebook'  => $this->website_social_facebook,
                'instagram' => $this->website_social_instagram,
                'linkedin'  => $this->website_social_linkedin,
                'youtube'   => $this->website_social_youtube,
            ],

            'website_url' => $this->website_url,

            'show' => [
                'agents'   => (bool) $this->website_show_agents,
                'listings' => (bool) $this->website_show_listings,
            ],

            // How /agents is ordered ('alphabetical' | 'custom'). The /agents
            // response is already sorted accordingly — this is just informational.
            'agent_order_mode' => $this->website_agent_order_mode ?: 'alphabetical',
        ];
    }
}
