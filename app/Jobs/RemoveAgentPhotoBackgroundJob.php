<?php

namespace App\Jobs;

use App\Models\Agency;
use App\Models\User;
use App\Models\UserDocument;
use App\Services\Images\BackgroundRemoval\BackgroundRemovalException;
use App\Services\Images\BackgroundRemoval\BackgroundRemovalManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Sends ONE agent photo to the configured AI background-removal driver
 * (Photoroom or remove.bg — see BackgroundRemovalManager) and stores the
 * transparent PNG alongside the original, on its OWN queue lane
 * (`bg_removal`) so it never competes with or blocks default/matching/mail
 * work. Dispatched exactly once per upload/replace, from
 * AgentProfilePhotoService::set() — never per ad render, never per
 * property. See ad-manager.md §15.2.
 *
 * Full failure handling: ANY failure (bad/missing key, timeout, quota, bad
 * response) leaves the ORIGINAL photo as the only photo. Every ad surface
 * already falls back to the plain photo whenever no cutout path is
 * recorded (User::profilePhotoCutoutUrl() returns null on anything but
 * bg_removal_status = 'done'), so this job can never blank or break an ad —
 * it can only ever add a cutout, never remove the fallback.
 */
class RemoveAgentPhotoBackgroundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 3;

    /** Escalating waits between retries — give a rate-limited/down API room to recover. */
    public array $backoff = [30, 120, 300];

    /**
     * @param  int  $userId
     * @param  string  $originalPath  The public-disk relative path captured AT DISPATCH TIME
     *                                (e.g. "agents/42/photo.webp"). NOT content-addressed —
     *                                AgentPhotoNormalizer always writes the SAME path for a
     *                                given user, overwriting the previous file's bytes in
     *                                place (the exact gotcha behind the P24 round-4 "which
     *                                file did this property actually use" investigation) — so
     *                                the path alone cannot detect a same-user replacement.
     * @param  string  $expectedFileHash  md5() of the file's bytes AT DISPATCH TIME. Re-hashed
     *                                    against the CURRENT file before writing a cutout, so a
     *                                    job still in flight for an old photo can never attach
     *                                    its cutout to a DIFFERENT photo that has since
     *                                    overwritten the same path (the same class of race
     *                                    properties.p24_image_signature exists to prevent).
     */
    public function __construct(
        public int $userId,
        public string $originalPath,
        public string $expectedFileHash,
    ) {
        $this->onQueue('bg_removal');
    }

    public function handle(BackgroundRemovalManager $manager): void
    {
        $user = User::withoutGlobalScopes()->find($this->userId);
        if (! $user || $user->agent_photo_path !== $this->originalPath) {
            // Photo removed, or a DIFFERENT path entirely (e.g. re-uploaded
            // under a changed userId scheme) — nothing left to process.
            Log::info('bg_removal.superseded', ['user_id' => $this->userId, 'path' => $this->originalPath]);

            return;
        }

        $doc = $user->documents()
            ->where('document_type', UserDocument::DOCUMENT_TYPE_PROFILE_PHOTO)
            ->latest()
            ->first();
        if (! $doc) {
            return;
        }

        // Superseded — a newer upload already overwrote THIS SAME PATH with a
        // DIFFERENT photo's bytes before this job got to run. Writing a
        // cutout now would attach the WRONG cutout to the CURRENT photo.
        $currentBytes = Storage::disk('public')->exists($this->originalPath)
            ? Storage::disk('public')->get($this->originalPath)
            : null;
        if ($currentBytes === null || md5($currentBytes) !== $this->expectedFileHash) {
            Log::info('bg_removal.superseded', ['user_id' => $this->userId, 'path' => $this->originalPath]);

            return;
        }

        $agency = $user->agency_id ? Agency::withoutGlobalScopes()->find($user->agency_id) : null;
        if ($agency && ! $agency->ad_bg_removal_api_enabled) {
            Log::info('bg_removal.agency_disabled', ['user_id' => $this->userId, 'agency_id' => $agency->id]);

            return;
        }

        $doc->update(['bg_removal_status' => 'processing']);

        $absolutePath = Storage::disk('public')->path($this->originalPath);
        $driver = $manager->driver();

        try {
            $result = $driver->removeBackground($absolutePath);
        } catch (BackgroundRemovalException $e) {
            Log::warning('bg_removal.api_call_failed', [
                'driver'      => $driver->name(),
                'user_id'     => $this->userId,
                'attempt'     => $this->attempts(),
                'http_status' => $e->httpStatus,
                'error'       => $e->getMessage(),
            ]);

            // Rethrow so $tries/$backoff engage — a transient failure (timeout,
            // rate limit) gets retried automatically. Once attempts are
            // exhausted, Laravel calls failed() below, which is the only place
            // bg_removal_status is persisted as 'failed' (never mid-retry, so
            // an admin never sees a false-terminal state for a retry in flight).
            throw $e;
        }

        $cutoutPath = "agents/{$this->userId}/photo-cutout.png";
        Storage::disk('public')->put($cutoutPath, $result->pngContents);

        $doc->update([
            'bg_removal_status'       => 'done',
            'bg_removal_cutout_path'  => $cutoutPath,
            'bg_removal_driver'       => $result->driver,
            'bg_removal_processed_at' => now(),
            'bg_removal_error'        => null,
        ]);

        Log::info('bg_removal.api_call_succeeded', [
            'driver'       => $result->driver,
            'user_id'      => $this->userId,
            'cost_credits' => $result->costCredits,
        ]);
    }

    /** Terminal failure — every retry exhausted. The original photo remains the only photo. */
    public function failed(\Throwable $e): void
    {
        $user = User::withoutGlobalScopes()->find($this->userId);
        $doc = $user
            ? $user->documents()->where('document_type', UserDocument::DOCUMENT_TYPE_PROFILE_PHOTO)->latest()->first()
            : null;

        if ($doc) {
            $doc->update([
                'bg_removal_status'       => 'failed',
                'bg_removal_driver'       => app(BackgroundRemovalManager::class)->driverName(),
                'bg_removal_processed_at' => now(),
                'bg_removal_error'        => $e->getMessage(),
            ]);
        }

        Log::error('bg_removal.terminally_failed', [
            'user_id' => $this->userId,
            'error'   => $e->getMessage(),
        ]);
    }
}
