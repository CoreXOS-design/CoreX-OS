<?php

namespace App\Http\Controllers\PrivateProperty;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PrivateProperty\PrivatePropertySyndicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentPpController extends Controller
{
    private PrivatePropertySyndicationService $syndicationService;

    public function __construct(PrivatePropertySyndicationService $syndicationService)
    {
        $this->syndicationService = $syndicationService;
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
}
