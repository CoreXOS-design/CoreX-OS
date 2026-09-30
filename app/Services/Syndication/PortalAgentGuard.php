<?php

namespace App\Services\Syndication;

use App\Models\Property;
use App\Models\User;

/**
 * Portal Agent Mismatch Guard — .ai/specs/portal-agent-mismatch-guard.md.
 *
 * Answers, BEFORE CoreX sends a listing to Property24 or Private Property:
 *   • is every listing agent still active in CoreX?          (agent_inactive)
 *   • does the portal show someone other than the listing agent?  (agent_differs)
 * and turns a portal's own "agent not active" refusal into the same plain
 * answer (portal_agent_inactive / agent_differs).
 *
 * Reads ONLY CoreX's own stored data — never a portal call — so it costs nothing
 * on the refresh path (CLAUDE.md "Portal sync — the refresh cost contract").
 *
 * Johan's decision (2026-09-30): when the agent differs, CoreX warns and asks
 * first. It never switches the portal's agent on its own.
 */
class PortalAgentGuard
{
    public const P24 = 'p24';
    public const PP  = 'pp';

    public const AGENT_INACTIVE        = 'agent_inactive';
    public const AGENT_DIFFERS         = 'agent_differs';
    public const PORTAL_AGENT_INACTIVE = 'portal_agent_inactive';

    /**
     * The agent problem that should stop a send right now, or null when the
     * listing is clear to go out. Only agent_differs can be overridden (by the
     * user confirming the switch); agent_inactive never can.
     */
    public function check(Property $property, string $portal): ?array
    {
        $agents = $this->listingAgents($property);

        foreach ($agents as $user) {
            // Strict: a NULL is_active is not a deactivated agent.
            if ($user->trashed() || $user->is_active === false) {
                return $this->conflict($property, $portal, self::AGENT_INACTIVE, [
                    'message'   => "{$user->name} is no longer active in CoreX. Change the listing agent on this listing before sending it to " . $this->portalLabel($portal) . '.',
                    'can_switch' => false,
                ]);
            }
        }

        $held     = $this->heldRefs($property, $portal);
        $expected = $this->expectedRefs($property, $portal);

        // Unknown on either side — nothing to compare. The portal's own answer
        // (if it refuses) is turned into a conflict by fromPortalRejection().
        if ($held === null || $expected === null || $held === $expected) {
            return null;
        }

        return $this->differs($property, $portal, $held);
    }

    /**
     * The conflict to show on the listing panel right now: the live check, or a
     * stored portal refusal that the live check cannot recompute (the portal
     * said the listing agent's own profile is inactive over there).
     */
    public function current(Property $property, string $portal): ?array
    {
        if ($live = $this->check($property, $portal)) {
            return $live;
        }

        $stored = $property->{$portal . '_agent_conflict'};
        if (is_array($stored) && ($stored['code'] ?? null) === self::PORTAL_AGENT_INACTIVE) {
            return $stored;
        }

        return null;
    }

    /**
     * Turn a portal refusal naming inactive agents into a conflict, or null if
     * the message is not an agent refusal. P24 words it:
     *   "Some of the specified agents are not active. AgentIds: 191056."
     * Records what the portal holds so the next check() already knows.
     */
    public function fromPortalRejection(Property $property, string $portal, ?string $message): ?array
    {
        if (!$message || !preg_match('/agents? (?:are|is) not active\.?\s*AgentIds:\s*([\d,\s]+)/i', $message, $m)) {
            return null;
        }

        $rejected = $this->normalise(explode(',', $m[1]));
        if (empty($rejected)) {
            return null;
        }

        $expected = $this->expectedRefs($property, $portal) ?? [];

        // Every refused agent IS one of ours — the listing agent's own portal
        // profile is inactive over there. Switching cannot fix that.
        if (!array_diff($rejected, $expected)) {
            $names = $this->namesFor($property, $portal, $rejected);
            return $this->conflict($property, $portal, self::PORTAL_AGENT_INACTIVE, [
                'message'       => $this->portalLabel($portal) . ' says the agent profile for ' . $this->joinNames($names)
                    . ' is not active on ' . $this->portalLabel($portal) . '. It must be reactivated there before this listing can go out.',
                'can_switch'    => false,
                'portal_agents' => $names,
            ]);
        }

        // A refused agent is someone else — the portal still holds the listing
        // under a previous agent. Remember that so check() sees the mismatch.
        $property->forceFill([$portal . '_portal_agent_ids' => $rejected])->saveQuietly();

        return $this->differs($property, $portal, $rejected);
    }

    /** A send went through: the portal now holds exactly these agents. */
    public function recordSent(Property $property, string $portal, array $refs): void
    {
        $refs = $this->normalise($refs);
        $property->forceFill([
            $portal . '_portal_agent_ids' => empty($refs) ? null : $refs,
            $portal . '_agent_conflict'   => null,
        ])->saveQuietly();
    }

    /** A send was stopped on an agent problem: keep it for the panel and the listings filter. */
    public function recordConflict(Property $property, string $portal, array $conflict): void
    {
        $property->forceFill([$portal . '_agent_conflict' => $conflict])->saveQuietly();
    }

