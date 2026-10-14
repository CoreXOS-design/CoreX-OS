<?php

namespace App\Services\Rentals;

use App\Mail\Rentals\RentalContractorJobMail;
use App\Mail\Rentals\RentalJobCardCrewLinkMail;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\RentalCrew;
use App\Models\RentalJobCard;
use App\Models\RentalJobDispatch;
use App\Models\RentalPortalSetting;
use App\Models\RentalSecureAccessToken;
use App\Models\RentalWorkOrder;
use App\Models\User;
use App\Support\WhatsAppNumberFormatter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/rentals-faults-work-orders.md §19 (AT-452 round 2) - the contractor (and the in-house crew) is told THE MOMENT they are
 * appointed, honestly:
 *
 *  - Email goes through the agency mail path ({@see RentalMailDispatcher}) AS the agent who appointed them, carrying one private link
 *    (spec §4.2) where they see the job and its photos with the office's marks and notes, accept or decline, and upload a quote.
 *  - WhatsApp is never automated: the agent presses "Open WhatsApp and send" (which records the open), and only then "Mark as sent".
 *    A WhatsApp row is never "sent" without an open click.
 *  - A contractor with no email and no mobile is NOT a silent dead end: a `not_sent` row is written, the work order says so in plain
 *    words, the agent is told, and the Send button works the moment details are added.
 *  - One send per appointment / per Resend press: every attempt first claims a unique idempotency key, so a double click, a retry or two
 *    agents cannot send the same job twice.
 * Every outcome is written to the work order's history (channel, destination, who, when) and the lease's agents are told through
 * {@see RentalWorkOrderService::notifyAgentOfOwnerAction()} - the one place that decides who those agents are.
 */
class RentalContractorJobService
{
    public const EV_SENT = 'rental_work_order.contractor_job_sent';
    public const EV_NOT_SENT = 'rental_work_order.contractor_job_not_sent';
    public const EV_RESPONDED = 'rental_work_order.contractor_responded';

    public const RESPONSE_PENDING = 'pending';
    public const RESPONSE_ACCEPTED = 'accepted';
    public const RESPONSE_DECLINED = 'declined';
    public const RESPONSE_QUOTE = 'quote_uploaded';

    public function __construct(private readonly RentalSecureAccessTokenService $tokens)
    {
    }

    // ── who can be reached ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The details a job can be sent to: the contractor's first active contact person with an email or number, else the firm itself.
     * `whatsapp` is the digits-only number a wa.me link needs (null when there is no usable number).
     *
     * @return array{name:string, email:?string, phone:?string, whatsapp:?string, has_contact:bool}
     */
    public function contactFor(?AgencyServiceProvider $provider): array
    {
        if (! $provider) {
            return ['name' => '', 'email' => null, 'phone' => null, 'whatsapp' => null, 'has_contact' => false];
        }
        $contacts = $provider->relationLoaded('serviceContacts') ? $provider->serviceContacts : $provider->serviceContacts()->get();
        $contact = $contacts->filter(fn ($c) => (bool) ($c->is_active ?? true) && ($c->email || $c->phone))->first();

        $email = trim((string) ($contact?->email ?: $provider->email)) ?: null;
        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = null;
        }
        $phone = trim((string) ($contact?->phone ?: $provider->phone)) ?: null;
        $digits = $phone ? WhatsAppNumberFormatter::forDeepLink($phone) : '';
        $whatsapp = strlen($digits) >= 10 ? $digits : null;

