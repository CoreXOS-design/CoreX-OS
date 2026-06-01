<?php

namespace App\Jobs;

use App\Models\PropertyImageAnalysis;
use App\Services\AI\VisionRecognitionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Analyse a single property image with Claude Haiku 4.5 vision.
 * Writes detected features + spaces to property_image_analyses for the
 * agent to review on the property edit page.
 */
class AnalysePropertyImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 120;

    public function __construct(public int $analysisId) {}

    public function handle(VisionRecognitionService $vision): void
    {
        $analysis = PropertyImageAnalysis::find($this->analysisId);
        if (!$analysis || in_array($analysis->status, ['complete', 'failed'], true)) {
            return;
        }

        $analysis->update(['status' => 'processing']);

        // Resolve the absolute path. image_path is stored relative to the public disk
        // (matches how MobilePropertyController writes property images).
        $absolute = Storage::disk('public')->path($analysis->image_path);
        if (!is_readable($absolute)) {
            $analysis->update([
                'status' => 'failed',
                'error'  => 'Image not readable at ' . $analysis->image_path,
                'processed_at' => now(),
            ]);
            return;
        }

        try {
            $result = $vision->analyseImage($absolute);

            $analysis->update([
                'status'            => 'complete',
                'detected_features' => $result['features'],
                'detected_spaces'   => $result['spaces'],
                'raw_response'      => ['text' => $result['raw']],
                'cost_usd'          => $result['cost_usd'],
                'processed_at'      => now(),
                'error'             => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AnalysePropertyImageJob: failed', [
                'analysis_id' => $this->analysisId,
                'error'       => $e->getMessage(),
            ]);
            $analysis->update([
                'status' => 'failed',
                'error'  => mb_substr($e->getMessage(), 0, 1000),
                'processed_at' => now(),
            ]);
            throw $e; // let queue retry
        }
    }

    public function failed(\Throwable $e): void
    {
        PropertyImageAnalysis::where('id', $this->analysisId)->update([
            'status' => 'failed',
            'error'  => mb_substr('Permanent failure: ' . $e->getMessage(), 0, 1000),
            'processed_at' => now(),
        ]);
    }
}
