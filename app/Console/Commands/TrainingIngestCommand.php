<?php

namespace App\Console\Commands;

use App\Models\Training\TrainingDoc;
use App\Models\Training\TrainingDocChunk;
use App\Models\Training\TrainingDocRead;
use App\Services\AI\EmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TrainingIngestCommand extends Command
{
    protected $signature = 'training:ingest {--force : Re-ingest even if content hash matches} {--doc= : Only ingest a specific slug}';
    protected $description = 'Ingest training docs from .ai/docs/training/ into the database with embeddings';

    // ── Filename → metadata mapping ────────────────────────
    private const DOC_META = [
        '00_TRAINING_INDEX'      => ['role' => 'all',                 'required' => true,  'order' => 0],
        '01_SYSTEM_SETUP'        => ['role' => 'super_admin',         'required' => true,  'order' => 1],
        '02_AGENCY_ADMIN_DAILY'  => ['role' => 'admin',              'required' => true,  'order' => 2],
        '03_BRANCH_MANAGER'      => ['role' => 'branch_manager',     'required' => true,  'order' => 3],
        '04_AGENT_DAY_ONE'       => ['role' => 'agent',              'required' => true,  'order' => 4],
        '05_AGENT_LISTING_WORKFLOW' => ['role' => 'agent',           'required' => false, 'order' => 5],
        '06_AGENT_BUYER_WORKFLOW'   => ['role' => 'agent',           'required' => false, 'order' => 6],
        '07_COMPLIANCE_OFFICER'  => ['role' => 'compliance_officer', 'required' => true,  'order' => 7],
        '08_DOCUPERFECT_GUIDE'   => ['role' => 'all',               'required' => false, 'order' => 8],
        '09_COMPLIANCE_REPORTING' => ['role' => 'all',              'required' => false, 'order' => 9],
        '10_QUICK_REFERENCE'     => ['role' => 'all',               'required' => true,  'order' => 10],
    ];

    private EmbeddingService $embeddings;

    public function handle(EmbeddingService $embeddings): int
    {
        $this->embeddings = $embeddings;
        $force = $this->option('force');
        $onlySlug = $this->option('doc');

        $docsPath = base_path('.ai/docs/training');
        if (! is_dir($docsPath)) {
            $this->error("Training docs directory not found: {$docsPath}");
            return 1;
        }

        $files = glob($docsPath . '/*.md');
        $processed = 0;
        $skipped = 0;

        foreach ($files as $filePath) {
            $filename = pathinfo($filePath, PATHINFO_FILENAME);

            // Skip CAPABILITY_AUDIT — it's reference, not training
            if (str_contains($filename, 'CAPABILITY_AUDIT')) {
                continue;
            }

            $slug = Str::slug($filename);

            if ($onlySlug && $slug !== $onlySlug) {
                continue;
            }

            $content = file_get_contents($filePath);
            $hash = hash('sha256', $content);

            $existing = TrainingDoc::withTrashed()->where('slug', $slug)->first();

            if ($existing && $existing->content_hash === $hash && !$force) {
                $this->line("  Skip (unchanged): {$filename}");
                $skipped++;
                continue;
            }

            $meta = $this->getMetaForFile($filename);
            $title = $this->extractTitle($content, $filename);
            $wordCount = str_word_count(strip_tags($content));
            $readingTime = max(1, (int) ceil($wordCount / 250));

            if ($existing) {
                // Update existing doc
                $isContentChanged = $existing->content_hash !== $hash;

                $existing->update([
                    'title'                => $title,
                    'role_audience'        => $meta['role'],
                    'file_path'            => $filePath,
                    'content_hash'         => $hash,
                    'word_count'           => $wordCount,
                    'reading_time_minutes' => $readingTime,
                    'is_required'          => $meta['required'],
                    'sort_order'           => $meta['order'],
                    'version'              => $isContentChanged ? $existing->version + 1 : $existing->version,
                    'last_indexed_at'      => now(),
                    'deleted_at'           => null,
                ]);

                if ($isContentChanged) {
                    // Mark all reads as outdated
                    TrainingDocRead::where('doc_id', $existing->id)
                        ->whereNull('is_outdated_since')
                        ->update(['is_outdated_since' => now()]);

                    $this->info("  Updated (v{$existing->version}): {$filename}");
                } else {
                    $this->info("  Re-indexed (forced): {$filename}");
                }

                $doc = $existing;
            } else {
                $doc = TrainingDoc::create([
                    'slug'                 => $slug,
                    'title'                => $title,
                    'role_audience'        => $meta['role'],
                    'file_path'            => $filePath,
                    'content_hash'         => $hash,
                    'word_count'           => $wordCount,
                    'reading_time_minutes' => $readingTime,
                    'is_required'          => $meta['required'],
                    'sort_order'           => $meta['order'],
                    'version'              => 1,
                    'last_indexed_at'      => now(),
                ]);

                $this->info("  Created: {$filename}");
            }

            // Re-chunk and re-embed
            $doc->chunks()->delete();
            $chunks = $this->splitIntoChunks($content);
            $this->createChunksWithEmbeddings($doc, $chunks);

            $processed++;
        }

        $this->newLine();
        $this->info("Done. Processed: {$processed}, Skipped: {$skipped}");
        $this->table(
            ['Slug', 'Title', 'Role', 'Required', 'Chunks', 'Words'],
            TrainingDoc::ordered()->get()->map(fn ($d) => [
                $d->slug, Str::limit($d->title, 35), $d->role_audience,
                $d->is_required ? 'Yes' : '', $d->chunks()->count(), $d->word_count,
            ])->toArray()
        );

        return 0;
    }

    private function getMetaForFile(string $filename): array
    {
        foreach (self::DOC_META as $prefix => $meta) {
            if (str_starts_with($filename, $prefix)) {
                return $meta;
            }
        }

        // Fallback: derive from filename
        return ['role' => 'all', 'required' => false, 'order' => 99];
    }

    private function extractTitle(string $content, string $filename): string
    {
        // First H1 line
        if (preg_match('/^#\s+(.+)$/m', $content, $m)) {
            // Strip markdown formatting from title
            return trim(str_replace(['*', '_', '`'], '', $m[1]));
        }

        return Str::title(str_replace('_', ' ', $filename));
    }

    /**
     * Split markdown content at H2 boundaries. If a section exceeds 800 words,
     * split further at H3 or paragraph boundaries.
     */
    private function splitIntoChunks(string $content): array
    {
        $chunks = [];
        $h1Title = '';

        // Extract H1 title
        if (preg_match('/^#\s+(.+)$/m', $content, $m)) {
            $h1Title = trim($m[1]);
        }

        // Split at H2 (## ) boundaries
        $sections = preg_split('/^(?=##\s)/m', $content);

        foreach ($sections as $section) {
            $section = trim($section);
            if (empty($section)) {
                continue;
            }

            // Detect the heading
            $heading = '';
            $anchor = '';
            if (preg_match('/^##\s+(.+)$/m', $section, $m)) {
                $heading = trim(str_replace(['*', '_', '`'], '', $m[1]));
                $anchor = Str::slug($heading);
            } elseif (preg_match('/^#\s+(.+)$/m', $section, $m)) {
                $heading = trim(str_replace(['*', '_', '`'], '', $m[1]));
                $anchor = Str::slug($heading);
            }

            $headingPath = $h1Title;
            if ($heading && $heading !== $h1Title) {
                $headingPath = $h1Title . ' > ' . $heading;
            }

            $words = str_word_count($section);

            if ($words > 800) {
                // Split at H3 boundaries or paragraphs
                $subSections = preg_split('/^(?=###\s)/m', $section);
                foreach ($subSections as $sub) {
                    $sub = trim($sub);
                    if (empty($sub)) continue;

                    $subHeading = $heading;
                    $subAnchor = $anchor;
                    if (preg_match('/^###\s+(.+)$/m', $sub, $sm)) {
                        $subHeading = trim(str_replace(['*', '_', '`'], '', $sm[1]));
                        $subAnchor = Str::slug($subHeading);
                        $headingPath = $h1Title . ($heading ? ' > ' . $heading : '') . ' > ' . $subHeading;
                    }

                    $chunks[] = [
                        'heading_path'   => $headingPath,
                        'section_anchor' => $subAnchor,
                        'content'        => $sub,
                        'word_count'     => str_word_count($sub),
                    ];
                }
            } else {
                $chunks[] = [
                    'heading_path'   => $headingPath,
                    'section_anchor' => $anchor,
                    'content'        => $section,
                    'word_count'     => $words,
                ];
            }
        }

        return $chunks;
    }

    private function createChunksWithEmbeddings(TrainingDoc $doc, array $chunks): void
    {
        if (empty($chunks)) {
            return;
        }

        // Prepare texts for batch embedding
        $texts = [];
        foreach ($chunks as $chunk) {
            $prefix = $chunk['heading_path'] ? $chunk['heading_path'] . "\n" : '';
            $texts[] = $prefix . $chunk['content'];
        }

        $this->line("    Generating embeddings for " . count($texts) . " chunks...");
        $embeddings = $this->embeddings->embedBatch($texts);

        foreach ($chunks as $index => $chunk) {
            $embedding = $embeddings[$index] ?? null;

            TrainingDocChunk::create([
                'doc_id'         => $doc->id,
                'chunk_index'    => $index,
                'heading_path'   => $chunk['heading_path'],
                'section_anchor' => $chunk['section_anchor'],
                'content'        => $chunk['content'],
                'word_count'     => $chunk['word_count'],
                'embedding'      => $embedding,
                'has_embedding'  => $embedding !== null,
            ]);
        }
    }
}
