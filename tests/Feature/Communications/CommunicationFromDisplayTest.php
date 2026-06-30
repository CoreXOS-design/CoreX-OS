<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\Communication;
use App\Models\User;
use Tests\TestCase;

/**
 * AT-137 item 2 — the archive/thread "from" must be direction-aware: OUTBOUND
 * shows the AGENT (owner), INBOUND shows the contact (from_identifier). Pure
 * accessor test (no DB) — instantiates models and sets the owner relation.
 */
final class CommunicationFromDisplayTest extends TestCase
{
    public function test_outbound_shows_the_agent_owner_name(): void
    {
        $comm = new Communication(['direction' => 'outbound', 'from_identifier' => '27713510291']);
        $comm->setRelation('owner', new User(['name' => 'Johan Reichel']));

        $this->assertSame('Johan Reichel', $comm->from_display);
    }

    public function test_inbound_shows_the_contact_identifier(): void
    {
        $comm = new Communication(['direction' => 'inbound', 'from_identifier' => '27713510291']);
        $comm->setRelation('owner', null);

        $this->assertSame('27713510291', $comm->from_display);
    }

    public function test_outbound_without_owner_falls_back_to_agent_label(): void
    {
        $comm = new Communication(['direction' => 'outbound', 'from_identifier' => '27713510291']);
        $comm->setRelation('owner', null);

        $this->assertSame('Agent', $comm->from_display);
    }
}
