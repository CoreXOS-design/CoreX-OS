<?php

namespace App\Services\Auctions;

use App\Events\Auction\BidderApproved;
use App\Events\Auction\BidderDeclined;
use App\Events\Auction\BidderRegistered;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionRegistration;
use App\Models\AuctionRegistrationDocument;
use App\Models\BidderDocument;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Bidder registration (.ai/specs/auctions.md §8.3): a signed-in buyer registers for ONE auction with the documents the
 * agency requires (uploading new ones, or ticking "use existing" for ones already on file with this agency); staff
 * review each document, then approve (which issues the bidder number) or decline. Only an approved registration may bid.
 */
class AuctionRegistrationService
{
    /** When registration stops: the auction's own deadline, else N hours (agency setting) before it starts. */
    public function deadline(Auction $auction): ?Carbon
    {
        if ($auction->registration_closes_at) {
            return $auction->registration_closes_at;
        }

        return $auction->starts_at
            ? $auction->starts_at->copy()->subHours(AgencyAuctionSettings::registrationClosesHoursFor((int) $auction->agency_id))
            : null;
    }

    public function isOpen(Auction $auction): bool
    {
        if (! in_array($auction->status, [Auction::STATUS_PUBLISHED, Auction::STATUS_OPEN], true)) {
            return false;
        }
        $deadline = $this->deadline($auction);

        return $deadline === null || now()->lt($deadline);
    }

    /** @return array<int, string> document kinds this bidder type must supply */
    public function requiredKinds(Auction $auction, string $bidderType): array
    {
        $agencyId = (int) $auction->agency_id;

        return array_values($bidderType === 'company'
            ? AgencyAuctionSettings::docsRequiredCompanyFor($agencyId)
            : AgencyAuctionSettings::docsRequiredIndividualFor($agencyId));
    }

    /** Documents this buyer already has on file with the agency, newest first per kind (expired ones left out). */
    public function onFile(ClientUser $user, int $agencyId): Collection
    {
        return BidderDocument::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('agency_id', $agencyId)->where('client_user_id', $user->id)
            ->orderByDesc('id')->get()->reject(fn (BidderDocument $d) => $d->isExpired())->groupBy('kind');
    }

    public function forBuyer(Auction $auction, ClientUser $user): ?AuctionRegistration
    {
        return AuctionRegistration::withoutGlobalScopes()->where('auction_id', $auction->id)->where('client_user_id', $user->id)->first();
    }

    /**
     * @param array{bidder_type: string, company_name?: ?string, company_reg_no?: ?string, accept_conditions?: mixed} $data
     * @param array<string, UploadedFile> $uploads  kind => file
     * @param array<string, int|string> $existing   kind => bidder_documents.id the buyer chose to reuse
     *
     * @throws ValidationException
     */
    public function submit(Auction $auction, ClientUser $user, Contact $contact, array $data, array $uploads, array $existing, ?string $ip): AuctionRegistration
    {
        if (! $this->isOpen($auction)) {
            throw ValidationException::withMessages(['registration' => 'Registration for this auction is closed.']);
        }
        $type = ($data['bidder_type'] ?? 'individual') === 'company' ? 'company' : 'individual';
        if ($type === 'company' && trim((string) ($data['company_name'] ?? '')) === '') {
            throw ValidationException::withMessages(['company_name' => 'Enter the company name.']);
        }
        if (empty($data['accept_conditions'])) {
            throw ValidationException::withMessages(['accept_conditions' => 'Please accept the conditions of the auction to register.']);
        }

        $registration = $this->forBuyer($auction, $user);
        if ($registration && $registration->isApproved()) {
            throw ValidationException::withMessages(['registration' => 'You are already approved to bid in this auction.']);
        }

        // Resolve every required document BEFORE writing anything.
        $onFile = $this->onFile($user, (int) $auction->agency_id)->flatten()->keyBy('id');
        $plan = [];
        foreach ($this->requiredKinds($auction, $type) as $kind) {
            $label = AgencyAuctionSettings::DOCUMENT_KINDS[$kind] ?? $kind;
            if (isset($uploads[$kind]) && $uploads[$kind] instanceof UploadedFile) {
                $plan[$kind] = ['upload' => $uploads[$kind]];
            } elseif (! empty($existing[$kind]) && ($doc = $onFile->get((int) $existing[$kind])) && $doc->kind === $kind) {
                $plan[$kind] = ['doc' => $doc];
            } else {
                throw ValidationException::withMessages(["documents.{$kind}" => "Please add your {$label}."]);
            }
        }

        $registration = DB::transaction(function () use ($auction, $user, $contact, $data, $type, $plan, $registration, $ip) {
            $fields = [
                'contact_id' => $contact->id,
                'bidder_type' => $type,
                'company_name' => $type === 'company' ? mb_substr(trim((string) $data['company_name']), 0, 190) : null,
                'company_reg_no' => $type === 'company' ? mb_substr(trim((string) ($data['company_reg_no'] ?? '')), 0, 60) ?: null : null,
                'status' => AuctionRegistration::STATUS_PENDING,
                'declined_reason' => null,
                'approved_by' => null,
                'approved_at' => null,
                'bidder_number' => null,
                'accepted_conditions_at' => now(),
                'accepted_conditions_ip' => $ip,
            ];

            if ($registration) {
                $registration->forceFill($fields)->save();
                $registration->documents()->get()->each->delete();   // archived, never hard-deleted
            } else {
                $registration = AuctionRegistration::withoutAgencyStamping(fn () => AuctionRegistration::create($fields + [
                    'agency_id' => $auction->agency_id, 'auction_id' => $auction->id, 'client_user_id' => $user->id,
                ]));
            }

            foreach ($plan as $kind => $item) {
                $doc = $item['doc'] ?? $this->store($auction, $user, $kind, $item['upload']);
                AuctionRegistrationDocument::withoutAgencyStamping(fn () => AuctionRegistrationDocument::create([
                    'agency_id' => $auction->agency_id, 'registration_id' => $registration->id, 'bidder_document_id' => $doc->id,
                    'kind' => $kind, 'status' => AuctionRegistrationDocument::STATUS_SUBMITTED,
                ]));
            }

            return $registration;
        });

        BidderRegistered::dispatch($registration);

        return $registration;
    }

