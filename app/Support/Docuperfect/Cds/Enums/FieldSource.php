<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds\Enums;

/**
 * CDS v2 — where a Field's value comes from (spec §2 Field.source).
 *
 *  - Auto        : resolved by CoreX from a pillar (Property/Contact/Deal/Agent) at render time.
 *  - PartyInput  : typed by a signing party in the signing view.
 *  - AgentInput  : typed by the agent when preparing the signing request.
 */
enum FieldSource: string
{
    case Auto = 'auto';
    case PartyInput = 'party_input';
    case AgentInput = 'agent_input';
}
