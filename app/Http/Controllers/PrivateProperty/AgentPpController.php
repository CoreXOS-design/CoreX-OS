<?php

namespace App\Http\Controllers\PrivateProperty;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentPpController extends Controller
{
    private PrivatePropertySyndicationService $syndicationService;
    private PrivatePropertySoapClient $soapClient;
    private PrivatePropertyListingMapper $mapper;

    public function __construct(
        PrivatePropertySyndicationService $syndicationService,
        PrivatePropertySoapClient $soapClient,
        PrivatePropertyListingMapper $mapper
    ) {
        $this->syndicationService = $syndicationService;
        $this->soapClient = $soapClient;
        $this->mapper = $mapper;
    }

    /**
     * List every agent profile on the PP branch (so admins can spot
     * duplicates created when UpdateAgent was called with mismatched
     * AgentIds). Parses the embedded XML DataSet returned by
     * GetAllAgentsForBranch and renders a sortable table.
     */
    public function index()
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        $resp = $this->soapClient->getAllAgentsForBranch();

        $agents = [];
        $error  = null;

        if (isset($resp['error']) && $resp['error'] === true) {
            $error = $resp['message'] ?? 'Failed to fetch agents from PP';
        } else {
            $xml = $this->extractAgentsXml($resp);
            if ($xml !== '') {
                $agents = $this->parseAgentDataSet($xml);
            }
            if (empty($agents)) {
                $error = 'PP returned a response but no agent rows were parsed. '
                       . 'Raw response (truncated): '
                       . substr(json_encode($resp), 0, 1500);
            }
        }

        $byUser = User::query()
            ->whereNotNull('email')
            ->get(['id', 'name', 'email'])
            ->keyBy(fn ($u) => strtolower(trim($u->email)));

        foreach ($agents as &$a) {
            $key = strtolower(trim($a['email'] ?? ''));
            $match = $byUser[$key] ?? null;
            $a['corex_user_id']   = $match?->id;
            $a['corex_user_name'] = $match?->name;
            $a['is_duplicate_external_ref'] = false;
        }
        unset($a);

        $emailCounts = array_count_values(array_map(
            fn ($a) => strtolower(trim($a['email'] ?? '')),
            $agents
        ));
        foreach ($agents as &$a) {
            $key = strtolower(trim($a['email'] ?? ''));
            if ($key !== '' && ($emailCounts[$key] ?? 0) > 1) {
                $a['is_duplicate_external_ref'] = true;
            }
        }
        unset($a);

