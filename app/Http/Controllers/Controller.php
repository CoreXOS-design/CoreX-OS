<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The current request's query parameters, safe to hand to a paginator's
     * ->appends() (in place of ->withQueryString()). Laravel's global
     * ConvertEmptyStringsToNull middleware turns an explicitly-blank query
     * value (e.g. an "All" filter submitted as ?agent_id=) into null in
     * $request->query(). PHP's http_build_query() then silently DROPS any
     * null-valued key when a paginator builds its page=N links, so the next
     * request sees that filter as ABSENT rather than empty — tripping any
     * $request->has('x')-based default-narrowing (e.g. "no agent_id -> my
     * records only") right back on, even though the user explicitly chose
     * "All". Restoring '' for anything the middleware nulled keeps every
     * filter, "All" included, intact across every pagination link.
     */
    protected function paginationQuery(Request $request): array
    {
        return array_map(fn ($v) => $v ?? '', $request->query());
    }
}
