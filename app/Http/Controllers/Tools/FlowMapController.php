<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tools;

use App\Http\Controllers\Controller;
use App\Services\FlowMap\FlowMapBuilder;
use Illuminate\Http\Request;

/**
 * Flow Map — a read-only, permission-aware guide to how every part of
 * CoreX interconnects and what comes next. Owns no entity; emits nothing.
 * Spec: .ai/specs/flows-map.md
 */
class FlowMapController extends Controller
{
    public function index(Request $request, FlowMapBuilder $builder)
    {
        // Route middleware (permission:access_flow_map) already gates the
        // page. Defence-in-depth: bail if somehow unauthenticated.
        $user = $request->user();
        abort_unless($user, 403);

        $map = $builder->build($user);

        return view('tools.flow-map.index', [
            'categories' => $map['categories'],
            'nodes'      => $map['nodes'],
        ]);
    }
}
