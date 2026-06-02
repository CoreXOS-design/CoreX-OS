<?php

namespace App\Jobs;

use App\Models\AgencyWebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Delivers one webhook to an agency website. Signs the raw JSON body with the
 * key's webhook_secret (HMAC-SHA256, X-CoreX-Signature) exactly as the PP
 * webhook verifies, retries with exponential backoff, and records the outcome
 * on the AgencyWebhookDelivery row so the UI can surface a dead endpoint.
 *
 * Spec: .ai/specs/agency-public-api.md §6.2
 */
class DeliverAgencyWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 30;

    /** Exponential backoff between attempts (seconds): 1m, 5m, 30m, 2h, 6h. */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 21600];
    }

    public function __construct(public readonly int $deliveryId)
    {
    }

    public function handle(): void
    {
        $delivery = AgencyWebhookDelivery::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->with('apiKey')
            ->find($this->deliveryId);

        if (!$delivery || $delivery->delivered_at !== null) {
            return; // already gone or already delivered
        }

        $key = $delivery->apiKey;
        if (!$key || !$key->webhook_url || !$key->webhook_secret || !$key->isActive()) {
            $delivery->forceFill([
                'failed_at'  => Carbon::now(),
                'last_error' => 'Key missing webhook_url/secret or inactive.',
                'attempts'   => $delivery->attempts + 1,
            ])->save();
            return;
        }

        $body = json_encode([
            'event'       => $delivery->event_name,
            'occurred_at' => $delivery->created_at?->toIso8601String(),
            'agency_id'   => $delivery->agency_id,
            'data'        => $delivery->payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $signature = hash_hmac('sha256', $body, $key->webhook_secret);

        try {
            $response = Http::withHeaders([
                'Content-Type'     => 'application/json',
                'X-CoreX-Signature' => $signature,
                'X-CoreX-Event'     => $delivery->event_name,
            ])->timeout($this->timeout)->withBody($body, 'application/json')->post($key->webhook_url);

            $delivery->forceFill([
                'attempts'        => $delivery->attempts + 1,
                'response_status' => $response->status(),
            ]);

            if ($response->successful()) {
                $delivery->forceFill(['delivered_at' => Carbon::now(), 'last_error' => null, 'next_retry_at' => null])->save();
                return;
            }

            $delivery->last_error = 'HTTP ' . $response->status();
            $this->scheduleRetryOrFail($delivery);
        } catch (\Throwable $e) {
            $delivery->forceFill([
                'attempts'   => $delivery->attempts + 1,
                'last_error' => $e->getMessage(),
            ]);
            $this->scheduleRetryOrFail($delivery);
            // Re-throw so the queue applies backoff() and retries up to $tries.
            throw $e;
        }
    }

    private function scheduleRetryOrFail(AgencyWebhookDelivery $delivery): void
    {
        if ($delivery->attempts >= $this->tries) {
            $delivery->failed_at = Carbon::now();
            $delivery->next_retry_at = null;
            Log::warning("Agency webhook delivery #{$delivery->id} failed after {$delivery->attempts} attempts: {$delivery->last_error}");
        } else {
            $backoff = $this->backoff();
            $secs = $backoff[min($delivery->attempts, count($backoff) - 1)] ?? 3600;
            $delivery->next_retry_at = Carbon::now()->addSeconds($secs);
        }
        $delivery->save();
    }

    /** Final failure after all retries exhausted by the queue. */
    public function failed(\Throwable $e): void
    {
        $delivery = AgencyWebhookDelivery::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->find($this->deliveryId);
        if ($delivery && $delivery->delivered_at === null && $delivery->failed_at === null) {
            $delivery->forceFill(['failed_at' => Carbon::now(), 'last_error' => $e->getMessage()])->save();
        }
    }
}
