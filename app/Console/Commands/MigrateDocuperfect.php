<?php

namespace App\Console\Commands;

use App\Models\Docuperfect\Clause;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\Template;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;

class MigrateDocuperfect extends Command
{
    protected $signature = 'docuperfect:migrate
                            {--fresh : Wipe existing Docuperfect data before importing}';

    protected $description = 'Migrate data from standalone Docuperfect SQLite DB into Nexus';

    /**
     * Hard-coded mappings from Docuperfect IDs → Nexus IDs.
     * Built from manual comparison of both databases.
     */
    private array $branchMap = [
        'branch_1762504950945_2' => 3,  // Southbroom
        'branch_1763363628146'   => 1,  // Shelly Beach
        'branch_1763545855519'   => 1,  // Rentals → Shelly Beach (no Rentals branch in Nexus)
    ];

    private array $userMap = [
        'user_admin'           => 22, // admin → Johan Reichel
        'user_1763705124842'   => 34, // Kym → Kym Pollard
        'user_1763705166373'   => 35, // Dru → Dru De Bruyn
        'user_1763711898277'   => 25, // Falan → Falan Du Bois
        'user_1763711918140'   => 23, // Elize → Elize Reicel
        'user_1763711933987'   => 26, // Shawn → Shawn Du Bois
        'user_1763715455052'   => 29, // Maggie → Maggie Venter
        'user_1763715435196'   => 22, // Rentals (shared account) → Johan Reichel
        'user_1769413158640'   => 33, // Cindy → Cindy Petersen
    ];

    private const FALLBACK_USER_ID = 22; // Johan Reichel

