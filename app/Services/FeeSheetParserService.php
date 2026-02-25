<?php

namespace App\Services;

class FeeSheetParserService
{
    /**
     * Parse a Van Dyk & Swart (or similar attorney) cost sheet PDF.
     * Returns bracket arrays for conveyancing, deeds_office, transfer_duty.
     */
    public function parse(string $pdfPath): array
    {
        $text = $this->extractText($pdfPath);
        $lines = explode("\n", $text);

        $convBrackets = [];
        $deedsBrackets = [];
        $dutyDataPoints = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $numbers = $this->extractNumbers($line);

            // The cost sheet has rows with at least 7 numbers:
            // Price, Conv, Posts, VAT, Deeds, Duty, Total (transfer side)
            // Then optionally: Conv, Posts, VAT, Deeds, Total (bond side)
            if (count($numbers) >= 7) {
                $price = $numbers[0];

                // Sanity check: price should be >= 100,000
                if ($price < 50000) continue;

                $convFee = $numbers[1];
                $deedsFee = $numbers[4];
                $duty = $numbers[5];

                if ($convFee > 0) {
                    $convBrackets[] = ['max' => $price, 'fee' => $convFee];
                }
                if ($deedsFee > 0) {
                    $deedsBrackets[] = ['max' => $price, 'fee' => $deedsFee];
                }
                $dutyDataPoints[] = ['price' => $price, 'duty' => $duty];
            }
        }

        // Deduplicate brackets (same fee for multiple prices → keep highest price)
        $convBrackets = $this->deduplicateBrackets($convBrackets);
        $deedsBrackets = $this->deduplicateBrackets($deedsBrackets);

        // Convert duty data points to rate brackets
        $dutyRateBrackets = $this->inferDutyBrackets($dutyDataPoints);

        // Extract additional costs note (NB!! section)
        $additionalCosts = $this->extractAdditionalCosts($text);

        return [
            'conveyancing' => $convBrackets,
            'deeds_office' => $deedsBrackets,
            'transfer_duty' => $dutyRateBrackets,
            'additional_costs' => $additionalCosts,
        ];
    }

    private function deduplicateBrackets(array $brackets): array
    {
        $grouped = [];
        foreach ($brackets as $b) {
            $fee = (float) $b['fee'];
            $max = (float) $b['max'];
            if (!isset($grouped[$fee]) || $max > $grouped[$fee]) {
                $grouped[$fee] = $max;
            }
        }

        $result = [];
        foreach ($grouped as $fee => $maxPrice) {
            $result[] = ['max' => $maxPrice, 'fee' => $fee];
        }

        usort($result, fn($a, $b) => $a['max'] <=> $b['max']);
        return $result;
    }

    /**
     * Infer progressive duty rate brackets from price→duty data points.
     * Uses finite differences between consecutive data points.
     */
    private function inferDutyBrackets(array $dataPoints): array
    {
        // Sort by price
        usort($dataPoints, fn($a, $b) => $a['price'] <=> $b['price']);

        if (count($dataPoints) < 2) {
            return [];
        }

        // Find the zero-duty threshold
        $zeroBoundary = 0;
        foreach ($dataPoints as $dp) {
            if ($dp['duty'] == 0) {
                $zeroBoundary = $dp['price'];
            } else {
                break;
            }
        }

        $brackets = [];
        $brackets[] = ['from' => 0, 'to' => $zeroBoundary, 'rate' => 0.00];

        // Calculate marginal rates between consecutive points
        $prevPrice = $zeroBoundary;
        $prevDuty = 0;
        $currentRate = null;
        $currentFrom = $zeroBoundary;

        foreach ($dataPoints as $dp) {
            if ($dp['price'] <= $zeroBoundary) continue;
            if ($dp['duty'] <= 0) continue;

            $priceDiff = $dp['price'] - $prevPrice;
            $dutyDiff = $dp['duty'] - $prevDuty;

            if ($priceDiff > 0) {
                $marginalRate = round($dutyDiff / $priceDiff, 4);

                // Round to common SA rate values
                $marginalRate = $this->snapToCommonRate($marginalRate);

                if ($currentRate === null) {
                    $currentRate = $marginalRate;
                    $currentFrom = $zeroBoundary;
                } elseif (abs($marginalRate - $currentRate) > 0.005) {
                    // Rate changed — close previous bracket, start new one
                    $brackets[] = ['from' => $currentFrom, 'to' => $prevPrice, 'rate' => $currentRate];
                    $currentFrom = $prevPrice;
                    $currentRate = $marginalRate;
                }
            }

            $prevPrice = $dp['price'];
            $prevDuty = $dp['duty'];
        }

        // Close final bracket
        if ($currentRate !== null) {
            $brackets[] = ['from' => $currentFrom, 'to' => 999999999, 'rate' => $currentRate];
        }

        return $brackets;
    }

    private function snapToCommonRate(float $rate): float
    {
        $common = [0.00, 0.03, 0.05, 0.06, 0.08, 0.10, 0.11, 0.13, 0.15];
        $closest = $common[0];
        $minDiff = abs($rate - $closest);

        foreach ($common as $c) {
            $diff = abs($rate - $c);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $closest = $c;
            }
        }

        return ($minDiff < 0.015) ? $closest : $rate;
    }

    /**
     * Extract numbers from a cost sheet line.
     * Cost sheets use "1 500 000.00" format (spaces in thousands).
     */
    private function extractNumbers(string $line): array
    {
        // Match decimal numbers with possible spaces in thousands
        preg_match_all('/[\d\s]+\.\d{2}/', $line, $matches);

        $numbers = [];
        foreach ($matches[0] as $match) {
            $clean = str_replace(' ', '', trim($match));
            $val = (float) $clean;
            if ($val >= 0) {
                $numbers[] = $val;
            }
        }

        return $numbers;
    }

    private function extractText(string $pdfPath): string
    {
        // Use pdftotext with layout preservation
        $output = [];
        $code = 0;
        exec("pdftotext -layout " . escapeshellarg($pdfPath) . " - 2>&1", $output, $code);
        if ($code === 0 && !empty($output)) {
            return implode("\n", $output);
        }

        // Fallback: pdftotext without layout
        $output = [];
        exec("pdftotext " . escapeshellarg($pdfPath) . " - 2>&1", $output, $code);
        if ($code === 0 && !empty($output)) {
            return implode("\n", $output);
        }

        throw new \RuntimeException("Cannot extract text from PDF. Ensure poppler-utils (pdftotext) is installed.");
    }

    private function extractAdditionalCosts(string $text): ?string
    {
        // Look for NB!! or "Additional" section
        if (preg_match('/NB[\s!]*![\s\S]*?(?=\n\n|\z)/i', $text, $match)) {
            return trim($match[0]);
        }
        if (preg_match('/Additional\s+Costs?[\s\S]*?(?=\n\n|\z)/i', $text, $match)) {
            return trim($match[0]);
        }
        return null;
    }
}
