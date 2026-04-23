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
     * Two modes:
     *   - With pp_encrypted_id: claim ownership via UpdateUniqueAgentID, mapping
     *     PP's encrypted internal ID to the supplied external_ref as AgentId.
     *   - Without pp_encrypted_id: push a normal UpdateAgent with the new
     *     external_ref as AgentId so PP updates its record directly.
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

        if ($ppEncryptedId !== '') {
            $result = $this->soapClient->updateUniqueAgentId($ppEncryptedId, $externalRef);

            if (isset($result['error']) && $result['error'] === true) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'UpdateUniqueAgentID failed',
                ], 422);
            }

            $user->update(['pp_unique_agent_id' => $ppEncryptedId]);

            return response()->json([
                'success'            => true,
                'message'            => 'PP External Ref updated and ownership claimed',
                'external_ref'       => $externalRef,
                'pp_unique_agent_id' => $ppEncryptedId,
            ]);
        }

        $agentData = $this->mapper->buildAgentData($user, true);

        if (empty($agentData['TelCell'])) {
            return response()->json([
                'success' => false,
                'message' => 'Agent has no phone/cell number — required by Private Property',
            ], 422);
        }

        $agentData['AgentId'] = $externalRef;

        $result = $this->soapClient->updateAgent($agentData);

        if (isset($result['error']) && $result['error'] === true) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'UpdateAgent failed',
            ], 422);
        }

        return response()->json([
            'success'      => true,
            'message'      => 'PP External Ref updated',
            'external_ref' => $externalRef,
        ]);
    }
}