    public function handle(): int
    {
        $dbPath = base_path('docuperfect.db');

        if (! file_exists($dbPath)) {
            $this->error("SQLite database not found at: {$dbPath}");
            return self::FAILURE;
        }

        $sqlite = new PDO("sqlite:{$dbPath}");
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        if ($this->option('fresh')) {
            $this->warn('Wiping existing Docuperfect data...');
            DB::table('docuperfect_clause_branches')->truncate();
            DB::table('docuperfect_template_branches')->truncate();
            Document::query()->delete();
            Clause::query()->delete();
            Template::query()->delete();
            // Remove stored page images
            Storage::deleteDirectory('docuperfect/templates');
        }

        $start = microtime(true);
        $warnings = [];

        $clauseCount = 0;
        $templateCount = 0;
        $pageImageCount = 0;
        $documentCount = 0;
        $templateMap = []; // old SQLite ID → new Nexus ID

        DB::beginTransaction();

        try {
            // ── 1. Import Clauses ──────────────────────────────────────
            $this->info('Importing clauses...');
            $clauses = $sqlite->query('SELECT * FROM conditionalClauses')->fetchAll();

            foreach ($clauses as $c) {
                $ownerId = $this->mapUser($c['ownerId'], $warnings);
                $isGlobal = (bool) ($c['isGlobal'] ?? 1);

                $clause = Clause::create([
                    'name'      => $c['name'],
                    'text'      => $c['text'],
                    'is_global' => $isGlobal,
                    'owner_id'  => $ownerId,
                ]);

                // Parse allowedBranches and create pivot entries
                if (! $isGlobal) {
                    $allowedBranches = json_decode($c['allowedBranches'] ?? '[]', true) ?: [];
                    $nexusBranchIds = $this->mapBranches($allowedBranches, $warnings);
                    if (count($nexusBranchIds) > 0) {
                        $clause->branches()->attach($nexusBranchIds);
                    }
                }

                $clauseCount++;
            }
            $this->info("  → {$clauseCount} clauses imported.");

            // ── 2. Import Templates ────────────────────────────────────
            $this->info('Importing templates...');
            $templates = $sqlite->query('SELECT * FROM templates')->fetchAll();

            foreach ($templates as $t) {
                $ownerId = $this->mapUser($t['ownerId'], $warnings);
                $isGlobal = (bool) ($t['isGlobal'] ?? 1);
                $isArchived = (bool) ($t['archived'] ?? 0);

                // Decode fields
                $fields = json_decode($t['fields'] ?? '[]', true) ?: [];

                // Map templateType — standalone only uses 'standard', map to 'sales' as default
                $typeMap = [
                    'standard' => 'sales',
                    'sales'    => 'sales',
                    'rentals'  => 'rental',
                    'compliance' => 'compliance',
                ];
                $templateType = $typeMap[$t['templateType'] ?? 'standard'] ?? 'sales';

                // Decode page images to count pages
                $pageImages = json_decode($t['pageImages'] ?? '[]', true) ?: [];
                $pageCount = count($pageImages);

                $template = Template::create([
                    'name'          => trim($t['name']),
                    'template_type' => $templateType,
                    'page_count'    => $pageCount,
                    'fields_json'   => $fields,
                    'is_global'     => $isGlobal,
                    'owner_id'      => $ownerId,
                    'archived_at'   => $isArchived ? now() : null,
                ]);

                $templateMap[$t['id']] = $template->id;

                // Parse allowedBranches and create pivot entries
                if (! $isGlobal) {
                    $allowedBranches = json_decode($t['allowedBranches'] ?? '[]', true) ?: [];
                    $nexusBranchIds = $this->mapBranches($allowedBranches, $warnings);
                    if (count($nexusBranchIds) > 0) {
                        $template->branches()->attach($nexusBranchIds);
                    }
                }

                // Extract page images from base64 → save as files
                foreach ($pageImages as $i => $base64) {
                    // Detect format from data URL prefix
                    $extension = 'png';
                    if (preg_match('#^data:image/(\w+);base64,#', $base64, $m)) {
                        $extension = $m[1] === 'jpeg' ? 'jpg' : $m[1];
                    }

                    // Strip data URL prefix
                    $imageData = base64_decode(
                        preg_replace('#^data:image/\w+;base64,#', '', $base64)
                    );

                    if ($imageData === false) {
                        $warnings[] = "Failed to decode base64 for template '{$t['name']}' page {$i}";
                        continue;
                    }

                    $path = "docuperfect/templates/{$template->id}/page-{$i}.{$extension}";
                    Storage::put($path, $imageData);
                    $pageImageCount++;
                }

                $templateCount++;
                if ($templateCount % 10 === 0) {
                    $this->info("  → {$templateCount} templates processed...");
                }
            }
            $this->info("  → {$templateCount} templates imported ({$pageImageCount} page images).");

            // ── 3. Import Documents ────────────────────────────────────
            $this->info('Importing documents...');
            $documents = $sqlite->query('SELECT * FROM userDocuments')->fetchAll();

            foreach ($documents as $d) {
                $ownerId = $this->mapUser($d['ownerId'], $warnings);
                $branchId = $this->mapBranch($d['branchId'] ?? '', $warnings);
                $newTemplateId = $templateMap[$d['templateId']] ?? null;
                $isArchived = (bool) ($d['archived'] ?? 0);

                if ($newTemplateId === null) {
                    $warnings[] = "Document '{$d['name']}' references unknown template '{$d['templateId']}' — skipping.";
                    continue;
                }

                $fields = json_decode($d['fields'] ?? '[]', true) ?: [];

                Document::create([
                    'name'        => trim($d['name']),
                    'template_id' => $newTemplateId,
                    'fields_json' => $fields,
                    'owner_id'    => $ownerId,
                    'branch_id'   => $branchId,
                    'archived_at' => $isArchived ? now() : null,
                ]);

                $documentCount++;
            }
            $this->info("  → {$documentCount} documents imported.");

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("Migration failed: {$e->getMessage()}");
            $this->error($e->getTraceAsString());
            return self::FAILURE;
        }

        $elapsed = round(microtime(true) - $start, 2);

        // ── Summary ──────────────────────────────────────────────────
        $this->newLine();
        $this->info('=== Migration Complete ===');
        $this->info("Clauses:     {$clauseCount}");
        $this->info("Templates:   {$templateCount}");
        $this->info("Page images: {$pageImageCount}");
        $this->info("Documents:   {$documentCount}");
        $this->info("Time:        {$elapsed}s");

        if (count($warnings) > 0) {
            $this->newLine();
            $this->warn('Warnings (' . count($warnings) . '):');
            foreach (array_unique($warnings) as $w) {
                $this->warn("  ⚠ {$w}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Map a Docuperfect user ID to a Nexus user ID.
     */
    private function mapUser(string $dpUserId, array &$warnings): int
    {
        $dpUserId = trim($dpUserId);

        if ($dpUserId === '' || $dpUserId === 'undefined') {
            return self::FALLBACK_USER_ID;
        }

        if (isset($this->userMap[$dpUserId])) {
            return $this->userMap[$dpUserId];
        }

        $warnings[] = "Unmapped user: '{$dpUserId}' → using fallback (Johan, ID " . self::FALLBACK_USER_ID . ")";
        return self::FALLBACK_USER_ID;
    }

    /**
     * Map a single Docuperfect branch ID to a Nexus branch ID.
     */
    private function mapBranch(string $dpBranchId, array &$warnings): ?int
    {
        $dpBranchId = trim($dpBranchId);

        if ($dpBranchId === '' || $dpBranchId === 'Main' || $dpBranchId === 'undefined') {
            return null;
        }

        if (isset($this->branchMap[$dpBranchId])) {
            return $this->branchMap[$dpBranchId];
        }

        $warnings[] = "Unmapped branch: '{$dpBranchId}' → using null";
        return null;
    }

    /**
     * Map an array of Docuperfect branch IDs to Nexus branch IDs.
     */
    private function mapBranches(array $dpBranchIds, array &$warnings): array
    {
        $nexusIds = [];
        foreach ($dpBranchIds as $dpId) {
            $mapped = $this->mapBranch($dpId, $warnings);
            if ($mapped !== null) {
                $nexusIds[] = $mapped;
            }
        }
        return array_unique($nexusIds);
    }
}
