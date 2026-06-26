<?php

namespace App\Http\Controllers\PrivateProperty;

use App\Http\Controllers\Controller;
use App\Models\CommandCenter\CommandTask;
use App\Models\Contact;
use App\Models\ContactSource;
use App\Models\ContactType;
use App\Models\Property;
use App\Services\PrivateProperty\PrivatePropertyConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

        try {
            DB::transaction(function () use ($payload, $property) {
                $contact = $this->createLeadContact($payload, $property);

                $property->contacts()->syncWithoutDetaching([
                    $contact->id => ['role' => 'lead'],
                ]);

                $this->createLeadTask($payload, $property, $contact);
            });
        } catch (\Throwable $e) {
            // Log but still return 200 — PP must not retry on our internal errors.
            Log::channel('private_property')->error('PP webhook: lead processing failed', [
                'error'   => $e->getMessage(),
                'leadId'  => $payload['leadId'] ?? null,
            ]);
        }

        return response('OK', 200);
    }

    private function createLeadContact(array $payload, Property $property): Contact
    {
        [$first, $last] = $this->splitName($payload['leadName'] ?? '');

        // Part 2 — unify portal tagging: a PP enquirer is a Buyer, same as P24
        // (was tagged "Lead"). Falls back to "Lead" only if the Buyer type is absent.
        $leadTypeId   = ContactType::where('name', 'Buyer')->value('id')
                     ?? ContactType::where('name', 'Lead')->value('id');
        $leadSourceId = ContactSource::where('name', 'Private Property')->value('id');

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

    private function createLeadTask(array $payload, Property $property, Contact $contact): void
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
