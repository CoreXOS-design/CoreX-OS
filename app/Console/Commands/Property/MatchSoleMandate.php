<?php

declare(strict_types=1);

namespace App\Console\Commands\Property;

use App\Models\Agency;
use App\Services\Prospecting\MicWork\SoleMandateMatcher;
use Illuminate\Console\Command;

/** AT-528: run the wording-based sole-mandate match over every advert that has text (dry run unless --apply). New snippets are matched on arrival by the observer. */
final class MatchSoleMandate extends Command
{
    protected $signature = 'mic:match-sole-mandate {--agency= : One agency id} {--apply : Write the flag}';

    protected $description = 'Match the agency mandate words against advert wording and store the flag';

    public function handle(SoleMandateMatcher $matcher): int
    {
        $ids = $this->option('agency') ? [(int) $this->option('agency')] : Agency::query()->pluck('id')->map(fn ($i) => (int) $i)->all();
        foreach ($ids as $id) {
            $r = $matcher->rematchAgency($id, (bool) $this->option('apply'));
            $this->line("agency {$id}: " . json_encode($r) . ($this->option('apply') ? '' : ' (dry run)'));
        }

        return self::SUCCESS;
    }
}