        return view('admin.pp.agents', [
            'agents' => $agents,
            'error'  => $error,
        ]);
    }

    /**
     * Deactivate a PP agent by its encrypted PrivatePropertyAgentId.
     * Used to clean up duplicate profiles that don't map to a CoreX user.
     * Sends UpdateAgent with Active=false.
     */
    public function deactivateByEncryptedId(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        $validated = $request->validate([
            'pp_encrypted_id' => 'required|string|max:500',
            'agent_id'        => 'required|string|max:100',
            'first_name'      => 'nullable|string|max:100',
            'last_name'       => 'nullable|string|max:100',
            'email'           => 'nullable|string|max:200',
            'tel_cell'        => 'nullable|string|max:50',
        ]);

        $payload = [
            'AgentId'                => $validated['agent_id'],
            'FirstName'              => $validated['first_name'] ?? '',
            'LastName'               => $validated['last_name'] ?? '',
            'Email'                  => $validated['email'] ?? '',
            'TelCell'                => $validated['tel_cell'] ?? '',
            'TelWork'                => $validated['tel_cell'] ?? '',
            'TelHome'                => '',
            'Active'                 => false,
            'BranchId'               => \App\Services\PrivateProperty\PrivatePropertyConfig::forCurrentAgency()['branch_guid'],
            'PrivatePropertyAgentId' => $validated['pp_encrypted_id'],
            'PrivysealAlias'         => '',
        ];

        $result = $this->soapClient->updateAgent($payload);

        if (isset($result['error']) && $result['error'] === true) {
            $message = $result['message'] ?? 'UpdateAgent (deactivate) failed';
            $listings = $this->parseActiveListingsFromError($message);

            return response()->json([
                'success'         => false,
                'message'         => $message,
                'active_listings' => $listings,
                'pp_encrypted_id' => $validated['pp_encrypted_id'],
                'agent_id'        => $validated['agent_id'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'PP agent profile ' . $validated['agent_id'] . ' deactivated',
        ]);
    }

    /**
     * Hard-delete a listing referenced by PP's PP121 error.
     * Called from the duplicate-agent cleanup popup. Deactivates on PP first
     * (so PP releases the agent association) then force-deletes the Property
     * row in CoreX. This is the one place hard deletion is allowed — it's a
     * sandbox/duplicate cleanup workflow under manage_users.
     */
    public function purgeListing(int $id): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        $property = \App\Models\Property::withTrashed()->find($id);

        // Always tell PP to deactivate this listing ID, even if the Property
        // row no longer exists in CoreX (orphaned PP-side records). Try both
        // listing types — PP rejects the wrong one but accepts the right one.
        $ppParts = [];
        $ppOk    = false;

        $preferredType = $property && in_array(strtolower($property->listing_type ?? ''), ['rental'])
            ? 'Rental'
            : 'Sale';
        $types = $preferredType === 'Rental' ? ['Rental', 'Sale'] : ['Sale', 'Rental'];

        foreach ($types as $type) {
            $resp = $this->soapClient->deactivateListing((string) $id, $type);
            if (isset($resp['error']) && $resp['error'] === true) {
                $ppParts[] = $type . ': ' . ($resp['message'] ?? 'error');
                continue;
            }
            $ppOk = true;
            $ppParts[] = $type . ': deactivated';
            break;
        }

        $ppMessage = implode(' | ', $ppParts);

        if ($property) {
            $property->forceDelete();
        }

        return response()->json([
            'success'    => true,
            'message'    => 'Listing #' . $id . ($property ? ' purged from CoreX' : ' (orphan — not in CoreX)')
                          . ($ppMessage ? ' — PP: ' . $ppMessage : ''),
            'pp_ok'      => $ppOk,
            'pp_message' => $ppMessage,
        ]);
    }

    /**
     * Parse "active listings: 16, 17, 18" from PP's PP121 error and resolve
     * each ID against the CoreX Property table so the UI can offer one-click
     * deactivation per listing.
     */
    private function parseActiveListingsFromError(string $message): array
    {
        if (!preg_match('/active listings?:\s*([0-9,\s]+)/i', $message, $m)) {
            return [];
        }

        $ids = array_filter(array_map('intval', preg_split('/[,\s]+/', $m[1])));
        if (empty($ids)) {
            return [];
        }

        $properties = \App\Models\Property::withTrashed()
            ->whereIn('id', $ids)
            ->get(['id', 'headline', 'title', 'address', 'suburb', 'town', 'pp_ref', 'agent_id', 'deleted_at'])
            ->keyBy('id');

        $out = [];
        foreach ($ids as $id) {
            $p = $properties->get($id);
            $out[] = [
                'id'           => $id,
                'exists'       => (bool) $p,
                'soft_deleted' => $p?->deleted_at !== null,
                'headline'     => $p?->headline ?? $p?->title ?? '(unknown listing)',
                'address'      => $p ? trim(($p->address ?? '') . ', ' . ($p->suburb ?? '') . ' ' . ($p->town ?? '')) : '',
                'pp_ref'       => $p?->pp_ref,
                'agent_id'     => $p?->agent_id,
                'purge_url'  => route('admin.pp.agents.purge-listing', ['id' => $id]),
                'view_url'   => $p ? route('corex.properties.show', ['property' => $id]) : null,
            ];
        }

        return $out;
    }

    /**
     * Pull the XML DataSet string out of whatever shape PP returned.
     * The "any" field can sit at different depths depending on PHP SoapClient
     * version and WSDL import style.
     */
    private function extractAgentsXml(array $resp): string
    {
        $candidates = [
            $resp['GetAllAgentsForBranchResult']['any'] ?? null,
            $resp['any'] ?? null,
            is_string($resp['GetAllAgentsForBranchResult'] ?? null) ? $resp['GetAllAgentsForBranchResult'] : null,
        ];
        foreach ($candidates as $c) {
            if (is_string($c) && str_contains($c, '<Agents')) {
                return $c;
            }
        }
        $flat = json_encode($resp);
        if (is_string($flat) && str_contains($flat, '<Agents')) {
            return $flat;
        }
        return '';
    }

    /**
     * Parse the DataSet XML returned in GetAllAgentsForBranchResult.any.
     * Falls back to regex if XML parsing yields nothing (handles escaped
     * XML inside JSON wrappers).
     */
    private function parseAgentDataSet(string $xml): array
    {
        $xml = html_entity_decode($xml);
        $xml = str_replace(['\\"', '\\/'], ['"', '/'], $xml);

        $rows = [];
        try {
            $previous = libxml_use_internal_errors(true);
            $doc = new \DOMDocument();
            $doc->loadXML($xml);
            $xpath = new \DOMXPath($doc);
            $xpath->registerNamespace('diffgr', 'urn:schemas-microsoft-com:xml-diffgram-v1');

            $nodes = $xpath->query('//*[local-name()="NewDataSet"]/*[local-name()="Agents"]');
            if (!$nodes || $nodes->length === 0) {
                $nodes = $xpath->query('//*[local-name()="Agents"]');
            }
            foreach ($nodes as $node) {
                $row = [];
                foreach ($node->childNodes as $child) {
                    if ($child->nodeType === XML_ELEMENT_NODE) {
                        $row[$child->nodeName] = trim($child->textContent);
                    }
                }
                $candidate = [
                    'id'                       => $row['ID'] ?? '',
                    'agent_id'                 => $row['AgentId'] ?? '',
                    'pp_encrypted_id'          => $row['PrivatePropertyAgentId'] ?? '',
                    'first_name'               => $row['firstName'] ?? '',
                    'last_name'                => $row['LastName'] ?? '',
                    'email'                    => $row['email'] ?? '',
                    'contact_number'           => trim($row['ContactNumber'] ?? ''),
                    'image_url'                => $row['AgentImageUrl'] ?? '',
                ];
                if ($candidate['pp_encrypted_id'] === '' && $candidate['agent_id'] === '') {
                    continue;
                }
                $rows[] = $candidate;
            }
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        } catch (\Throwable $e) {
            // fall through with empty rows
        }

        if (empty($rows) && preg_match_all('/<Agents\b[^>]*>(.*?)<\/Agents>/s', $xml, $matches)) {
            foreach ($matches[1] as $inner) {
                $row = [];
                foreach (['ID','AgentId','PrivatePropertyAgentId','firstName','LastName','email','ContactNumber','AgentImageUrl'] as $field) {
                    if (preg_match('/<' . $field . '\b[^>]*>(.*?)<\/' . $field . '>/s', $inner, $m)) {
                        $row[$field] = trim(html_entity_decode($m[1]));
                    } elseif (preg_match('/<' . $field . '\b[^>]*\/>/', $inner)) {
                        $row[$field] = '';
                    }
                }
                if (!empty($row)) {
                    $rows[] = [
                        'id'              => $row['ID'] ?? '',
                        'agent_id'        => $row['AgentId'] ?? '',
                        'pp_encrypted_id' => $row['PrivatePropertyAgentId'] ?? '',
                        'first_name'      => $row['firstName'] ?? '',
                        'last_name'       => $row['LastName'] ?? '',
                        'email'           => $row['email'] ?? '',
                        'contact_number'  => trim($row['ContactNumber'] ?? ''),
                        'image_url'       => $row['AgentImageUrl'] ?? '',
                    ];
                }
            }
            // Dedupe by encrypted ID — diffgram repeats rows in a `before` block.
            $seen = [];
            $rows = array_values(array_filter($rows, function ($r) use (&$seen) {
                $key = $r['pp_encrypted_id'] !== '' ? $r['pp_encrypted_id'] : ($r['id'] . '|' . $r['agent_id']);
                if (isset($seen[$key])) return false;
                $seen[$key] = true;
                return true;
            }));
        }

        usort($rows, fn ($a, $b) => strcmp(
            ($a['email'] ?? '') . ($a['agent_id'] ?? ''),
            ($b['email'] ?? '') . ($b['agent_id'] ?? '')
        ));

        return $rows;
    }

    /**
     * Build a copy-paste email block for Private Property listing/agent
     * mapping requests. PP periodically ships a stock file and asks us to
     * fill in our internal IDs against their rows. This view exposes the
     * CoreX-side data they need: every PP-syndicated user (PP external ref,
     * encrypted ID, CoreX user id) and every PP-syndicated listing (PP ref,
     * PP feed ref, CoreX property id, address, agent).
     */
    public function mappingEmail()
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        $agents = User::query()
            ->where(function ($q) {
                $q->whereNotNull('pp_external_ref')
                  ->orWhereNotNull('pp_unique_agent_id');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'pp_external_ref', 'pp_unique_agent_id']);

        $listings = \App\Models\Property::query()
            ->where(function ($q) {
                $q->where('pp_syndication_enabled', true)
                  ->orWhereNotNull('pp_ref')
                  ->orWhereNotNull('pp_listing_feed_ref');
            })
            ->with(['agent:id,name'])
            ->orderBy('id')
            ->get([
                'id', 'headline', 'title', 'address', 'suburb', 'town',
                'listing_type', 'pp_ref', 'pp_listing_feed_ref',
                'pp_syndication_status', 'agent_id',
            ]);

        return view('admin.pp.mapping-email', [
            'agents'   => $agents,
            'listings' => $listings,
        ]);
    }

    /**
     * Register/sync an agent on Private Property.
     */
    public function sync(User $user): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        $result = $this->syndicationService->registerAgent($user);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Update PP ownership — claim the agent's encrypted PP ID.
     */
    public function updateId(User $user, Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        $request->validate([
            'pp_agent_id' => 'required|string|max:100',
        ]);

        $result = $this->syndicationService->updateUniqueAgentId($user, $request->pp_agent_id);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Update the PP External Ref (AgentId) for an agent.
     *
     * Always uses UpdateUniqueAgentID — UpdateAgent creates a new profile
     * when the AgentId doesn't already exist on PP, which is never what we
     * want here. UpdateUniqueAgentID remaps PP's existing internal record
     * (identified by its encrypted PrivatePropertyAgentId) to the supplied
     * External Ref without creating a duplicate.
     *
     * Encrypted ID resolution order:
     *   1. pp_encrypted_id supplied in the request (admin pasted from PP support)
     *   2. user.pp_unique_agent_id stored on a previous claim
     *   3. fetch from PP via GetAgent(current External Ref)
     */
    public function updateExternalRef(User $user, Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        $validated = $request->validate([
            'external_ref'    => 'required|string|max:100',
            'pp_encrypted_id' => 'nullable|string|max:500',
        ]);

        $externalRef   = trim($validated['external_ref']);
        $ppEncryptedId = trim($validated['pp_encrypted_id'] ?? '');

        if ($ppEncryptedId === '') {
            $ppEncryptedId = trim((string) ($user->pp_unique_agent_id ?? ''));
        }

        if ($ppEncryptedId === '') {
            $ppEncryptedId = $this->fetchEncryptedAgentIdFromPp($user);
        }

        if ($ppEncryptedId === '') {
            return response()->json([
                'success' => false,
                'message' => 'Could not locate this agent on Private Property. '
                           . 'Sync the agent first (creates the profile), or paste '
                           . 'the encrypted PrivatePropertyAgentId from PP support '
                           . 'into the field below.',
            ], 422);
        }

        $result = $this->soapClient->updateUniqueAgentId($ppEncryptedId, $externalRef);

        if (isset($result['error']) && $result['error'] === true) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'UpdateUniqueAgentID failed',
            ], 422);
        }

        // Persist both the encrypted PP ID and the chosen External Ref so
        // future Sync Agent calls don't overwrite this value with $user->id.
        $user->update([
            'pp_unique_agent_id' => $ppEncryptedId,
            'pp_external_ref'    => $externalRef,
        ]);

        return response()->json([
            'success'            => true,
            'message'            => 'PP External Ref remapped via UpdateUniqueAgentID (now ' . $externalRef . ')',
            'external_ref'       => $externalRef,
            'pp_unique_agent_id' => $ppEncryptedId,
        ]);
    }

    /**
     * Try to discover PP's encrypted PrivatePropertyAgentId for a user by
     * looking up their current External Ref via GetAgent. Returns '' on miss.
     */
    private function fetchEncryptedAgentIdFromPp(User $user): string
    {
        $candidates = array_unique(array_filter([
            (string) $user->id,
            (string) ($user->pp_external_ref ?? ''),
        ]));

        foreach ($candidates as $candidate) {
            try {
                $resp = $this->soapClient->getAgent($candidate);
            } catch (\Throwable $e) {
                continue;
            }

            if (isset($resp['error']) && $resp['error'] === true) {
                continue;
            }

            $encrypted = $this->extractEncryptedId($resp);
            if ($encrypted !== '') {
                return $encrypted;
            }
        }

        return '';
    }

    private function extractEncryptedId(array $resp): string
    {
        $candidates = [
            $resp['PrivatePropertyAgentId'] ?? null,
            $resp['GetAgentResult']['PrivatePropertyAgentId'] ?? null,
            $resp['Agent']['PrivatePropertyAgentId'] ?? null,
        ];

        foreach ($candidates as $value) {
            $value = trim((string) ($value ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
