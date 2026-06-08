<?php

namespace App\Http\Controllers\Api\V1\Website;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebsiteApi\ArticleResource;
use App\Models\AgentArticle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public published agent articles for an agency website. Returns only
 * is_published=true articles of the key's agency, newest first. Supports
 * ?agent_id= so an agent's profile page can pull just their articles.
 *
 * Spec: .ai/specs/testimonials.md (agent linkage).
 */
class ArticlesController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $agencyId = $request->user()->agency_id;
        $perPage = max(1, min(100, (int) $request->integer('per_page', 50)));

        $query = AgentArticle::query()
            ->where('agency_id', $agencyId)
            ->where('is_published', true)
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if (($agentId = (int) $request->integer('agent_id')) > 0) {
            $query->where('user_id', $agentId);
        }

        return ArticleResource::collection($query->paginate($perPage));
    }

    public function show(Request $request, int $id): ArticleResource
    {
        $agencyId = $request->user()->agency_id;

        $article = AgentArticle::query()
            ->where('agency_id', $agencyId)
            ->where('is_published', true)
            ->where('id', $id)
            ->first();

        abort_if($article === null, 404, 'Article not found.');

        return new ArticleResource($article);
    }
}
