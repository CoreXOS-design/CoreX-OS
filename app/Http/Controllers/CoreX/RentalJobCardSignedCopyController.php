<?php

namespace App\Http\Controllers\CoreX;

use App\Events\Rentals\RentalJobCardCrewCompleted;
use App\Events\Rentals\RentalJobCardSignedCopyUploaded;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardSignedCopy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * .ai/specs/rental-work-orders.md §14.27.5 / §14.28 — the wet-ink route: the
 * crew brings the signed job card back to the office and it is uploaded
 * against the card. pdf / jpg / png, 10240 KB, PRIVATE disk, served only
 * through the authenticated download route (guardRentalRecordScope). An
 * earlier upload is kept and stamped Superseded — never hard-deleted.
 *
 * Uploading records the same worker sign-off the crew link does (via
 * `signed_copy`, the name typed here, the uploader's IP / device) — and never
 * closes the card. Refused on a Cancelled card; allowed on a Completed card
 * (paper often arrives late) where it is only filed, completion unchanged.
 */
class RentalJobCardSignedCopyController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function store(Request $request, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        if ($rentalJobCard->status === RentalJobCard::STATUS_CANCELLED) {
            return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)
                ->withErrors(['signed_copy' => 'This job card is cancelled — a signed copy can no longer be uploaded.']);
        }

        $data = $request->validate([
            'signed_copy' => ['required', 'file', 'mimes:' . implode(',', RentalJobCardSignedCopy::ALLOWED_EXTENSIONS), 'max:' . RentalJobCardSignedCopy::MAX_SIZE_KB],
            'signed_by_name' => ['required', 'string', 'min:2', 'max:191'],
        ], [
            'signed_copy.mimes' => 'The signed copy must be a PDF, JPG or PNG.',
            'signed_copy.max' => 'The signed copy can be up to 10 MB.',
            'signed_by_name.required' => 'Type the name of the person who signed.',
        ]);

        $file = $request->file('signed_copy');
        $path = $file->store("rental-job-card-signed-copies/{$rentalJobCard->id}", 'local');
        $recordsCompletion = ! $rentalJobCard->isClosed();
        $signedBy = trim((string) preg_replace('/\s+/', ' ', $data['signed_by_name']));

        try {
            [$copy, $supersededCount] = DB::transaction(function () use ($request, $rentalJobCard, $file, $path, $signedBy, $recordsCompletion) {
                $copy = RentalJobCardSignedCopy::create([
                    'agency_id' => $rentalJobCard->agency_id,
                    'rental_job_card_id' => $rentalJobCard->id,
                    'storage_path' => $path,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'mime_type' => (string) ($file->getMimeType() ?: $file->getClientMimeType()),
                    'size_kb' => (int) ceil($file->getSize() / 1024),
                    'signed_by_name' => $signedBy,
                    'uploaded_by_user_id' => $request->user()->id,
                    'uploaded_at' => now(),
                ]);

                // Keep every earlier copy; mark it Superseded by this one.
                $earlier = RentalJobCardSignedCopy::query()
                    ->where('rental_job_card_id', $rentalJobCard->id)
                    ->whereNull('superseded_at')->where('id', '!=', $copy->id)->get();
                foreach ($earlier as $old) {
                    $old->forceFill(['superseded_at' => now(), 'superseded_by_id' => $copy->id])->save();
                }

                $rentalJobCard->logUpdate('signed_copy_uploaded', $request->user(), "Signed copy uploaded — {$copy->original_name} (signed by {$signedBy})");
                if ($earlier->isNotEmpty()) {
                    $rentalJobCard->logUpdate('signed_copy_superseded', $request->user(), $earlier->count() === 1 ? 'The earlier signed copy was kept and marked Superseded' : "{$earlier->count()} earlier signed copies were kept and marked Superseded");
                }

                // The same worker sign-off the crew link records. Open cards only —
                // a Completed card just files the paper, completion unchanged.
                if ($recordsCompletion) {
                    $rentalJobCard->recordCrewCompletion(
                        $signedBy, RentalJobCard::SIGN_OFF_VIA_SIGNED_COPY, $request->ip(),
                        $request->userAgent() ? mb_substr((string) $request->userAgent(), 0, 255) : null, $request->user(),
                    );
                }

                return [$copy, $earlier->count()];
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path); // nothing recorded points at it
            throw $e;
        }

        RentalJobCardSignedCopyUploaded::dispatch($rentalJobCard, $copy->id, $supersededCount > 0, $request->user()->id);
        if ($recordsCompletion) {
            RentalJobCardCrewCompleted::dispatch($rentalJobCard, $signedBy, RentalJobCard::SIGN_OFF_VIA_SIGNED_COPY, $request->user()->id);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)
            ->with('success', 'Signed copy uploaded' . ($recordsCompletion ? ' and the crew completion recorded.' : '.'));
    }

    public function download(Request $request, RentalJobCard $rentalJobCard, int $copy)
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        // Scoped to THIS card by the query — another card's copy id is a 404.
        $row = RentalJobCardSignedCopy::query()->where('rental_job_card_id', $rentalJobCard->id)->findOrFail($copy);
        abort_unless(Storage::disk('local')->exists($row->storage_path), 404);

        return Storage::disk('local')->response($row->storage_path, $row->original_name, [
            'Content-Type' => $row->mime_type,
            'Content-Disposition' => 'inline; filename="' . addslashes($row->original_name) . '"',
        ]);
    }
}
