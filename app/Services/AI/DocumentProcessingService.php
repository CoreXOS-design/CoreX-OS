<?php

namespace App\Services\AI;

use App\Models\KnowledgeDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentProcessingService
{
    private EmbeddingService $embeddingService;

    public function __construct(EmbeddingService $embeddingService)
    {
        $this->embeddingService = $embeddingService;
    }

    private const MIN_CHUNK_SIZE = 500;
    private const MAX_CHUNK_SIZE = 4000;

    /**
     * Process an uploaded file: store, create record, extract text, chunk, index.
     */
    public function processUpload(
        UploadedFile $file,
        int $categoryId,
        int $uploadedBy,
        string $title,
        ?string $description = null,
        ?string $version = null
    ): KnowledgeDocument {
        $extension = strtolower($file->getClientOriginalExtension());
        $fileName = $file->getClientOriginalName();
        $storedPath = $file->store('knowledge-documents', 'local');

        $document = KnowledgeDocument::create([
            'category_id' => $categoryId,
            'uploaded_by' => $uploadedBy,
            'title' => $title,
            'description' => $description,
            'file_path' => $storedPath,
            'file_name' => $fileName,
            'file_type' => $extension,
            'file_size' => $file->getSize(),
            'status' => 'processing',
            'version' => $version,
        ]);

        try {
            $fullPath = storage_path('app/' . $storedPath);
            $text = $this->extractText($fullPath, $extension);

            if (empty(trim($text))) {
                $document->update([
                    'status' => 'error',
                    'error_message' => 'No text could be extracted from this file.',
                ]);
                return $document;
            }

            $chunks = $this->chunkText($text);
            $this->storeChunks($document, $chunks);
            $this->generateEmbeddings($document);

            $document->update([
                'status' => 'ready',
                'chunk_count' => count($chunks),
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Knowledge document processing failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            $document->update([
                'status' => 'error',
                'error_message' => 'Processing failed: ' . Str::limit($e->getMessage(), 500),
            ]);
        }

        return $document;
    }

    /**
     * Re-process an existing document: delete chunks, re-extract and re-chunk.
     */
    public function reprocess(KnowledgeDocument $document): KnowledgeDocument
    {
        $document->chunks()->delete();
        $document->update(['status' => 'processing', 'error_message' => null]);

        try {
            $fullPath = storage_path('app/' . $document->file_path);
            $text = $this->extractText($fullPath, $document->file_type);

            if (empty(trim($text))) {
                $document->update([
                    'status' => 'error',
                    'error_message' => 'No text could be extracted from this file.',
                ]);
                return $document;
            }

            $chunks = $this->chunkText($text);
            $this->storeChunks($document, $chunks);
            $this->generateEmbeddings($document);

            $document->update([
                'status' => 'ready',
                'chunk_count' => count($chunks),
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Knowledge document reprocessing failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            $document->update([
                'status' => 'error',
                'error_message' => 'Reprocessing failed: ' . Str::limit($e->getMessage(), 500),
            ]);
        }

        return $document;
    }

    /**
     * Extract text from a file based on its type.
     */
    private function extractText(string $path, string $type): string
    {
        return match ($type) {
            'pdf' => $this->extractFromPdf($path),
            'docx', 'doc' => $this->extractFromDocx($path),
            'txt', 'md' => file_get_contents($path) ?: '',
            default => '',
        };
    }

    /**
     * Extract text from a PDF using pdftotext CLI.
     */
    private function extractFromPdf(string $path): string
    {
        $escapedPath = escapeshellarg($path);

        // Try pdftotext with -layout flag first
        $output = [];
        $returnCode = 0;
        exec("pdftotext -layout {$escapedPath} -", $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            return implode("\n", $output);
        }

        // Fallback: pdftotext without -layout
        $output = [];
        exec("pdftotext {$escapedPath} -", $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            return implode("\n", $output);
        }

        // Final fallback: smalot/pdfparser if available
        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);
            return $pdf->getText();
        }

        return '';
    }

    /**
     * Extract text from a DOCX using pandoc CLI.
     */
    private function extractFromDocx(string $path): string
    {
        $escapedPath = escapeshellarg($path);

        // Try pandoc first
        $output = [];
        $returnCode = 0;
        exec("pandoc {$escapedPath} -t plain", $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            return implode("\n", $output);
        }

        // Fallback: ZipArchive XML extraction
        return $this->extractDocxViaZip($path);
    }

    /**
     * Extract text from DOCX by reading the XML inside the zip.
     */
    private function extractDocxViaZip(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }

        $content = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($content === false) {
            return '';
        }

        // Strip XML tags and decode entities
        $text = strip_tags($content);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return $text;
    }

    /**
     * Split text into clause/section-aware chunks.
     *
     * Strategy: detect section headings → split at boundaries → merge tiny
     * sections → split oversized sections → add sentence-level overlap.
     */
    private function chunkText(string $text): array
    {
        $text = trim($text);
        if (empty($text)) {
            return [];
        }

        // Step 1: Split at detected clause/section boundaries
        $sections = $this->splitIntoSections($text);

        // Step 2: Merge small sections, split large ones
        $normalized = $this->normalizeSections($sections);

        // Step 3: Add last-sentence overlap between consecutive chunks
        $withOverlap = $this->addSentenceOverlap($normalized);

        // Step 4: Build final chunk metadata array
        return $this->buildChunkArray($withOverlap);
    }

    /**
     * Split full text into sections at detected heading boundaries.
     * Each section = ['title' => ?string, 'body' => string].
     */
    private function splitIntoSections(string $text): array
    {
        $lines = explode("\n", $text);
        $sections = [];
        $currentTitle = null;
        $currentBody = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($this->isSectionHeading($trimmed)) {
                // Flush previous section
                if (!empty($currentBody) || $currentTitle !== null) {
                    $sections[] = [
                        'title' => $currentTitle,
                        'body' => implode("\n", $currentBody),
                    ];
                }
                $currentTitle = $trimmed;
                $currentBody = [];
            } else {
                $currentBody[] = $line;
            }
        }

        // Flush last section
        if (!empty($currentBody) || $currentTitle !== null) {
            $sections[] = [
                'title' => $currentTitle,
                'body' => implode("\n", $currentBody),
            ];
        }

        return $sections;
    }

    /**
     * Test whether a line is a clause/section heading.
     */
    private function isSectionHeading(string $line): bool
    {
        if (empty($line) || mb_strlen($line) > 120) {
            return false;
        }

        // Markdown headers: "# Title", "## Title"
        if (preg_match('/^#{1,6}\s+\S/', $line)) {
            return true;
        }

        // Named clauses: "CLAUSE 1", "CLAUSE 2:"
        if (preg_match('/^CLAUSE\s+\d+/i', $line)) {
            return true;
        }

        // Legal section markers: CHAPTER, SECTION, PART, SCHEDULE, etc.
        if (preg_match('/^(CHAPTER|SECTION|PART|SCHEDULE|ANNEXURE|APPENDIX)\s/i', $line)) {
            return true;
        }

        // Numbered clauses: "1.", "2.", "12.", "1.1", "2.3.1" at start of line
        // Only when the remainder is heading-length (not a full paragraph sentence)
        if (preg_match('/^\d+(\.\d+)*[\.\)]\s+(.*)/', $line, $m)) {
            $rest = trim($m[2]);
            if (mb_strlen($rest) <= 80) {
                return true;
            }
        }

        // All-caps line that looks like a legal heading (JURISDICTION, PURCHASE PRICE, etc.)
        if (mb_strlen($line) > 3 && mb_strlen($line) <= 80
            && mb_strtoupper($line) === $line
            && preg_match_all('/[A-Z]/', $line) >= 2) {
            return true;
        }

        return false;
    }

    /**
     * Build the full text content for a section (title + body).
     */
    private function buildSectionContent(array $section): string
    {
        $parts = [];
        if (!empty($section['title'])) {
            $parts[] = $section['title'];
        }
        $body = trim($section['body'] ?? '');
        if ($body !== '') {
            $parts[] = $body;
        }
        return trim(implode("\n", $parts));
    }

    /**
     * Merge sections smaller than MIN and split sections larger than MAX.
     * Small sections merge backward into the previous chunk.
     */
    private function normalizeSections(array $sections): array
    {
        $normalized = [];

        foreach ($sections as $section) {
            $content = $this->buildSectionContent($section);
            if (empty($content)) {
                continue;
            }

            $charCount = mb_strlen($content);

            // Try to merge small section into previous chunk
            if ($charCount < self::MIN_CHUNK_SIZE && !empty($normalized)) {
                $prevIdx = count($normalized) - 1;
                $merged = $normalized[$prevIdx]['content'] . "\n\n" . $content;

                if (mb_strlen($merged) <= self::MAX_CHUNK_SIZE) {
                    $normalized[$prevIdx]['content'] = $merged;
                    continue;
                }
            }

            // Split oversized section at paragraph / sentence boundaries
            if ($charCount > self::MAX_CHUNK_SIZE) {
                $subChunks = $this->splitLargeSection($section['title'], $content);
                foreach ($subChunks as $sub) {
                    $normalized[] = $sub;
                }
            } else {
                $normalized[] = [
                    'title' => $section['title'],
                    'content' => $content,
                ];
            }
        }

        return $normalized;
    }

    /**
     * Split an oversized section first at paragraph boundaries, then sentences.
     */
    private function splitLargeSection(?string $sectionTitle, string $content): array
    {
        $paragraphs = preg_split('/\n\s*\n/', $content, -1, PREG_SPLIT_NO_EMPTY);

        $chunks = [];
        $currentContent = '';
        $chunkCount = 0;

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }

            $candidate = $currentContent === '' ? $para : $currentContent . "\n\n" . $para;

            if (mb_strlen($candidate) <= self::MAX_CHUNK_SIZE) {
                $currentContent = $candidate;
                continue;
            }

            // Flush accumulated content
            if ($currentContent !== '') {
                $title = $chunkCount === 0 ? $sectionTitle : ($sectionTitle ? $sectionTitle . ' (cont.)' : null);
                $chunks[] = ['title' => $title, 'content' => $currentContent];
                $chunkCount++;
                $currentContent = '';
            }

            // Handle a single paragraph that exceeds MAX
            if (mb_strlen($para) > self::MAX_CHUNK_SIZE) {
                $sentenceChunks = $this->splitAtSentences($para);
                foreach ($sentenceChunks as $sc) {
                    $title = $chunkCount === 0 ? $sectionTitle : ($sectionTitle ? $sectionTitle . ' (cont.)' : null);
                    $chunks[] = ['title' => $title, 'content' => $sc];
                    $chunkCount++;
                }
            } else {
                $currentContent = $para;
            }
        }

        // Flush remainder
        if (trim($currentContent) !== '') {
            $title = $chunkCount === 0 ? $sectionTitle : ($sectionTitle ? $sectionTitle . ' (cont.)' : null);
            $chunks[] = ['title' => $title, 'content' => trim($currentContent)];
        }

        return $chunks;
    }

    /**
     * Split a block of text at sentence boundaries, respecting MAX_CHUNK_SIZE.
     * Returns array of content strings.
     */
    private function splitAtSentences(string $text): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {
            $candidate = $current === '' ? $sentence : $current . ' ' . $sentence;

            if (mb_strlen($candidate) > self::MAX_CHUNK_SIZE && $current !== '') {
                $chunks[] = $current;
                $current = $sentence;
            } else {
                $current = $candidate;
            }
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }

    /**
     * Add sentence-level overlap: prepend the last sentence of the previous
     * chunk to the start of each subsequent chunk (preserves context without
     * duplicating headings).
     */
    private function addSentenceOverlap(array $chunks): array
    {
        if (count($chunks) <= 1) {
            return $chunks;
        }

        $result = [$chunks[0]];

        for ($i = 1; $i < count($chunks); $i++) {
            $lastSentence = $this->extractLastSentence($chunks[$i - 1]['content']);

            if ($lastSentence !== null) {
                $chunks[$i]['content'] = $lastSentence . "\n" . $chunks[$i]['content'];
            }

            $result[] = $chunks[$i];
        }

        return $result;
    }

    /**
     * Extract the last sentence from a chunk for overlap purposes.
     * Returns null if the chunk is a single sentence or the last sentence is too long.
     */
    private function extractLastSentence(string $text): ?string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        if (count($sentences) < 2) {
            return null;
        }

        $last = end($sentences);

        // Skip overlap if last sentence is too long
        if (mb_strlen($last) > 300) {
            return null;
        }

        return $last;
    }

    /**
     * Convert normalized chunk arrays into final metadata format.
     */
    private function buildChunkArray(array $chunks): array
    {
        $result = [];

        foreach ($chunks as $chunk) {
            $content = trim($chunk['content']);
            if ($content === '') {
                continue;
            }

            $result[] = [
                'content' => $content,
                'section_title' => $chunk['title'] ?? null,
                'char_count' => mb_strlen($content),
                'word_count' => str_word_count($content),
            ];
        }

        return $result;
    }

    /**
     * Store chunks for a document.
     */
    private function storeChunks(KnowledgeDocument $document, array $chunks): void
    {
        foreach ($chunks as $index => $chunk) {
            $document->chunks()->create([
                'chunk_index' => $index,
                'content' => $chunk['content'],
                'section_title' => $chunk['section_title'],
                'char_count' => $chunk['char_count'],
                'word_count' => $chunk['word_count'],
            ]);
        }
    }

    /**
     * Generate embeddings for all chunks of a document.
     * Graceful degradation: if API fails, chunks remain without embeddings.
     */
    public function generateEmbeddings(KnowledgeDocument $document): int
    {
        $chunks = $document->chunks()->where('has_embedding', false)->get();
        if ($chunks->isEmpty()) {
            return 0;
        }

        // Build embedding input: section_title + content
        $texts = $chunks->map(function ($chunk) {
            $parts = [];
            if ($chunk->section_title) {
                $parts[] = $chunk->section_title;
            }
            $parts[] = $chunk->content;
            return implode("\n", $parts);
        })->toArray();

        $embeddings = $this->embeddingService->embedBatch($texts);

        $embedded = 0;
        foreach ($chunks as $i => $chunk) {
            if (isset($embeddings[$i]) && $embeddings[$i] !== null) {
                $chunk->update([
                    'embedding' => $embeddings[$i],
                    'has_embedding' => true,
                ]);
                $embedded++;
            }
        }

        return $embedded;
    }
}