        return [
            'name' => (string) ($contact?->contact_person ?: ($contact?->attorney_name ?: $provider->name)),
            'email' => $email,
            'phone' => $phone,
            'whatsapp' => $whatsapp,
            'has_contact' => $email !== null || $whatsapp !== null,
        ];
    }

    // ── contractor: appoint / re-send ───────────────────────────────────────────────────────────────────────────────────────

    /**
     * Change (or first set) the work order's contractor from the work order screen WITHOUT needing a typed quote - the job goes to them
     * to quote. Not a go-ahead: starting work is still gated by the owner's approval ({@see RentalWorkOrder::authoriseToProceed}).
     *
     * @throws \LogicException with a plain sentence when it cannot be done
     */
    public function appoint(RentalWorkOrder $wo, int $providerId, ?string $tradeType, User $by): array
    {
        app(RentalOwnerHoldService::class)->assertNotOnHold($wo);   // section 22.7 - paused by the owner
        if (in_array($wo->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true)) {
            throw new \LogicException('This work order is already closed.');
        }
        if ($wo->assignment_type === RentalWorkOrder::ASSIGNMENT_INTERNAL || $wo->isOwnerContractor()) {
            throw new \LogicException('This work order is not done by one of the agency\'s contractors.');
        }
        if ($wo->hasApprovedBaseline() && (int) $wo->agency_service_provider_id !== $providerId) {
            throw new \LogicException('The owner has already approved an amount for this work order, so the contractor can no longer be changed here.');
        }
        $provider = AgencyServiceProvider::withoutGlobalScopes()->with('serviceContacts')
            ->where('agency_id', $wo->agency_id)->where('is_active', true)->whereNull('deleted_at')->maintenanceContractors()->find($providerId);
        if (! $provider) {
            throw new \LogicException('Choose which contractor does the work.');
        }
        if ((int) $wo->agency_service_provider_id === $providerId) {
            throw new \LogicException($provider->name . ' is already the contractor - use "Send the job again".');
        }

        $first = $wo->agency_service_provider_id === null;
        $wo->forceFill(['agency_service_provider_id' => $providerId, 'contractor_response' => null, 'contractor_responded_at' => null, 'contractor_decline_reason' => null]);
        if ($tradeType !== null) {
            $wo->trade_type = $tradeType;
        }
        $wo->save();
        $this->tokens->revokeAllFor($wo);   // the previous contractor's link stops working at once
        $wo->updates()->create([
            'agency_id' => $wo->agency_id, 'update_type' => $first ? 'supplier_assigned' : 'supplier_changed', 'created_by_user_id' => $by->id,
            'note' => ($first ? 'Contractor chosen: ' : 'Contractor changed to ') . $provider->name . '.',
        ]);

        return $this->dispatchOnAppointment($wo->fresh(), $by, $first ? RentalJobDispatch::REASON_APPOINTED : RentalJobDispatch::REASON_REASSIGNED);
    }

    /**
     * The contractor's first (or new) job message. Safe to call twice for the same appointment: the second call finds what the first
     * did and sends nothing more. Never throws - a mail problem leaves a `failed` row and a plain line on the work order.
     *
     * @return array{rows:array<int,RentalJobDispatch>, sent:bool, not_sent:bool}
     */
    public function dispatchOnAppointment(RentalWorkOrder $wo, ?User $by, string $reason = RentalJobDispatch::REASON_APPOINTED): array
    {
        $assignments = $wo->updates()->whereIn('update_type', ['supplier_assigned', 'supplier_changed'])->count();

        return $this->send($wo, $by, $reason, "wo{$wo->id}:p{$wo->agency_service_provider_id}:appt{$assignments}");
    }

    /** "Send the job again" - the agent's explicit press; $pressKey comes from the form, so a double click is one send. */
    public function resend(RentalWorkOrder $wo, User $by, string $pressKey): array
    {
        return $this->send($wo, $by, RentalJobDispatch::REASON_RESEND, "wo{$wo->id}:p{$wo->agency_service_provider_id}:press:" . substr(preg_replace('/[^A-Za-z0-9-]/', '', $pressKey), 0, 64));
    }

    private function send(RentalWorkOrder $wo, ?User $by, string $reason, string $keyBase): array
    {
        $out = ['rows' => [], 'sent' => false, 'not_sent' => false];
        if ($held = app(RentalOwnerHoldService::class)->holdReason($wo)) {   // section 22.7 - paused by the owner: nothing goes out
            $this->history($wo, $by, 'job_not_sent', 'Job NOT sent - ' . $held);
            $out['not_sent'] = true;
            $out['held'] = $held;

            return $out;
        }
        try {
            $provider = $wo->supplier()->withoutGlobalScopes()->with('serviceContacts')->first();
            if (! $provider || in_array($wo->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true)) {
                return $out;
            }
            $contact = $this->contactFor($provider);

            if (! $contact['has_contact']) {
                $row = $this->claim($keyBase . ':none', $wo, $by, $reason, [
                    'recipient_kind' => RentalJobDispatch::KIND_CONTRACTOR, 'agency_service_provider_id' => $provider->id, 'recipient_name' => $provider->name,
                    'channel' => RentalJobDispatch::CHANNEL_NONE, 'status' => RentalJobDispatch::STATUS_NOT_SENT,
                    'error' => 'No email address or mobile number on file for this contractor.',
                ]);
                if ($row['fresh']) {
                    $this->history($wo, $by, 'job_not_sent', 'Job NOT sent to ' . $provider->name . ' - no email or mobile number on file. Add their details and press Send.');
                    $this->tell($wo, self::EV_NOT_SENT, 'Job NOT sent - no contact details - ' . $this->address($wo), $provider->name . ' has no email or mobile number on file. ' . $wo->title);
                }
                $out['rows'][] = $row['row'];
                $out['not_sent'] = true;

                return $out;
            }

            if ($contact['email']) {
                $row = $this->claim($keyBase . ':email', $wo, $by, $reason, [
                    'recipient_kind' => RentalJobDispatch::KIND_CONTRACTOR, 'agency_service_provider_id' => $provider->id, 'recipient_name' => $provider->name,
                    'channel' => RentalJobDispatch::CHANNEL_EMAIL, 'destination' => $contact['email'], 'status' => RentalJobDispatch::STATUS_PREPARED,
                ]);
                if ($row['fresh']) {
                    $this->sendContractorEmail($wo, $provider, $contact, $row['row'], $by);
                }
                $out['rows'][] = $row['row']->fresh();
                $out['sent'] = $out['sent'] || $row['row']->fresh()->counted();
            }

            if ($contact['whatsapp']) {
                $row = $this->claim($keyBase . ':wa', $wo, $by, $reason, [
                    'recipient_kind' => RentalJobDispatch::KIND_CONTRACTOR, 'agency_service_provider_id' => $provider->id, 'recipient_name' => $provider->name,
                    'channel' => RentalJobDispatch::CHANNEL_WHATSAPP, 'destination' => $contact['phone'], 'status' => RentalJobDispatch::STATUS_PREPARED,
                ]);
                $out['rows'][] = $row['row'];
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Contractor job send failed', ['work_order' => $wo->id, 'error' => $e->getMessage()]);
        }

        return $out;
    }

    private function sendContractorEmail(RentalWorkOrder $wo, AgencyServiceProvider $provider, array $contact, RentalJobDispatch $row, ?User $by): void
    {
        $sender = $this->senderFor($wo, $by);
        try {
            $issued = $this->tokens->issueFor($wo, $sender ?? $by ?? $this->anyUser($wo));
            $url = route('rentals.secure-link.show', $issued['raw_token']);
            $photoCount = count(app(RentalFaultPhotoAnnotationService::class)->contractorProblemPhotos($wo));   // what the job page will show
            $mail = new RentalContractorJobMail(
                $wo, $contact['name'] ?: $provider->name, $url, $issued['token']->expires_at?->format('j M Y'), $photoCount,
                trim((string) ($wo->property?->access_notes ?? '')) ?: null, $this->tenantLine($wo), $this->agentLine($sender), $sender,
            );
            app(RentalMailDispatcher::class)->send($contact['email'], $mail);
            $row->forceFill(['status' => RentalJobDispatch::STATUS_SENT, 'sent_at' => now(), 'rental_secure_access_token_id' => $issued['token']->id])->save();
        } catch (\Throwable $e) {
            report($e);
            $row->forceFill(['status' => RentalJobDispatch::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
            $this->history($wo, $by, 'job_not_sent', 'Job could NOT be emailed to ' . $provider->name . ' (' . $contact['email'] . '). Press "Send the job again" to retry.');
            $this->tell($wo, self::EV_NOT_SENT, 'Job NOT sent - email failed - ' . $this->address($wo), $provider->name . ': ' . $wo->title);

            return;
        }
        $this->afterCounted($wo, $row, $by);
    }

    // ── WhatsApp: open, then confirm ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The agent pressed "Open WhatsApp and send": mint a link for THIS message (the emailed link keeps working), record the open, and
     * return the wa.me address with the text ready. Nothing is "sent" yet - that takes the agent's second press.
     *
     * @throws \LogicException
     */
    public function openWhatsApp(RentalJobDispatch $d, User $by): string
    {
        if ($d->channel !== RentalJobDispatch::CHANNEL_WHATSAPP || ! in_array($d->status, [RentalJobDispatch::STATUS_PREPARED, RentalJobDispatch::STATUS_OPENED], true)) {
            throw new \LogicException('This WhatsApp message is not waiting to be opened.');
        }
        $digits = WhatsAppNumberFormatter::forDeepLink((string) $d->destination);
        if (strlen($digits) < 10) {
            throw new \LogicException('There is no usable mobile number on file.');
        }

        if ($d->recipient_kind === RentalJobDispatch::KIND_CREW) {
            $card = RentalJobCard::withoutGlobalScopes()->where('agency_id', $d->agency_id)->findOrFail((int) $d->rental_job_card_id);
            $issued = $this->tokens->issueExtra($card, RentalSecureAccessToken::PURPOSE_CREW_JOB_CARD, $by, RentalPortalSetting::crewJobLinkExpiryDaysFor($card->agency_id), $d->rental_secure_access_token_id);
            $url = route('rentals.crew-job.show', $issued['raw_token']);
            $text = 'Hi ' . ($d->recipient_name ?: 'there') . ', here is your job at ' . ($card->property?->buildDisplayAddress() ?: 'the property') . ': ' . $card->title . '. Open it here: ' . $url;
        } else {
            $wo = RentalWorkOrder::withoutGlobalScopes()->where('agency_id', $d->agency_id)->findOrFail((int) $d->rental_work_order_id);
            if ((int) $wo->agency_service_provider_id !== (int) $d->agency_service_provider_id) {
                throw new \LogicException('This job has since been given to another contractor.');
            }
            $issued = $this->tokens->issueExtra($wo, RentalSecureAccessToken::PURPOSE_CONTRACTOR_WORK_ORDER, $by, RentalPortalSetting::contractorSecureLinkExpiryDaysFor($wo->agency_id), $d->rental_secure_access_token_id);
            $url = route('rentals.secure-link.show', $issued['raw_token']);
            $text = 'Hi ' . ($d->recipient_name ?: 'there') . ', ' . $this->agencyName($wo) . ' has a job for you at ' . $this->address($wo) . ': ' . $wo->title
                . '. See the photos, accept or decline and send your quote here: ' . $url;
        }
        $d->forceFill(['status' => RentalJobDispatch::STATUS_OPENED, 'opened_at' => now(), 'rental_secure_access_token_id' => $issued['token']->id, 'sent_by_user_id' => $by->id])->save();

        return 'https://wa.me/' . $digits . '?text=' . rawurlencode($text);
    }

    /** The agent confirms the WhatsApp went - only possible after "Open WhatsApp and send" was pressed. Pressing it twice changes nothing. */
    public function markWhatsAppSent(RentalJobDispatch $d, User $by): void
    {
        if ($d->status === RentalJobDispatch::STATUS_MARKED_SENT) {
            return;
        }
        if ($d->channel !== RentalJobDispatch::CHANNEL_WHATSAPP || $d->status !== RentalJobDispatch::STATUS_OPENED || $d->opened_at === null) {
            throw new \LogicException('Open WhatsApp first - a message is only marked as sent after you opened it.');
        }
        $d->forceFill(['status' => RentalJobDispatch::STATUS_MARKED_SENT, 'sent_at' => now(), 'sent_by_user_id' => $by->id])->save();
        $wo = RentalWorkOrder::withoutGlobalScopes()->find($d->rental_work_order_id);
        if ($wo) {
            $this->afterCounted($wo, $d, $by);
        }
    }

    // ── a counted send: history, ordered, tell the agents ───────────────────────────────────────────────────────────────────

    private function afterCounted(RentalWorkOrder $wo, RentalJobDispatch $d, ?User $by): void
    {
        $who = $by?->name ?: 'the system';
        $what = $d->recipient_kind === RentalJobDispatch::KIND_CREW ? 'Job sent to the crew' : 'Job sent to contractor';
        $this->history($wo, $by, $d->recipient_kind === RentalJobDispatch::KIND_CREW ? 'job_sent_to_crew' : 'job_sent_to_contractor',
            $what . ' ' . ($d->recipient_name ?: '') . ' by ' . $d->channelLabel() . ($d->destination ? ' to ' . $d->destination : '') . ' - ' . $who
            . ($d->channel === RentalJobDispatch::CHANNEL_WHATSAPP ? ' (opened WhatsApp, then confirmed it went)' : '') . '.');

        if ($d->recipient_kind === RentalJobDispatch::KIND_CONTRACTOR) {
            $this->markOrdered($wo, $by);
        }
        $this->tell($wo, self::EV_SENT, $what . ' - ' . $this->address($wo), trim(($d->recipient_name ?: '') . ' by ' . $d->channelLabel() . ': ' . $wo->title));
    }

    /** The first counted send moves a reported work order to ordered (the tenant's progress line reads `ordered_at`) and opens the contractor's "pending" answer. */
    private function markOrdered(RentalWorkOrder $wo, ?User $by): void
    {
        $fresh = RentalWorkOrder::withoutGlobalScopes()->find($wo->id);
        if (! $fresh) {
            return;
        }
        $updates = ['contractor_response' => $fresh->contractor_response ?: self::RESPONSE_PENDING];
        if ($fresh->status === RentalWorkOrder::STATUS_REPORTED) {
            $updates['status'] = RentalWorkOrder::STATUS_ORDERED;
            $updates['ordered_at'] = now();
        }
        $fresh->forceFill($updates)->save();
        if (isset($updates['status'])) {
            $fresh->updates()->create([
                'agency_id' => $fresh->agency_id, 'update_type' => 'status_change', 'from_status' => RentalWorkOrder::STATUS_REPORTED,
                'to_status' => RentalWorkOrder::STATUS_ORDERED, 'created_by_user_id' => $by?->id,
                'note' => 'The job was sent to the contractor.',
            ]);
        }
    }

    // ── the contractor answers (from their own link) ────────────────────────────────────────────────────────────────────────

    public function recordResponse(RentalWorkOrder $wo, string $response, ?string $reason = null): void
    {
        if (! in_array($response, [self::RESPONSE_ACCEPTED, self::RESPONSE_DECLINED, self::RESPONSE_QUOTE], true)) {
            return;
        }
        $wo->forceFill([
            'contractor_response' => $response, 'contractor_responded_at' => now(),
            'contractor_decline_reason' => $response === self::RESPONSE_DECLINED ? ($reason ?: null) : null,
        ])->save();
        $name = $wo->supplier?->name ?: 'The contractor';
        $line = match ($response) {
            self::RESPONSE_ACCEPTED => $name . ' accepted the job.',
            self::RESPONSE_DECLINED => $name . ' declined the job' . ($reason ? ': ' . $reason : '.'),
            default => $name . ' uploaded a quote from their link.',
        };
        $this->history($wo, null, 'contractor_response', $line);
        $this->tell($wo, self::EV_RESPONDED, $line . ' - ' . $this->address($wo), $wo->title);
    }

    public static function responseLabel(?string $response): string
    {
        return match ($response) {
            self::RESPONSE_PENDING => 'Waiting for their answer',
            self::RESPONSE_ACCEPTED => 'Accepted the job',
            self::RESPONSE_DECLINED => 'Declined the job',
            self::RESPONSE_QUOTE => 'Quote uploaded',
            default => 'Not asked yet',
        };
    }

    // ── what the work order's supplier card shows ───────────────────────────────────────────────────────────────────────────

    /**
     * @return array{provider:?AgencyServiceProvider, contact:array, sent:?RentalJobDispatch, not_sent:?RentalJobDispatch, whatsapp:?RentalJobDispatch,
     *               response:?string, response_label:string, decline_reason:?string, can_send:bool, next:string}
     */
    public function statusFor(RentalWorkOrder $wo): array
    {
        $provider = $wo->supplier()->withoutGlobalScopes()->with('serviceContacts')->first();
        $contact = $this->contactFor($provider);
        $rows = $provider
            ? RentalJobDispatch::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->where('recipient_kind', RentalJobDispatch::KIND_CONTRACTOR)
                ->where('agency_service_provider_id', $provider->id)->orderByDesc('id')->get()
            : collect();
        $sent = $rows->first(fn ($r) => $r->counted());
        $latestOpenWa = $rows->first(fn ($r) => $r->channel === RentalJobDispatch::CHANNEL_WHATSAPP && in_array($r->status, [RentalJobDispatch::STATUS_PREPARED, RentalJobDispatch::STATUS_OPENED], true));
        // the NOT-sent line stands until something has been sent after it
        $latest = $rows->first();
        $notSent = (! $sent && $latest && in_array($latest->status, [RentalJobDispatch::STATUS_NOT_SENT, RentalJobDispatch::STATUS_FAILED], true)) ? $latest : null;

        return [
            'provider' => $provider,
            'contact' => $contact,
            'sent' => $sent,
            'not_sent' => $notSent,
            'whatsapp' => $latestOpenWa,
            'response' => $wo->contractor_response,
            'response_label' => self::responseLabel($wo->contractor_response),
            'decline_reason' => $wo->contractor_decline_reason,
            'can_send' => $provider !== null && $contact['has_contact'],
            'any_row' => $rows->count() > 0,
        ];
    }

    // ── the in-house crew ───────────────────────────────────────────────────────────────────────────────────────────────────

    public static function crewAutoSendOn(?int $agencyId): bool
    {
        return RentalPortalSetting::crewLinkAutoSendOnFaultJobFor($agencyId) && RentalPortalSetting::crewLinksEnabledFor($agencyId);
    }

    /**
     * Send the crew their per-job link (spec §14.28): email when the crew has one, WhatsApp prepared when it has a number, a plain
     * "not sent" line when it has neither - or when no crew has been chosen yet. Idempotent per job card + reason + press.
     *
     * @return array{rows:array<int,RentalJobDispatch>, sent:bool, not_sent:bool}
     */
    public function sendCrewLink(RentalJobCard $card, ?User $by, string $reason = RentalJobDispatch::REASON_CREW_AUTO, ?string $pressKey = null): array
    {
        $out = ['rows' => [], 'sent' => false, 'not_sent' => false];
        if ($held = app(RentalOwnerHoldService::class)->holdReason($card)) {   // section 22.7 - paused by the owner: nothing goes out
            $out['not_sent'] = true;
            $out['held'] = $held;

            return $out;
        }
        try {
            if ($card->isClosed() || ! $card->rental_work_order_id) {
                return $out;
            }
            $wo = RentalWorkOrder::withoutGlobalScopes()->find($card->rental_work_order_id);
            if (! $wo) {
                return $out;
            }
            $crew = $card->rental_crew_id ? RentalCrew::withoutGlobalScopes()->find($card->rental_crew_id) : null;
            $keyBase = "jc{$card->id}:crew" . ($crew?->id ?? 0) . ':' . ($pressKey ? 'press:' . substr(preg_replace('/[^A-Za-z0-9-]/', '', $pressKey), 0, 64) : 'auto');

            $emailOk = $crew && filter_var((string) $crew->email, FILTER_VALIDATE_EMAIL);
            $digits = $crew && $crew->phone ? WhatsAppNumberFormatter::forDeepLink($crew->phone) : '';
            $wa = strlen($digits) >= 10;

            if (! $crew || (! $emailOk && ! $wa)) {
                $row = $this->claim($keyBase . ':none', $wo, $by, $reason, [
                    'rental_job_card_id' => $card->id, 'recipient_kind' => RentalJobDispatch::KIND_CREW, 'rental_crew_id' => $crew?->id,
                    'recipient_name' => $crew?->name, 'channel' => RentalJobDispatch::CHANNEL_NONE, 'status' => RentalJobDispatch::STATUS_NOT_SENT,
                    'error' => $crew ? 'No email address or mobile number on file for this crew.' : 'No crew has been chosen yet.',
                ]);
                if ($row['fresh']) {
                    $line = $crew ? 'Crew link NOT sent to ' . $crew->name . ' - no email or mobile number on file.' : 'Crew link not sent - no crew has been chosen on the job card yet.';
                    $card->logUpdate('link_not_sent', $by, $line);
                    $this->history($wo, $by, 'job_not_sent', $line);
                    $this->tell($wo, self::EV_NOT_SENT, 'Crew job link not sent - ' . $this->address($wo), $line);
                }
                $out['rows'][] = $row['row'];
                $out['not_sent'] = true;

                return $out;
            }

            if ($emailOk) {
                $row = $this->claim($keyBase . ':email', $wo, $by, $reason, [
                    'rental_job_card_id' => $card->id, 'recipient_kind' => RentalJobDispatch::KIND_CREW, 'rental_crew_id' => $crew->id,
                    'recipient_name' => $crew->name, 'channel' => RentalJobDispatch::CHANNEL_EMAIL, 'destination' => $crew->email, 'status' => RentalJobDispatch::STATUS_PREPARED,
                ]);
                if ($row['fresh']) {
                    try {
                        $issued = $this->tokens->issueForJobCard($card, $by);
                        $url = route('rentals.crew-job.show', $issued['raw_token']);
                        app(RentalMailDispatcher::class)->send($crew->email, new RentalJobCardCrewLinkMail($card, $url, $issued['token']->expires_at?->format('j M Y'), $this->senderFor($wo, $by)));
                        $row['row']->forceFill(['status' => RentalJobDispatch::STATUS_SENT, 'sent_at' => now(), 'rental_secure_access_token_id' => $issued['token']->id])->save();
                        $card->logUpdate('link_emailed', $by, "Crew link emailed to {$crew->email}" . ($reason === RentalJobDispatch::REASON_CREW_AUTO ? ' (sent automatically when the job card was created)' : ''));
                        $this->afterCounted($wo, $row['row']->fresh(), $by);
                    } catch (\Throwable $e) {
                        report($e);
                        $row['row']->forceFill(['status' => RentalJobDispatch::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
                        $card->logUpdate('link_not_sent', $by, 'Crew link could NOT be emailed to ' . $crew->email . '. Use "Email to crew" to try again.');
                        $this->tell($wo, self::EV_NOT_SENT, 'Crew job link not sent - email failed - ' . $this->address($wo), $crew->name);
                    }
                }
                $out['rows'][] = $row['row']->fresh();
                $out['sent'] = $out['sent'] || $row['row']->fresh()->counted();
            }

            if ($wa) {
                $row = $this->claim($keyBase . ':wa', $wo, $by, $reason, [
                    'rental_job_card_id' => $card->id, 'recipient_kind' => RentalJobDispatch::KIND_CREW, 'rental_crew_id' => $crew->id,
                    'recipient_name' => $crew->name, 'channel' => RentalJobDispatch::CHANNEL_WHATSAPP, 'destination' => $crew->phone, 'status' => RentalJobDispatch::STATUS_PREPARED,
                ]);
                $out['rows'][] = $row['row'];
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Crew job link send failed', ['job_card' => $card->id, 'error' => $e->getMessage()]);
        }

        return $out;
    }

    /** The latest WhatsApp still waiting on the agent for this job card, and whether any crew send has gone. */
    public function crewStatusFor(RentalJobCard $card): array
    {
        $rows = RentalJobDispatch::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->where('recipient_kind', RentalJobDispatch::KIND_CREW)->orderByDesc('id')->get();
        $sent = $rows->first(fn ($r) => $r->counted());
        $latest = $rows->first();

        return [
            'sent' => $sent,
            'not_sent' => (! $sent && $latest && in_array($latest->status, [RentalJobDispatch::STATUS_NOT_SENT, RentalJobDispatch::STATUS_FAILED], true)) ? $latest : null,
            'whatsapp' => $rows->first(fn ($r) => $r->channel === RentalJobDispatch::CHANNEL_WHATSAPP && in_array($r->status, [RentalJobDispatch::STATUS_PREPARED, RentalJobDispatch::STATUS_OPENED], true)),
        ];
    }

    // ── internals ───────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Claim the right to send: insert the row under its unique idempotency key. Whoever inserts it sends; anybody arriving second gets
     * the row that is already there and sends nothing.
     *
     * @return array{row:RentalJobDispatch, fresh:bool}
     */
    private function claim(string $key, RentalWorkOrder $wo, ?User $by, string $reason, array $attributes): array
    {
        $existing = RentalJobDispatch::withoutGlobalScopes()->where('idempotency_key', $key)->first();
        if ($existing) {
            return ['row' => $existing, 'fresh' => false];
        }
        try {
            $row = RentalJobDispatch::create($attributes + [
                'agency_id' => $wo->agency_id, 'rental_work_order_id' => $wo->id, 'reason' => $reason, 'idempotency_key' => $key, 'sent_by_user_id' => $by?->id,
            ]);

            return ['row' => $row, 'fresh' => true];
        } catch (QueryException $e) {
            $existing = RentalJobDispatch::withoutGlobalScopes()->where('idempotency_key', $key)->first();
            if ($existing) {
                return ['row' => $existing, 'fresh' => false];
            }
            throw $e;
        }
    }

    private function history(RentalWorkOrder $wo, ?User $by, string $type, string $note): void
    {
        $wo->updates()->create(['agency_id' => $wo->agency_id, 'update_type' => $type, 'note' => $note, 'created_by_user_id' => $by?->id]);
    }

    /** Both lease agents (and nobody twice) - the recipient rule lives in RentalWorkOrderService::notifyAgentOfOwnerAction() and nowhere else. */
    private function tell(RentalWorkOrder $wo, string $eventKey, string $title, string $body): void
    {
        try {
            app(RentalWorkOrderService::class)->notifyAgentOfOwnerAction($wo, $eventKey, $title, $body, now());
        } catch (\Throwable $e) {
            Log::warning('Contractor job notification failed', ['work_order' => $wo->id, 'event' => $eventKey, 'error' => $e->getMessage()]);
        }
    }

    private function address(RentalWorkOrder $wo): string
    {
        return $wo->property?->buildDisplayAddress() ?: ('Property #' . $wo->property_id);
    }

    private function agencyName(RentalWorkOrder $wo): string
    {
        $agency = $wo->agency ?? $wo->property?->agency;

        return \App\Models\Agency::displayNameFor($agency);
    }

    /** The agent a mail is sent AS: whoever pressed the button, else the property's agent, else whoever created the work order. */
    private function senderFor(RentalWorkOrder $wo, ?User $by): ?User
    {
        if ($by) {
            return $by;
        }
        $agentId = $wo->property?->agent_id;
        $agent = $agentId ? User::withoutGlobalScopes()->find($agentId) : null;

        return $agent ?? ($wo->created_by_user_id ? User::withoutGlobalScopes()->find($wo->created_by_user_id) : null);
    }

    private function anyUser(RentalWorkOrder $wo): User
    {
        return User::withoutGlobalScopes()->where('agency_id', $wo->agency_id)->orderBy('id')->firstOrFail();
    }

    private function agentLine(?User $agent): ?string
    {
        if (! $agent) {
            return null;
        }
        $number = $agent->cell ?: $agent->phone;

        return trim($agent->name . ($number ? ', ' . $number : ''));
    }

    /** The tenant's name and number ONLY when the agency has switched that on for its crews (the one existing setting) - never by default. */
    private function tenantLine(RentalWorkOrder $wo): ?string
    {
        if (! RentalPortalSetting::crewLinkShowTenantContactFor($wo->agency_id) || ! $wo->lease) {
            return null;
        }
        $tenant = $wo->lease->tenants()->with('contact')->get()->map(fn ($t) => $t->contact)->filter()->first();
        if (! $tenant) {
            return null;
        }

        return trim(trim(($tenant->first_name ?? '') . ' ' . ($tenant->last_name ?? '')) . ($tenant->phone ? ', ' . $tenant->phone : '')) ?: null;
    }
}
