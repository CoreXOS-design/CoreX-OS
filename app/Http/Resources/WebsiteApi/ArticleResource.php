<?php

namespace App\Http\Resources\WebsiteApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public agent-article shape for agency websites. Only published articles reach
 * this resource (filtered in the controller). `agent_id` links the article to
 * its author so the website can show it on that agent's profile.
 *
 * Spec: .ai/specs/testimonials.md (agent linkage).
 */
class ArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'agent_id'      => (int) $this->user_id,
            'title'         => $this->title,
            'slug'          => $this->slug,
            'excerpt'       => $this->excerpt,
            'cover_image_url' => $this->coverImageUrl(),
            'body'          => $this->body,
            'link_url'      => $this->link_url,
            'tags'          => $this->tagList(),
            'read_minutes'  => $this->readMinutes(),
            'word_count'    => $this->wordCount(),
            'date'          => $this->published_at?->toDateString(),
        ];
    }
}
