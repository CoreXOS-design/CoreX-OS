<?php

namespace App\Services\Rentals;

use App\Models\AI\AiUsageEvent;
use App\Services\AI\AnthropicGateway;
use App\Services\AI\DTOs\NarrativeRequest;
use App\Services\AI\DTOs\NarrativeResponse;
use Illuminate\Support\Facades\Log;
use Imagick;
use ImagickDraw;
use ImagickPixel;
use Throwable;

/**
 * AT-472 / .ai/specs/rental-inspection-form.md §13.14 — Johan, 20 Oct 2026: the handwritten note on each row of a scanned site form is read
 * by AI and pre-fills the note box on the review screen (the agent confirms or edits it). This reverses "we do NOT read handwriting, ever".
 *
 * Two jobs, kept apart so the AI part can be swapped for a canned answer in tests:
 *   1. cropNoteArea() — cuts one row's NOTE area out of the straightened page: from the end of the row's last tick box to the right edge of
 *      the page (the writing overflows the printed box), with the corner marks, the QR and the page-identifier grid whited out so printing
 *      is never mistaken for handwriting. Pure image work, no AI. A crop with next to no ink is never sent anywhere.
 *   2. transcribe() — sends the crops that DO have ink through AnthropicGateway: Haiku first, then Sonnet for any answer below
 *      ESCALATE_BELOW confidence. Every call goes through the gateway, so the AI usage ledger (AiUsageRecorder) records tokens and rand cost.
 *
 * The whole step is behind services.anthropic.inspection_note_reading (env RENTAL_INSPECTION_NOTE_READING, default OFF): until Johan says
 * the API key is good, no scan upload makes an AI call. Under that master switch each agency has its own on/off (rental_inspection_settings.
 * note_read_enabled, default ON - audit M7); an agency that switches it off never has a crop of its forms sent anywhere. A failing or absent AI never fails a scan - the marks simply carry no detected note.
 */
class RentalInspectionNoteReadService
{
    /** Share of the crop that must be dark before it counts as "has handwriting" (measured on Johan's Ricoh scan of 20 Oct: writing 0.047-0.10, empty rows <= 0.015). */
    public const INK_MIN = 0.015;

    /** A Haiku answer below this confidence is asked again of Sonnet. */
    public const ESCALATE_BELOW = 0.80;

    private const SYSTEM_PROMPT = "You read handwriting from a cropped image of ONE row's notes area on a printed rental property inspection form. "
        . 'The crop may contain printed lines or box edges, and a stray fragment of writing from a neighbouring row (a tail or a stroke at the top or bottom edge). '
        . "Transcribe ONLY the handwritten words that belong to this row, exactly as written (keep the writer's spelling). "
        . 'If there is no legible handwriting for this row, return an empty text and confidence 0. '
        . 'confidence is your honest certainty (0 to 1) that the transcription is exactly right. '
        . 'Respond ONLY with valid JSON: {"text": "<the handwritten words, or empty string>", "confidence": <number between 0 and 1>}. No markdown, no commentary.';

    public function __construct(private AnthropicGateway $gateway)
    {
    }

    /**
     * Off unless the platform release switch is on AND the platform AI switch is on (the master kill switch above every agency) AND - when an
     * agency is given - that agency has not switched note reading off (audit M7; its setting defaults to ON).
     */
    public function enabled(?int $agencyId = null): bool
    {
        if (! (config('services.anthropic.inspection_note_reading') && config('services.anthropic.enabled'))) {
            return false;
        }

        return $agencyId === null || \App\Models\RentalInspectionSetting::noteReadEnabledFor($agencyId);
    }

