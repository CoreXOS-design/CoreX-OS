<?php

namespace App\Http\Resources\WebsiteApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Public agent-profile shape for agency websites. Only agents flagged
 * show_on_website reach this resource (filtered in the controller). Exposes
 * contact fields appropriate for a public "meet the team" / agent card —
 * NOT the FFC number or other compliance/PII fields (spec §13 Q7 default).
 *
 * Spec: .ai/specs/agency-public-api.md §5
 */
class AgentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'designation' => $this->designation,
            'email'       => $this->email,
            'phone'       => $this->phone,
            'cell'        => $this->cell,
            'photo_url'   => $this->agent_photo_path
                ? Storage::disk('public')->url(ltrim($this->agent_photo_path, '/'))
                : null,
        ];
    }
}
