<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\AgentArticle;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Agent self-service article management (My Portal → Profile). Each agent
 * authors their own articles for their public website profile. Publishing is
 * the agent's own gesture (their own content). Only own articles are editable.
 *
 * Spec: .ai/specs/testimonials.md (agent linkage).
 */
class AgentArticleController extends Controller
{
    public function store(Request $request)
    {
        $data = $this->validateInput($request);

        $request->user()->articles()->create([
            'title'        => $data['title'],
            'excerpt'      => $data['excerpt'] ?? null,
            'body'         => $data['body'] ?? null,
            'slug'         => Str::slug($data['title']) ?: null,
            'is_published' => false,
        ]);

        return back()->with('success', 'Article added.')->withFragment('profile');
    }

    public function update(Request $request, AgentArticle $article)
    {
        $this->authorizeOwner($request, $article);
        $data = $this->validateInput($request);

        $article->update([
            'title'   => $data['title'],
            'excerpt' => $data['excerpt'] ?? null,
            'body'    => $data['body'] ?? null,
            'slug'    => Str::slug($data['title']) ?: $article->slug,
        ]);

        return back()->with('success', 'Article updated.')->withFragment('profile');
    }

    public function togglePublish(Request $request, AgentArticle $article)
    {
        $this->authorizeOwner($request, $article);

        $publish = $request->boolean('is_published');
        $article->update([
            'is_published' => $publish,
            'published_at' => $publish ? ($article->published_at ?: now()) : $article->published_at,
        ]);

        return back()
            ->with('success', $publish ? 'Article published to your website.' : 'Article hidden from your website.')
            ->withFragment('profile');
    }

    public function destroy(Request $request, AgentArticle $article)
    {
        $this->authorizeOwner($request, $article);

        $article->delete();

        return back()->with('success', 'Article deleted.')->withFragment('profile');
    }

    private function validateInput(Request $request): array
    {
        return $request->validate([
            'title'   => ['required', 'string', 'max:200'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body'    => ['nullable', 'string', 'max:50000'],
        ]);
    }

    /** An agent may only manage their own articles. */
    private function authorizeOwner(Request $request, AgentArticle $article): void
    {
        abort_unless((int) $article->user_id === (int) $request->user()->id, 404);
    }
}
