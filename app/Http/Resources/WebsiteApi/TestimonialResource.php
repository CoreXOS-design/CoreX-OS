<?php

namespace App\Http\Resources\WebsiteApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public testimonial shape for agency websites. Only published testimonials
 * reach this resource (filtered in the controller). `author` is the editable
 * public display name — never the raw contact PII unless the agency chose it.
 *
 * Spec: .ai/specs/testimonials.md §4.
 */
class TestimonialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'     => $this->id,
            'author' => $this->display_name,
            'rating' => $this->rating !== null ? (int) $this->rating : null,
            'body'   => $this->body,
            'date'   => $this->published_at?->toDateString(),

            // The agent this testimonial is about — lets the website show the
            // agent and link to /agents/{agent_id}. `agent_id` is always present
            // for filtering/linking; `agent` is a light card when resolvable.
            'agent_id' => $this->agent_id !== null ? (int) $this->agent_id : null,
            'agent'    => $this->agent
                ? ['id' => (int) $this->agent->id, 'name' => $this->agent->name]
                : null,
        ];
    }
}
