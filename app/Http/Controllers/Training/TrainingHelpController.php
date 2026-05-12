<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\Training\TrainingDoc;
use App\Models\Training\TrainingDocBookmark;
use App\Models\Training\TrainingDocChunk;
use App\Models\Training\TrainingDocRead;
use App\Services\AI\EmbeddingService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TrainingHelpController extends Controller
{
    // ── Index ───────────────────────────────────────────────

    public function index(Request $request)
    {
        $user = auth()->user();
        $role = $user->effectiveRole();

        $query = TrainingDoc::ordered();

        $filter = $request->query('filter', 'all');
        if ($filter === 'for-me') {
            $query->forRole($role);
        } elseif ($filter !== 'all') {
            $query->where('role_audience', $filter);
        }

        $docs = $query->get();

        // Load progress for each doc
        $reads = TrainingDocRead::where('user_id', $user->id)
            ->whereIn('doc_id', $docs->pluck('id'))
            ->get()
            ->keyBy('doc_id');

        $bookmarks = TrainingDocBookmark::where('user_id', $user->id)->with('doc')->get();

        // Count required unread for badge
        $requiredDocs = TrainingDoc::required()->forRole($role)->ordered()->get();
        $requiredUnread = $requiredDocs->filter(function ($doc) use ($reads) {
            $read = $reads->get($doc->id);
            return !$read || !$read->completed_at || $read->is_outdated_since;
        })->count();

        // Overall progress (required docs only)
        $requiredTotal = $requiredDocs->count();
        $requiredDone = $requiredTotal - $requiredUnread;
        $overallProgress = $requiredTotal > 0 ? (int) round(($requiredDone / $requiredTotal) * 100) : 100;

        return view('training-help.index', compact(
            'docs', 'reads', 'bookmarks', 'filter', 'role',
            'requiredUnread', 'overallProgress', 'requiredTotal', 'requiredDone'
        ));
    }

    // ── Viewer ──────────────────────────────────────────────

    public function show(string $slug)
    {
        $doc = TrainingDoc::where('slug', $slug)->firstOrFail();
        $user = auth()->user();

        $chunks = $doc->chunks()->orderBy('chunk_index')->get();

        $read = TrainingDocRead::firstOrCreate(
            ['user_id' => $user->id, 'doc_id' => $doc->id],
            ['agency_id' => $user->effectiveAgencyId(), 'sections_completed' => []]
        );

        // Update last_read_at
        $read->update(['last_read_at' => now()]);

        $bookmarks = TrainingDocBookmark::where('user_id', $user->id)
            ->where('doc_id', $doc->id)
            ->get()
            ->keyBy('section_anchor');

        // Build TOC from chunks with unique section anchors
        $toc = $chunks->filter(fn ($c) => $c->section_anchor)
            ->unique('section_anchor')
            ->values();

        $sectionsCompleted = $read->sections_completed ?? [];

        // Render markdown content
        $renderedContent = Str::markdown($this->assembleMarkdown($chunks));

        return view('training-help.show', compact(
            'doc', 'chunks', 'read', 'bookmarks', 'toc',
            'sectionsCompleted', 'renderedContent'
        ));
    }

    // ── Search (AJAX) ───────────────────────────────────────

    public function search(Request $request)
    {
        $query = trim($request->query('q', ''));

        if (strlen($query) < 2) {
            return response()->json(['results' => []]);
        }

        $embeddings = app(EmbeddingService::class);
        $queryEmbedding = $embeddings->embed($query);

        if (!$queryEmbedding) {
            // Fallback to keyword search
            return $this->keywordSearch($query);
        }

        $chunks = TrainingDocChunk::where('has_embedding', true)
            ->with('doc')
            ->get();

        $scored = $chunks->map(function ($chunk) use ($queryEmbedding, $embeddings) {
            $score = $embeddings->cosineSimilarity($queryEmbedding, $chunk->embedding);
            return ['chunk' => $chunk, 'score' => $score];
        })
            ->filter(fn ($item) => $item['score'] >= 0.3)
            ->sortByDesc('score')
            ->take(10)
            ->values();

        $results = $scored->map(function ($item) {
            $chunk = $item['chunk'];
            $doc = $chunk->doc;
            $snippet = Str::limit(strip_tags($chunk->content), 200);
            $anchor = $chunk->section_anchor ? "#{$chunk->section_anchor}" : '';

            return [
                'doc_title' => $doc->title,
                'doc_slug'  => $doc->slug,
                'section'   => $chunk->heading_path,
                'anchor'    => $chunk->section_anchor,
                'snippet'   => $snippet,
                'url'       => "/corex/training-help/{$doc->slug}{$anchor}",
                'score'     => round($item['score'], 3),
            ];
        });

        return response()->json(['results' => $results]);
    }

    // ── Mark Section Read ───────────────────────────────────

    public function markRead(Request $request, string $slug)
    {
        $request->validate(['section' => 'required|string|max:200']);

        $doc = TrainingDoc::where('slug', $slug)->firstOrFail();
        $user = auth()->user();
        $section = $request->input('section');

        // Validate section exists in doc
        $exists = $doc->chunks()->where('section_anchor', $section)->exists();
        if (!$exists) {
            return response()->json(['error' => 'Section not found'], 404);
        }

        $read = TrainingDocRead::firstOrCreate(
            ['user_id' => $user->id, 'doc_id' => $doc->id],
            ['agency_id' => $user->effectiveAgencyId(), 'sections_completed' => []]
        );

        $completed = $read->sections_completed ?? [];
        if (!in_array($section, $completed)) {
            $completed[] = $section;
            $read->sections_completed = $completed;
        }
        $read->last_section_read = $section;
        $read->last_read_at = now();

        // Check if all sections are done
        $allSections = $doc->chunks()
            ->whereNotNull('section_anchor')
            ->distinct()
            ->pluck('section_anchor')
            ->toArray();
        $allDone = empty(array_diff($allSections, $completed));

        if ($allDone && !$read->completed_at) {
            $read->completed_at = now();
            $read->is_outdated_since = null;
        }

        $read->save();

        $progress = $doc->getProgressForUser($user->id);

        return response()->json([
            'success'    => true,
            'progress'   => $progress,
            'completed'  => (bool) $read->completed_at,
            'sections'   => $read->sections_completed,
        ]);
    }

    // ── Mark All Re-reviewed ────────────────────────────────

    public function markRereviewed(string $slug)
    {
        $doc = TrainingDoc::where('slug', $slug)->firstOrFail();
        $user = auth()->user();

        $read = TrainingDocRead::where('user_id', $user->id)
            ->where('doc_id', $doc->id)
            ->first();

        if ($read) {
            $read->update(['is_outdated_since' => null]);
        }

        return response()->json(['success' => true]);
    }

    // ── Bookmarks ───────────────────────────────────────────

    public function addBookmark(Request $request, string $slug)
    {
        $request->validate([
            'section' => 'required|string|max:200',
            'note'    => 'nullable|string|max:500',
        ]);

        $doc = TrainingDoc::where('slug', $slug)->firstOrFail();
        $user = auth()->user();

        $bookmark = TrainingDocBookmark::firstOrCreate(
            [
                'user_id'        => $user->id,
                'doc_id'         => $doc->id,
                'section_anchor' => $request->input('section'),
            ],
            ['note' => $request->input('note')]
        );

        return response()->json(['success' => true, 'bookmark_id' => $bookmark->id]);
    }

    public function removeBookmark(int $id)
    {
        $bookmark = TrainingDocBookmark::where('user_id', auth()->id())
            ->findOrFail($id);
        $bookmark->delete();

        return response()->json(['success' => true]);
    }

    // ── Progress API ────────────────────────────────────────

    public function progress()
    {
        $user = auth()->user();
        $role = $user->effectiveRole();

        $requiredDocs = TrainingDoc::required()->forRole($role)->ordered()->get();
        $reads = TrainingDocRead::where('user_id', $user->id)
            ->whereIn('doc_id', $requiredDocs->pluck('id'))
            ->get()
            ->keyBy('doc_id');

        $items = $requiredDocs->map(function ($doc) use ($reads) {
            $read = $reads->get($doc->id);
            return [
                'slug'     => $doc->slug,
                'title'    => $doc->title,
                'progress' => $doc->getProgressForUser(auth()->id()),
                'done'     => (bool) ($read?->completed_at),
                'outdated' => (bool) ($read?->is_outdated_since),
            ];
        });

        $done = $items->where('done', true)->where('outdated', false)->count();
        $total = $items->count();
        $percent = $total > 0 ? (int) round(($done / $total) * 100) : 100;

        // Next unread doc
        $next = $items->first(fn ($i) => !$i['done'] || $i['outdated']);

        return response()->json([
            'total'   => $total,
            'done'    => $done,
            'percent' => $percent,
            'next'    => $next,
            'items'   => $items,
        ]);
    }

    // ── PDF Export ───────────────────────────────────────────

    public function pdf(string $slug)
    {
        $doc = TrainingDoc::where('slug', $slug)->firstOrFail();
        $chunks = $doc->chunks()->orderBy('chunk_index')->get();
        $content = $this->assembleMarkdown($chunks);
        $html = Str::markdown($content);

        $rendered = view('training-help.pdf', [
            'doc'     => $doc,
            'content' => $html,
        ])->render();

        // Write to temp file
        $tmpHtml = tempnam(sys_get_temp_dir(), 'training_') . '.html';
        $tmpPdf = tempnam(sys_get_temp_dir(), 'training_') . '.pdf';
        file_put_contents($tmpHtml, $rendered);

        $scriptPath = base_path('scripts/html-to-pdf.mjs');
        $cmd = sprintf(
            'node %s %s %s 2>&1',
            escapeshellarg($scriptPath),
            escapeshellarg($tmpHtml),
            escapeshellarg($tmpPdf)
        );

        $output = shell_exec($cmd);

        if (!file_exists($tmpPdf) || filesize($tmpPdf) === 0) {
            @unlink($tmpHtml);
            @unlink($tmpPdf);
            return back()->with('error', 'PDF generation failed. Please try again.');
        }

        $filename = "{$doc->slug}-v{$doc->version}.pdf";

        return response()->download($tmpPdf, $filename, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    // ── Helpers ──────────────────────────────────────────────

    private function assembleMarkdown($chunks): string
    {
        return $chunks->pluck('content')->implode("\n\n");
    }

    private function keywordSearch(string $query): \Illuminate\Http\JsonResponse
    {
        $words = array_filter(explode(' ', mb_strtolower($query)));

        $chunks = TrainingDocChunk::with('doc')
            ->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->where('content', 'like', "%{$word}%");
                }
            })
            ->limit(10)
            ->get();

        $results = $chunks->map(function ($chunk) use ($query) {
            $doc = $chunk->doc;
            $snippet = Str::limit(strip_tags($chunk->content), 200);
            $anchor = $chunk->section_anchor ? "#{$chunk->section_anchor}" : '';

            return [
                'doc_title' => $doc->title,
                'doc_slug'  => $doc->slug,
                'section'   => $chunk->heading_path,
                'anchor'    => $chunk->section_anchor,
                'snippet'   => $snippet,
                'url'       => "/corex/training-help/{$doc->slug}{$anchor}",
                'score'     => 0,
            ];
        });

        return response()->json(['results' => $results]);
    }
}