    /** CoreX users this listing goes out under — the listing agent, plus the co-listing agent. */
    public function listingAgents(Property $property): array
    {
        $ids = array_values(array_unique(array_filter([(int) $property->agent_id, (int) $property->pp_second_agent_id])));
        if (empty($ids)) {
            return [];
        }

        $users = User::withoutGlobalScopes()->withTrashed()->whereIn('id', $ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(fn ($id) => $users->get($id), $ids)));
    }

    /**
     * The portal ids the listing agents SHOULD appear under, or null when one is
     * not known yet (a P24 agent not yet registered is registered by the submit
     * itself, so there is nothing to compare until then).
     */
    public function expectedRefs(Property $property, string $portal): ?array
    {
        $agents = $this->listingAgents($property);
        if (empty($agents)) {
            return null;
        }

        $refs = [];
        foreach ($agents as $user) {
            // P24 drops agents who opted out (exclude_from_p24) from the payload,
            // so recordSent() never stores them; expecting them here made a
            // registered-but-opted-out co-agent a permanent agent_differs.
            if ($portal === self::P24 && $user->exclude_from_p24) {
                continue;
            }

            $ref = $this->refFor($property, $portal, $user);
            if ($ref === null) {
                return null;
            }
            $refs[] = $ref;
        }

        if (empty($refs)) {
            return null; // every agent is opted out — nothing is sent, nothing to compare
        }

        return $this->normalise($refs);
    }

    /** Which button resolves a conflict: a status-only reactivation, or a normal send. */
    public function retryAction(Property $property, string $portal): string
    {
        $status = (string) $property->{$portal . '_syndication_status'};
        $error  = (string) $property->{$portal . '_last_error'};

        return ($status === 'deactivated' || str_starts_with($error, 'Reactivation failed')) ? 'reactivate' : 'submit';
    }

    public function portalLabel(string $portal): string
    {
        return $portal === self::P24 ? 'Property24' : 'Private Property';
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function differs(Property $property, string $portal, array $held): array
    {
        $portalNames  = $this->namesFor($property, $portal, $held);
        $listingNames = array_map(fn (User $u) => $u->name, $this->listingAgents($property));

        return $this->conflict($property, $portal, self::AGENT_DIFFERS, [
            'message'       => $this->portalLabel($portal) . ' has this listing under ' . $this->joinNames($portalNames)
                . '. Send it under ' . $this->joinNames($listingNames) . ' instead?',
            'can_switch'    => true,
            'portal_agents' => $portalNames,
        ]);
    }

    private function conflict(Property $property, string $portal, string $code, array $extra): array
    {
        return array_merge([
            'code'           => $code,
            'portal'         => $portal,
            'listing_agents' => array_map(fn (User $u) => $u->name, $this->listingAgents($property)),
            'portal_agents'  => [],
            'action'         => $this->retryAction($property, $portal),
            'detected_at'    => now()->toIso8601String(),
        ], $extra);
    }

    private function heldRefs(Property $property, string $portal): ?array
    {
        $held = $property->{$portal . '_portal_agent_ids'};

        return is_array($held) && !empty($held) ? $this->normalise($held) : null;
    }

    private function refFor(Property $property, string $portal, User $user): ?string
    {
        if ($portal === self::PP) {
            // Same rule PrivatePropertyListingMapper::buildAgentIdString() sends.
            return (string) ($user->pp_external_ref ?: $user->id);
        }

        // P24 agents are per P24 agency — an id stamped for another agency is a
        // different P24 agent (see Property24SyndicationService::resolveRegisteredAgentId).
        $agencyId = $property->resolveP24AgencyId();
        if (empty($user->p24_agent_id) || $agencyId === null || (int) $user->p24_agent_agency_id !== (int) $agencyId) {
            return null;
        }

        return (string) $user->p24_agent_id;
    }

    /** Names for portal agent ids, falling back to the id when no CoreX user carries it. */
    private function namesFor(Property $property, string $portal, array $refs): array
    {
        $query = User::withoutGlobalScopes()->withTrashed()->where('agency_id', $property->agency_id);

        $users = $portal === self::P24
            ? $query->whereIn('p24_agent_id', $refs)->get()->keyBy(fn ($u) => (string) $u->p24_agent_id)
            : $query->where(fn ($q) => $q->whereIn('pp_external_ref', $refs)->orWhereIn('id', array_filter($refs, 'ctype_digit')))
                ->get()->keyBy(fn ($u) => (string) ($u->pp_external_ref ?: $u->id));

        return array_map(
            fn ($ref) => $users->get((string) $ref)?->name ?? ('agent #' . $ref . ' on ' . $this->portalLabel($portal)),
            $refs
        );
    }

    private function joinNames(array $names): string
    {
        $names = array_values($names);
        if (count($names) <= 1) {
            return $names[0] ?? 'an unknown agent';
        }

        return implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names);
    }

    /** Sorted, unique, trimmed string ids — so two sets compare with ===. */
    private function normalise(array $refs): array
    {
        $refs = array_values(array_unique(array_filter(array_map(fn ($r) => trim((string) $r), $refs), 'strlen')));
        sort($refs, SORT_STRING);

        return $refs;
    }
}
