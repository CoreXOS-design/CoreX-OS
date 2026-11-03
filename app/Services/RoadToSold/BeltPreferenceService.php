<?php

declare(strict_types=1);

namespace App\Services\RoadToSold;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold page layout v2 (23.31): which of the three belts (get / maintain / sell) the user left open. The URL (?belt=) is the click that changes it and is
 * remembered per USER (table rts_user_preferences), so it follows the person to any browser. Until that table exists the choice is simply not remembered (the page
 * opens on the default belt); nothing breaks.
 */
final class BeltPreferenceService
{
    public const KEYS = ['get', 'maintain', 'sell'];

    public function get(User $user): ?string
    {
        if (! Schema::hasTable('rts_user_preferences')) {
            return null;
        }
        $v = DB::table('rts_user_preferences')->where('user_id', $user->id)->value('belt_open');

        return in_array($v, self::KEYS, true) ? (string) $v : null;
    }

    public function remember(User $user, string $belt): void
    {
        if (! in_array($belt, self::KEYS, true) || ! Schema::hasTable('rts_user_preferences')) {
            return;
        }
        $now = now();
        DB::table('rts_user_preferences')->updateOrInsert(['user_id' => $user->id], ['belt_open' => $belt, 'updated_at' => $now]);
        DB::table('rts_user_preferences')->where('user_id', $user->id)->whereNull('created_at')->update(['created_at' => $now]);
    }
}