    // ── 1. The crop ─────────────────────────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $itemBoxes this row's tick boxes, left to right (manifest boxes)
     * @param array{dx: float, dy: float, measured: bool} $offset the row's measured offset on the straightened page, in points
     * @param array<string, mixed> $geo the page geometry (RentalInspectionScanReaderService::geometryFor())
     * @return array{png: string, ink: float}|null null when there is nothing to crop; `ink` is the dark share 0-1
     */
    public function cropNoteArea(Imagick $canvas, array $itemBoxes, array $offset, array $geo, float $rowHeight): ?array
    {
        if ($itemBoxes === []) {
            return null;
        }
        $px = RentalInspectionScanGeometry::CANVAS_PX_PER_PT;
        $first = $itemBoxes[0];
        $last = $itemBoxes[count($itemBoxes) - 1];
        $pitch = count($itemBoxes) > 1 ? (float) $itemBoxes[1]['x'] - (float) $first['x'] : (float) $last['width'] * 2;

        $x = (float) $last['x'] + $pitch + (float) $offset['dx'] + 1.5;
        $y = (float) $first['y'] - (($rowHeight - (float) $first['height']) / 2) + (float) $offset['dy'] + 1.0;
        $w = (float) $geo['width'] - 12.0 - $x;
        $h = $rowHeight - 2.0;
        $pxX = (int) round($x * $px);
        $pxY = (int) round($y * $px);
        $pxW = (int) round($w * $px);
        $pxH = (int) round($h * $px);
        if ($pxW < 30 || $pxH < 10 || $pxX < 0 || $pxY < 0 || $pxX + $pxW > $canvas->getImageWidth() || $pxY + $pxH > $canvas->getImageHeight()) {
            return null;
        }

        $crop = $canvas->getImageRegion($pxW, $pxH, $pxX, $pxY);
        $crop->setImagePage(0, 0, 0, 0);

        // Printing that can sit in the right-hand part of a row: the corner marks, the QR, the page-identifier grid.
        $fiducial = (float) ($geo['fiducial_size'] ?? 0);
        $mask = [];
        foreach (($geo['fiducial_points'] ?? []) as $pt) {
            $mask[] = [(float) $pt[0] - $fiducial, (float) $pt[1] - $fiducial, 2 * $fiducial, 2 * $fiducial];
        }
        if (! empty($geo['qr'])) {
            $mask[] = [(float) $geo['qr']['x'] - 3, (float) $geo['qr']['y'] - 3, (float) $geo['qr']['size'] + 6, (float) $geo['qr']['size'] + 6];
        }
        $grid = $geo['grid'] ?? RentalInspectionFormPdfService::pageIdentifierGridLayout((float) $geo['width']);
        $mask[] = [
            (float) $grid['x'] - 4, (float) $grid['y'] - 4,
            $grid['cols'] * $grid['bit_size'] + ($grid['cols'] - 1) * $grid['bit_gap'] + 8,
            $grid['rows'] * $grid['bit_size'] + ($grid['rows'] - 1) * $grid['bit_gap'] + 8,
        ];
        $draw = new ImagickDraw();
        $draw->setFillColor(new ImagickPixel('white'));
        foreach ($mask as [$mx, $my, $mw, $mh]) {
            if ($mx < $x + $w && $mx + $mw > $x && $my < $y + $h && $my + $mh > $y) {
                $draw->rectangle(($mx - $x) * $px, ($my - $y) * $px, ($mx + $mw - $x) * $px, ($my + $mh - $y) * $px);
            }
        }
        $crop->drawImage($draw);

        $dark = clone $crop;
        $dark->setImageColorspace(Imagick::COLORSPACE_GRAY);
        $dark->thresholdImage(0.5 * Imagick::getQuantum());
        $ink = 1.0 - ($dark->getImageChannelMean(Imagick::CHANNEL_GRAY)['mean'] / Imagick::getQuantum());
        $dark->clear();

        $crop->setImageFormat('png');
        $png = $crop->getImageBlob();
        $crop->clear();

        return ['png' => $png, 'ink' => round($ink, 4)];
    }

    // ── 2. The read ─────────────────────────────────────────────────────────────────────────────

    /**
     * Haiku for every crop, then Sonnet for the answers below ESCALATE_BELOW. A crop whose call failed, was paused or came back unreadable is
     * simply absent from the result.
     *
     * @param array<int|string, array{png: string, label: string, agency_id: int}> $crops keyed by the caller's own key
     * @return array<int|string, array{text: string, confidence: float, model: string, cost_zar: float}>
     */
    public function transcribe(array $crops): array
    {
        if ($crops === []) {
            return [];
        }

        $answers = $this->ask($crops, 'fast');
        $unsure = array_filter($crops, fn ($_, $key) => ! isset($answers[$key]) || $answers[$key]['confidence'] < self::ESCALATE_BELOW, ARRAY_FILTER_USE_BOTH);
        if ($unsure !== []) {
            foreach ($this->ask($unsure, 'quality') as $key => $better) {
                $answers[$key] = $better;
            }
        }

        return $answers;
    }

    /**
     * @param array<int|string, array{png: string, label: string, agency_id: int}> $crops
     * @return array<int|string, array{text: string, confidence: float, model: string, cost_zar: float}>
     */
    private function ask(array $crops, string $modelAlias): array
    {
        $requests = [];
        foreach ($crops as $key => $crop) {
            $requests[$key] = new NarrativeRequest(
                narrativeType: 'inspection_note_read',
                cacheKey: 'inspection-note:' . sha1($crop['png']) . ':' . $modelAlias,
                modelAlias: $modelAlias,
                systemPrompt: self::SYSTEM_PROMPT,
                userPrompt: 'Inspection item (printed label, for context only): ' . $crop['label'] . '. Transcribe the handwriting in the attached image.',
                inputData: ['crop' => sha1($crop['png']), 'alias' => $modelAlias],
                maxTokens: 200,
                temperature: 0.0,
                cacheTtlMinutes: 10080,
                agencyId: (int) $crop['agency_id'],
                documents: [['type' => 'image', 'media_type' => 'image/png', 'data' => base64_encode($crop['png'])]],
                source: AiUsageEvent::SOURCE_IMAGE_ANALYSIS,
            );
        }

        try {
            $responses = $this->gateway->generateMany($requests);
        } catch (Throwable $e) {
            Log::warning('Inspection note read: the AI call failed', ['model' => $modelAlias, 'error' => $e->getMessage()]);

            return [];
        }

        $out = [];
        foreach ($responses as $key => $response) {
            if (! $response instanceof NarrativeResponse) {
                Log::warning('Inspection note read: one crop failed', ['model' => $modelAlias, 'error' => $response instanceof Throwable ? $response->getMessage() : 'unknown']);

                continue;
            }
            if ($response->isDegraded() || ($parsed = $this->parse($response)) === null) {
                continue;
            }
            $out[$key] = $parsed;
        }

        return $out;
    }

    /** @return array{text: string, confidence: float, model: string, cost_zar: float}|null */
    private function parse(NarrativeResponse $response): ?array
    {
        $json = $response->outputJson;
        if ($json === null) {
            $json = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', trim($response->outputText))), true);
        }
        if (! is_array($json)) {
            return null;
        }

        return [
            'text' => mb_substr(trim((string) ($json['text'] ?? '')), 0, 4000),
            'confidence' => round(max(0.0, min(1.0, (float) ($json['confidence'] ?? 0))), 2),
            'model' => $response->model,
            'cost_zar' => $response->costZar,
        ];
    }
}
