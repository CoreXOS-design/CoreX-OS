<?php

namespace App\Services\Importer;

class P24ImagesCsvParser
{
    /**
     * Returns: ['listing_number' => [ordered image URLs by Ordinal]].
     */
    public function parse(string $path): array
    {
        $grouped = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open images CSV: {$path}");
        }
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return $grouped;
        }

        $tmp = [];
        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) === 1 && ($data[0] === null || $data[0] === '')) continue;
            $raw = array_combine($header, array_pad($data, count($header), null)) ?: [];
            $ln = (string)($raw['ListingNumber'] ?? '');
            $ord = is_numeric($raw['Ordinal'] ?? null) ? (int)$raw['Ordinal'] : 0;
            $url = trim((string)($raw['Prop24ImageUrl'] ?? ''));
            if ($ln === '' || $url === '') continue;
            $tmp[$ln][] = ['ordinal' => $ord, 'url' => $url, 'caption' => $raw['Caption'] ?? null];
        }
        fclose($handle);

        foreach ($tmp as $ln => $items) {
            usort($items, fn($a, $b) => $a['ordinal'] <=> $b['ordinal']);
            $grouped[$ln] = array_map(fn($i) => $i['url'], $items);
        }
        return $grouped;
    }

    /** Total image count across all listings. */
    public function count(array $grouped): int
    {
        return array_sum(array_map('count', $grouped));
    }
}
