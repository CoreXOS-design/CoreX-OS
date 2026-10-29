<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Demo\Contexts;

use App\Models\Docuperfect\Template;

/** Any other agency document (HR, an acknowledgement, a letter, "Other"): neutral parties and the global sample data only. */
final class GenericContext extends AbstractDemoContext
{
    public function key(): string { return 'generic'; }

    public function label(): string { return 'General agency document'; }

    public function matches(Template $t, ?string $slug, ?string $txn): bool
    {
        return true;
    }

    public function parties(): array
    {
        return ['party_one' => ['label' => 'First party', 'name' => self::person('seller')], 'party_two' => ['label' => 'Second party', 'name' => self::person('buyer')], 'agent' => ['label' => 'Agent', 'name' => self::person('agent')]];
    }
}
