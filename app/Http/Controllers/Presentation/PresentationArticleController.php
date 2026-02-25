<?php

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\ArticlePool;
use App\Models\Presentation;
use App\Models\PresentationArticle;
use App\Services\Articles\ArticleMatcherService;
use App\Services\Presentations\Evidence\AIExtractionService;
use Illuminate\Http\Request;

class PresentationArticleController extends Controller
{
    /**
     * Add an article from the pool to a presentation.
     * Generates an AI summary and stores as a PresentationArticle.
     */
    public function add(Request $request, Presentation $presentation)
    {
        $request->validate([
            'article_pool_id' => 'required|integer|exists:article_pool,id',
        ]);

        $poolArticle = ArticlePool::findOrFail($request->article_pool_id);

        // Check if already attached (dedup on URL)
        $exists = $presentation->articles()->where('url', $poolArticle->url)->exists();
        if ($exists) {
            return back()->with('warning', 'This article is already added to the presentation.');
        }

        // Generate AI summary using existing AIExtractionService
        $textForSummary = $poolArticle->snippet ?? $poolArticle->title;
        $aiResult = ['summary' => null, 'model' => null];

        if ($textForSummary !== '' && config('features.article_ingestion', false)) {
            $ai = new AIExtractionService();

            // Build context-aware prompt text
            $context = "Property context: {$presentation->suburb}, {$presentation->property_type}";
            if ($presentation->asking_price_inc) {
                $context .= ', asking R ' . number_format($presentation->asking_price_inc);
            }
            $fullText = "{$context}\n\nArticle title: {$poolArticle->title}\nArticle text: {$textForSummary}";

            $aiResult = $ai->summariseArticle($fullText, array_values(array_filter([
                $presentation->suburb,
                $presentation->property_type,
            ])));
        }

        // If AI summary failed, use the snippet as a fallback
        $summary = $aiResult['summary'] ?? $poolArticle->snippet;

        // Build tags_json: merge pool tags + metadata for display
        $tags = $poolArticle->tags_json ?? [];
        $tags['source']       = $poolArticle->source;
        $tags['title']        = $poolArticle->title;
        $tags['published_at'] = $poolArticle->published_at?->toIso8601String();
        $tags['pool_id']      = $poolArticle->id;

        PresentationArticle::create([
            'presentation_id'       => $presentation->id,
            'url'                   => $poolArticle->url,
            'snapshot_text'         => $poolArticle->snippet,
            'content_hash'          => hash('sha256', $textForSummary),
            'fetched_at'            => $poolArticle->scraped_at,
            'ai_summary_text'       => $summary,
            'ai_summary_model'      => $aiResult['model'],
            'ai_summary_created_at' => $aiResult['model'] ? now() : null,
            'tags_json'             => $tags,
        ]);

        return back()->with('success', 'Article added to presentation.');
    }

    /**
     * Remove an article from a presentation.
     */
    public function remove(Presentation $presentation, PresentationArticle $article)
    {
        if ($article->presentation_id !== $presentation->id) {
            abort(404);
        }

        $article->delete();

        return back()->with('success', 'Article removed from presentation.');
    }
}
