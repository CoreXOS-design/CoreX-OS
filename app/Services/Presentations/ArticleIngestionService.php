<?php

namespace App\Services\Presentations;

use App\Models\PresentationArticle;
use App\Services\Presentations\Evidence\AIExtractionService;

/**
 * Ingests an article URL into a presentation:
 *   1. Fetch + store HTML snapshot via UrlSnapshotService
 *   2. Extract readable text (strip HTML tags, basic cleanup)
 *   3. Store snapshot_text + content_hash
 *   4. Generate AI summary (AIExtractionService)
 *   5. Persist PresentationArticle row
 *
 * No AI calls during compile — the compiler reads stored summaries only.
 */
class ArticleIngestionService
{
    public function __construct(
        private UrlSnapshotService  $snapshots = new UrlSnapshotService(),
        private AIExtractionService $ai        = new AIExtractionService(),
    ) {}

    /**
     * Ingest an article URL for a presentation.
     *
     * @param  array<string>  $tags  Optional labels (e.g. ['market_outlook', 'Cape Town'])
     * @return array{
     *   article_id: int,
     *   url: string,
     *   snapshot_stored: bool,
     *   text_length: int,
     *   ai_summary_generated: bool,
     *   ai_model: string|null
     * }
     */
    public function ingest(int $presentationId, string $url, array $tags = []): array
    {
        // ── 1. Fetch snapshot (stored regardless of HTTP failure) ─────────────
        $snapshot     = $this->snapshots->storeSnapshot($presentationId, $url, 'article');
        $rawHtml      = $snapshot->snapshot_html ?? '';
        $snapshotOk   = $snapshot->http_status !== null && $snapshot->http_status < 400;

        // ── 2. Extract readable text ──────────────────────────────────────────
        $text   = $this->extractText($rawHtml);
        $hash   = $text !== '' ? hash('sha256', $text) : null;

        // ── 3. AI summary ─────────────────────────────────────────────────────
        $aiResult = ['summary' => null, 'model' => null];

        if ($text !== '' && config('features.article_ingestion', false)) {
            $aiResult = $this->ai->summariseArticle($text, $tags);
        }

        // ── 4. Persist ────────────────────────────────────────────────────────
        $article = PresentationArticle::create([
            'presentation_id'        => $presentationId,
            'url'                    => $url,
            'snapshot_text'          => $text !== '' ? $text : null,
            'content_hash'           => $hash,
            'fetched_at'             => $snapshot->fetched_at,
            'ai_summary_text'        => $aiResult['summary'],
            'ai_summary_model'       => $aiResult['model'],
            'ai_summary_created_at'  => $aiResult['summary'] !== null ? now() : null,
            'tags_json'              => !empty($tags) ? $tags : null,
        ]);

        return [
            'article_id'           => $article->id,
            'url'                  => $url,
            'snapshot_stored'      => $snapshotOk,
            'text_length'          => mb_strlen($text),
            'ai_summary_generated' => $aiResult['summary'] !== null,
            'ai_model'             => $aiResult['model'],
        ];
    }

    // ── Text extraction ───────────────────────────────────────────────────────

    /**
     * Strip HTML and scripts, returning clean readable text.
     */
    private function extractText(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // Remove scripts, styles, and comments using DOMDocument
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        // Remove unwanted elements
        foreach (['script', 'style', 'noscript', 'nav', 'footer', 'header'] as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            while ($elements->length > 0) {
                $elements->item(0)->parentNode?->removeChild($elements->item(0));
            }
        }

        $text = $dom->textContent;

        // Collapse whitespace
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text);

        return $text;
    }
}
