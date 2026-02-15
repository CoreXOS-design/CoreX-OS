<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Listings\ListingImportMapper;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ProbeListingImport extends Command
{
    protected $signature = 'listings:probe-import {file}';
    protected $description = 'Probe a CSV/XLSX listings export and show auto-mapping + agent detection';

    public function handle(): int
    {
        $file = $this->argument('file');

        if (!file_exists($file)) {
            $this->error("File not found: $file");
            return 1;
        }

        $this->info("Reading: $file");

        try {
            $spreadsheet = IOFactory::load($file);
        } catch (\Throwable $e) {
            $this->error("Could not read spreadsheet: " . $e->getMessage());
            return 1;
        }

        $sheet = $spreadsheet->getActiveSheet();
        $raw = $sheet->toArray(null, true, true, false);

        if (!$raw || count($raw) < 2) {
            $this->error("No data rows found.");
            return 1;
        }

        // First row = headers
        $headers = array_map(
            fn($v) => is_null($v) ? '' : (string)$v,
            $raw[0]
        );

        // Remaining rows
        $rows = array_slice($raw, 1);

        $this->line("\n== Headers ==");
        foreach ($headers as $i => $h) {
            if (trim($h) === '') continue;
            $this->line("[$i] $h");
        }

        $mapping = ListingImportMapper::suggestMapping($headers);

        $this->line("\n== Suggested Mapping ==");
        if (empty($mapping)) {
            $this->warn("(none)");
        } else {
            foreach ($mapping as $logical => $idx) {
                $this->line("$logical  =>  [{$idx}] {$headers[$idx]}");
            }
        }

        $validation = ListingImportMapper::validateRequired($mapping);

        if (!$validation['ok']) {
            $this->error("\nMissing required fields:");
            foreach ($validation['missing'] as $m) {
                $this->error(" - $m");
            }
        } else {
            $this->info("\nAll required fields present.");
        }

        if (isset($mapping['agent'])) {
            $agents = ListingImportMapper::uniqueAgents($rows, $mapping['agent']);
            $this->line("\n== Unique Agents Found ==");
            foreach ($agents as $a) {
                $this->line(" - $a");
            }
        } else {
            $this->warn("\nNo agent column detected.");
        }

        $this->line("\n== Sample Normalized Rows (first 3) ==");
        $sampleCount = 0;
        foreach ($rows as $r) {
            if ($sampleCount >= 3) break;

            $rec = [
                "external_id"  => isset($mapping["external_id"])  ? ($r[$mapping["external_id"]] ?? null) : null,
                "external_ref" => isset($mapping["external_ref"]) ? ($r[$mapping["external_ref"]] ?? null) : null,
                "property"     => isset($mapping["property"])     ? ($r[$mapping["property"]] ?? null) : null,
                "status"       => isset($mapping["status"])       ? ($r[$mapping["status"]] ?? null) : null,
                "price_raw"    => isset($mapping["price"])        ? ($r[$mapping["price"]] ?? null) : null,
                "price_cents"  => isset($mapping["price"])        ? ListingImportMapper::parsePriceToCents($r[$mapping["price"]] ?? null) : null,
                "file_agent"   => isset($mapping["agent"])        ? ($r[$mapping["agent"]] ?? null) : null,
            ];

            $this->line("Row " . ($sampleCount + 1) . ": " . json_encode($rec, JSON_UNESCAPED_UNICODE));
            $sampleCount++;
        }

        $this->info("\nProbe complete.");
        return 0;
    }
}
