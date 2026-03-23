<?php

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\SignatureZone;

class SignatureZoneRenderer
{
    /**
     * Render a signature zone into positioned blocks for the given parties.
     *
     * Each party matching the zone's role gets a block positioned inside the
     * bounding box. Layout adapts to party count:
     *   1 → centred
     *   2 → side by side (2 cols)
     *   3 → row of 3
     *   4+ → grid (2 cols, N rows)
     *
     * @param  SignatureZone  $zone
     * @param  array  $parties  Array of parties matching the zone's role.
     *         Each entry: ['role' => 'seller', 'name' => '...', 'email' => '...']
     * @return array  Array of block definitions, each with:
     *         x, y, width, height (% relative to page), party_role, party_name, party_email
     */
    public function renderZone(SignatureZone $zone, array $parties): array
    {
        $count = count($parties);
        if ($count === 0) {
            return [];
        }

        $zoneX = (float) $zone->x_position;
        $zoneY = (float) $zone->y_position;
        $zoneW = (float) $zone->width;
        $zoneH = (float) $zone->height;

        // Padding inside the zone (% of zone dimensions)
        $padX = $zoneW * 0.02;
        $padY = $zoneH * 0.02;
        $innerW = $zoneW - ($padX * 2);
        $innerH = $zoneH - ($padY * 2);

        // Calculate grid: cols x rows
        $layout = $this->calculateGrid($count);
        $cols = $layout['cols'];
        $rows = $layout['rows'];

        // Block dimensions
        $gap = min($innerW * 0.02, 1); // small gap between blocks
        $blockW = ($innerW - ($gap * ($cols - 1))) / $cols;
        $blockH = ($innerH - ($gap * ($rows - 1))) / $rows;

        // Minimum block dimensions
        $blockW = max($blockW, 3);
        $blockH = max($blockH, 2);

        // Hard clamp: marker height must never exceed 8%
        $blockH = min($blockH, 8.0);

        $blocks = [];
        foreach ($parties as $i => $party) {
            $col = $i % $cols;
            $row = intdiv($i, $cols);

            $x = $zoneX + $padX + ($col * ($blockW + $gap));
            $y = $zoneY + $padY + ($row * ($blockH + $gap));

            // Centre the last row if it has fewer items
            $itemsInRow = ($row === $rows - 1) ? ($count - ($rows - 1) * $cols) : $cols;
            if ($itemsInRow < $cols && $col === 0) {
                $rowTotalW = ($itemsInRow * $blockW) + (($itemsInRow - 1) * $gap);
                $centreOffset = ($innerW - $rowTotalW) / 2;
                $x += $centreOffset;
            } elseif ($itemsInRow < $cols) {
                $rowTotalW = ($itemsInRow * $blockW) + (($itemsInRow - 1) * $gap);
                $centreOffset = ($innerW - $rowTotalW) / 2;
                $x = $zoneX + $padX + $centreOffset + ($col * ($blockW + $gap));
            }

            $blocks[] = [
                'x' => round($x, 4),
                'y' => round($y, 4),
                'width' => round($blockW, 4),
                'height' => round($blockH, 4),
                'party_role' => $party['role'],
                'party_name' => $party['name'] ?? '',
                'party_email' => $party['email'] ?? null,
                'zone_type' => $zone->zone_type,
            ];
        }

        return $blocks;
    }

    /**
     * Render an initial zone into positioned blocks.
     * Initials stack vertically (one per party, smaller blocks).
     *
     * @param  SignatureZone  $zone
     * @param  array  $parties
     * @return array
     */
    public function renderInitialZone(SignatureZone $zone, array $parties): array
    {
        $count = count($parties);
        if ($count === 0) {
            return [];
        }

        $zoneX = (float) $zone->x_position;
        $zoneY = (float) $zone->y_position;
        $zoneW = (float) $zone->width;
        $zoneH = (float) $zone->height;

        $padY = $zoneH * 0.03;
        $innerH = $zoneH - ($padY * 2);

        // Initials: single column, stacked vertically
        $gap = min($innerH * 0.02, 0.5);
        $blockH = min(($innerH - ($gap * ($count - 1))) / $count, 3);
        $blockW = min($zoneW, 8); // Initials are compact
        $blockH = max($blockH, 1.5);

        // Centre horizontally within zone
        $xOffset = ($zoneW - $blockW) / 2;

        $blocks = [];
        foreach ($parties as $i => $party) {
            $y = $zoneY + $padY + ($i * ($blockH + $gap));

            $blocks[] = [
                'x' => round($zoneX + $xOffset, 4),
                'y' => round($y, 4),
                'width' => round($blockW, 4),
                'height' => round($blockH, 4),
                'party_role' => $party['role'],
                'party_name' => $party['name'] ?? '',
                'party_email' => $party['email'] ?? null,
                'zone_type' => 'initial',
            ];
        }

        return $blocks;
    }

    /**
     * Check if blocks will overflow the zone bounding box.
     *
     * @param  SignatureZone  $zone
     * @param  int  $partyCount
     * @return array  ['overflow' => bool, 'message' => ?string]
     */
    public function checkOverflow(SignatureZone $zone, int $partyCount): array
    {
        $zoneW = (float) $zone->width;
        $zoneH = (float) $zone->height;

        if ($zone->zone_type === 'initial') {
            // Stacked: each initial needs ~2% height minimum
            $needed = $partyCount * 2;
            if ($needed > $zoneH) {
                return [
                    'overflow' => true,
                    'message' => "Warning: {$partyCount} initials may overflow this zone. Consider making it taller.",
                ];
            }
        } else {
            // Grid layout
            $layout = $this->calculateGrid($partyCount);
            $minBlockW = $zoneW / $layout['cols'];
            $minBlockH = $zoneH / $layout['rows'];

            if ($minBlockW < 5 || $minBlockH < 3) {
                return [
                    'overflow' => true,
                    'message' => "Warning: {$partyCount} parties may not fit in this zone. Consider making it larger.",
                ];
            }
        }

        return ['overflow' => false, 'message' => null];
    }

    /**
     * Calculate grid dimensions for a given party count.
     */
    protected function calculateGrid(int $count): array
    {
        if ($count <= 3) {
            return ['cols' => $count, 'rows' => 1];
        }

        // 2 columns for 4+
        $cols = 2;
        $rows = (int) ceil($count / $cols);

        return ['cols' => $cols, 'rows' => $rows];
    }
}
