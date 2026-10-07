<?php

namespace App\Http\Controllers\PrivateProperty;

use App\Http\Controllers\Controller;
use App\Models\CommandCenter\CommandTask;
use App\Models\Contact;
use App\Models\ContactSource;
use App\Models\ContactType;
use App\Models\PortalLead;
use App\Models\Property;
use App\Services\PrivateProperty\PrivatePropertyConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PpWebhookController extends Controller
{
    public function receive(Request $request): Response
    {
        $body      = $request->getContent();
        $signature = (string) $request->header('X-Signature', '');

        // Parse JSON first so we can resolve the right agency secret. Parsing
        // alone confers no trust — the HMAC check below is what gates the
        // request.
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            Log::channel('private_property')->warning('PP webhook: invalid JSON body');
            return response('OK', 200);
        }

        // Resolve the agency for this lead via the listing's property → agency,
        // falling back to env defaults if no match.
        $externalRef = $payload['listingExternalReference'] ?? null;
        $property    = $externalRef && is_numeric($externalRef) ? Property::find((int) $externalRef) : null;
        $secret      = PrivatePropertyConfig::for($property?->agency)['webhook_secret'];

        if (empty($secret)) {
            Log::channel('private_property')->error('PP webhook: no webhook secret configured (agency or env) — rejecting.');
            return response('Misconfigured', 500);
        }

        $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));

        if (!hash_equals($expected, $signature)) {
            Log::channel('private_property')->warning('PP webhook: invalid signature', [
                'received'  => $signature ? substr($signature, 0, 8) . '…' : '(empty)',
                'ip'        => $request->ip(),
                'agency_id' => $property?->agency_id,
            ]);
            return response('Unauthorized', 401);
        }

        Log::channel('private_property')->info('PP webhook: payload received', $payload);

        if (($payload['messageType'] ?? null) !== 'Lead') {
            return response('OK', 200);
        }

        if (!$property) {
            Log::channel('private_property')->warning('PP webhook: lead for unknown property', [
                'listingExternalReference' => $externalRef,
                'leadId'                   => $payload['leadId'] ?? null,
            ]);
            return response('OK', 200);
        }

        // IDEMPOTENCY (2026-10-07). PP re-delivers a webhook on any non-2xx / timeout, so
        // the SAME enquiry can arrive twice. The key is PP's OWN reference for the
        // enquiry — the payload's `leadId` — scoped to this property's agency; when a
        // delivery carries no leadId, a fingerprint of (listing, email, phone, message)
        // inside a short window stands in for it. A duplicate creates no contact note,
        // no task, no portal_leads row and fires no notification: it is logged at INFO
        // (it is expected PP behaviour, not an error) and answered 200 like everything else.
        $agencyId  = (int) $property->agency_id;
        $leadRef   = trim((string) ($payload['leadId'] ?? ''));
        $dedupHash = $this->fingerprint($payload, $property);
        $lock      = Cache::lock('pp-webhook-lead:' . $agencyId . ':' . sha1($leadRef !== '' ? 'id:' . $leadRef : 'fp:' . $dedupHash), 60);

        // Two simultaneous deliveries of the same enquiry: the loser sees the lock held
        // and stops here, the winner does the work.
        if (!$lock->get()) {
            $this->logDuplicate($payload, $property, 'in flight');
            return response('OK', 200);
        }

        try {
            if ($this->alreadyReceived($agencyId, $property, $leadRef, $dedupHash)) {
                $this->logDuplicate($payload, $property, $leadRef !== '' ? 'leadId' : 'fingerprint');
                return response('OK', 200);
            }

            DB::transaction(function () use ($payload, $property, $leadRef, $dedupHash) {
                $contact = $this->createLeadContact($payload, $property);

                // createLeadContact() can return an EXISTING, deduped
                // contact (email/phone match) — never a bare
                // syncWithoutDetaching() here: a soft-deleted
                // contact_property row for this exact pair (this contact
                // previously linked, later unlinked, from this same
                // property) would blind-insert-collide, and this whole
                // block is wrapped in a catch below that logs and still
                // returns 200 to PP — a real collision here would fail
                // completely silently, the exact highest-risk shape named
                // in .ai/specs/rental-applications.md, "The
                // contact_property hard-delete fix". ContactPropertyLinker
                // restores the existing row instead of colliding.
                \App\Services\Property\ContactPropertyLinker::link($contact->id, $property->id, 'lead');

                $this->createLeadTask($payload, $property, $contact, $leadRef, $dedupHash);
            });
        } catch (\Throwable $e) {
            // Log but still return 200 — PP must not retry on our internal errors.
            Log::channel('private_property')->error('PP webhook: lead processing failed', [
                'error'   => $e->getMessage(),
                'leadId'  => $payload['leadId'] ?? null,
            ]);
        } finally {
            $lock->release();
        }

        return response('OK', 200);
    }

    /** How long, with no leadId on the delivery, an identical enquiry is treated as a re-delivery. */
    private const FINGERPRINT_WINDOW_MINUTES = 10;

    /** Stable hash of what makes an enquiry "the same one": property + enquirer + message. */
    private function fingerprint(array $payload, Property $property): string
    {
        return sha1(implode('|', [
            (int) $property->id,
            mb_strtolower(trim((string) ($payload['leadEmail'] ?? ''))),
            preg_replace('/\D+/', '', (string) ($payload['leadPhoneNumber'] ?? '')),
            trim((string) ($payload['leadMessage'] ?? '')),
        ]));
    }

    /**
     * Has this enquiry already been processed? With a leadId: any earlier webhook task for the
     * agency carrying it, or any pp portal_leads row carrying it (so a PP-pull row for the same
     * LeadId also counts). Without one: the fingerprint seen on a webhook task in the window.
     * withoutGlobalScopes — this is unauthenticated ingress, and a soft-deleted earlier task
     * still means the enquiry was already received.
     */
    private function alreadyReceived(int $agencyId, Property $property, string $leadRef, string $dedupHash): bool
    {
        $tasks = CommandTask::query()->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('source_type', 'private_property_webhook');

        if ($leadRef !== '') {
            return (clone $tasks)->where('metadata->pp_lead_id', $leadRef)->exists()
                || PortalLead::query()->withoutGlobalScopes()
                    ->where('agency_id', $agencyId)
                    ->where('portal', PortalLead::PORTAL_PP)
                    ->where('lead_source_raw->__corex_lead_id', $leadRef)
                    ->exists();
        }

        return $tasks
            ->where('property_id', $property->id)
            ->where('metadata->pp_dedup_hash', $dedupHash)
            ->where('created_at', '>=', now()->subMinutes(self::FINGERPRINT_WINDOW_MINUTES))
            ->exists();
    }

    private function logDuplicate(array $payload, Property $property, string $matchedOn): void
    {
        Log::channel('private_property')->info('PP webhook: duplicate lead ignored', [
            'leadId'     => $payload['leadId'] ?? null,
            'matched_on' => $matchedOn,
            'listing'    => $property->id,
            'agency_id'  => $property->agency_id,
        ]);
    }

    private function createLeadContact(array $payload, Property $property): Contact
    {
        [$first, $last] = $this->splitName($payload['leadName'] ?? '');

        // Part 2 — unify portal tagging: a PP enquirer is a Buyer, same as P24
        // (was tagged "Lead"). Falls back to "Lead" only if the Buyer type is absent.
        $leadTypeId   = ContactType::where('name', 'Buyer')->value('id')
                     ?? ContactType::where('name', 'Lead')->value('id');
        $leadSourceId = ContactSource::idForAgencyByName((int) $property->agency_id, 'Private Property'); // webhook has no logged-in user: never an unscoped lookup

        $note = trim(
            "PP lead — listing {$property->id}"
            . (isset($payload['listingReference']) ? " ({$payload['listingReference']})" : '')
            . (isset($payload['leadDateTime']) ? " at {$payload['leadDateTime']}" : '')
            . "\n\n" . ($payload['leadMessage'] ?? '(no message)')
        );

        // Part 4 — normalised match-or-create (PP previously minted a contact on EVERY
        // webhook). Reuse the canonical ContactDuplicateService so "+27 76…" vs "076…"
        // and case-different emails resolve to ONE contact instead of a duplicate buyer.
        $email = $payload['leadEmail'] ?? null;
        $phone = $payload['leadPhoneNumber'] ?? null;
        if ($email || $phone) {
            $existing = app(\App\Services\ContactDuplicateService::class)
                ->findDuplicates(['email' => $email, 'phone' => $phone], (int) $property->agency_id)
                ->first();
            if ($existing) {
                // Append the enquiry note; keep the existing owner/type. No duplicate.
                $existing->notes = trim(($existing->notes ? $existing->notes . "\n\n" : '') . $note);
                $existing->saveQuietly();
                return $existing;
            }
        }

        return Contact::create([
            'first_name'         => $first,
            'last_name'          => $last,
            'phone'              => $phone,
            'email'              => $email,
            'notes'              => $note,
            'contact_type_id'    => $leadTypeId,
            'contact_source_id'  => $leadSourceId,
            'created_by_user_id' => $property->agent_id,
            'agency_id'          => $property->agency_id,
        ]);
    }

    private function createLeadTask(array $payload, Property $property, Contact $contact, string $leadRef = '', string $dedupHash = ''): void
    {
        if (!$property->agent_id) return;

        $name = $payload['leadName'] ?? 'New PP lead';

        CommandTask::create([
            'title'         => "New PP lead — {$name}",
            'description'   => "Private Property lead for "
                             . ($property->title ?? "property #{$property->id}")
                             . ".\n\n" . ($payload['leadMessage'] ?? ''),
            'task_type'     => 'lead_followup',
            'status'        => CommandTask::STATUS_TODO,
            'priority'      => 'high',
            'send_reminder' => true,
            'assigned_to'   => $property->agent_id,
            'property_id'   => $property->id,
            'contact_id'    => $contact->id,
            'source_type'   => 'private_property_webhook',
            'source_id'     => $contact->id,
            // The idempotency record (see receive()): PP's own enquiry id + the fallback
            // fingerprint. CommandTaskPortalLeadObserver copies the id onto the portal_leads row.
            'metadata'      => ['pp_lead_id' => $leadRef !== '' ? $leadRef : null, 'pp_dedup_hash' => $dedupHash],
            'branch_id'     => $property->branch_id,
            'agency_id'     => $property->agency_id,
        ]);
    }

    private function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') return ['Unknown', 'Lead'];
        $parts = preg_split('/\s+/', $name, 2);
        return [$parts[0], $parts[1] ?? ''];
    }
}