    /** Required kinds that do not yet have an ACCEPTED document. */
    public function missingKinds(AuctionRegistration $reg): array
    {
        $accepted = $reg->documents()->withoutGlobalScopes()->whereNull('deleted_at')->where('status', AuctionRegistrationDocument::STATUS_ACCEPTED)->pluck('kind')->all();

        return array_values(array_diff($this->requiredKinds($reg->auction, $reg->bidder_type), $accepted));
    }

    public function reviewDocument(AuctionRegistrationDocument $doc, User $by, bool $accept, ?string $reason = null): AuctionRegistrationDocument
    {
        if (! $accept && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Say why the document is rejected, so the buyer knows what to send.']);
        }
        $doc->forceFill([
            'status' => $accept ? AuctionRegistrationDocument::STATUS_ACCEPTED : AuctionRegistrationDocument::STATUS_REJECTED,
            'reviewed_by' => $by->id, 'reviewed_at' => now(),
            'reject_reason' => $accept ? null : mb_substr(trim((string) $reason), 0, 300),
        ])->save();

        return $doc;
    }

    /**
     * Approve the bidder and issue their number (1, 2, 3… within the auction).
     *
     * @throws ValidationException
     */
    public function approve(AuctionRegistration $reg, User $by): AuctionRegistration
    {
        $reg = DB::transaction(function () use ($reg, $by) {
            // Serialise number issuing per auction.
            $auction = Auction::withTrashed()->withoutGlobalScopes()->whereKey($reg->auction_id)->lockForUpdate()->firstOrFail();
            $reg = AuctionRegistration::withoutGlobalScopes()->lockForUpdate()->findOrFail($reg->id);
            $reg->setRelation('auction', $auction);

            if ($reg->status === AuctionRegistration::STATUS_APPROVED) {
                return $reg;
            }
            if (! in_array($reg->status, [AuctionRegistration::STATUS_PENDING, AuctionRegistration::STATUS_DECLINED], true)) {
                throw ValidationException::withMessages(['registration' => 'This registration was withdrawn.']);
            }
            if ($missing = $this->missingKinds($reg)) {
                $names = array_map(fn ($k) => AgencyAuctionSettings::DOCUMENT_KINDS[$k] ?? $k, $missing);
                throw ValidationException::withMessages(['registration' => 'Accept these documents first: ' . implode(', ', $names) . '.']);
            }

            $next = (int) AuctionRegistration::withTrashed()->withoutGlobalScopes()->where('auction_id', $auction->id)->max('bidder_number') + 1;
            $reg->forceFill([
                'status' => AuctionRegistration::STATUS_APPROVED, 'bidder_number' => $reg->bidder_number ?: $next,
                'approved_by' => $by->id, 'approved_at' => now(), 'declined_reason' => null,
            ])->save();

            return $reg;
        });

        BidderApproved::dispatch($reg, $by->id);

        return $reg;
    }

    public function decline(AuctionRegistration $reg, User $by, string $reason): AuctionRegistration
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give the buyer a reason, so they can put it right.']);
        }
        if ($reg->status === AuctionRegistration::STATUS_APPROVED && $reg->bids()->valid()->exists()) {
            throw ValidationException::withMessages(['registration' => 'This bidder has live bids. Retract them before declining the registration.']);
        }
        $reg->forceFill([
            'status' => AuctionRegistration::STATUS_DECLINED, 'declined_reason' => mb_substr(trim($reason), 0, 300),
            'approved_by' => null, 'approved_at' => null,
        ])->save();

        BidderDeclined::dispatch($reg, $by->id);

        return $reg;
    }

    private function store(Auction $auction, ClientUser $user, string $kind, UploadedFile $file): BidderDocument
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: 'pdf');
        $path = $file->storeAs("auction-bidders/{$auction->agency_id}/{$user->id}", bin2hex(random_bytes(8)) . '.' . $ext, 'local');

        return BidderDocument::withoutAgencyStamping(fn () => BidderDocument::create([
            'agency_id' => $auction->agency_id, 'client_user_id' => $user->id, 'kind' => $kind,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'path' => $path,
            'mime_type' => $file->getClientMimeType(), 'size' => (int) $file->getSize(),
        ]));
    }

    public function download(BidderDocument $doc)
    {
        abort_unless(Storage::disk('local')->exists($doc->path), 404);

        return Storage::disk('local')->download($doc->path, $doc->original_name);
    }
}
