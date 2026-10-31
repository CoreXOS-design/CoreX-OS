<?php

namespace App\Services\TrainingVideos\Auto;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AT-525 (spec section 9, T6) - the ONLY way the recorder gets a signed-in browser: a short-lived database session for a user of the TRAINING agency
 * (agencies.is_training), refused for anyone else, so a recording can never show a real client (POPIA). The session is removed again when the recording ends.
 */
class TrainingSessionService
{
    public const EMAIL_DOMAIN = 'training.corexos.invalid';

    /** @return array{name:string,value:string,session_id:string,user_id:int} */
    public function issue(User $user): array
    {
        $this->assertTrainingUser($user);
        $store = app('session')->driver();
        $id = Str::random(40);
        $store->setId($id);
        $store->start();
        $store->put(Auth::guard('web')->getName(), $user->getAuthIdentifier());
        $store->put('password_hash_web', $user->getAuthPassword());
        $store->save();
        $name = (string) config('session.cookie');
        $value = app('encrypter')->encrypt(\Illuminate\Cookie\CookieValuePrefix::create($name, app('encrypter')->getKey()) . $id, false);

        return ['name' => $name, 'value' => $value, 'session_id' => $id, 'user_id' => (int) $user->id];
    }

    public function revoke(string $sessionId): void
    {
        DB::table(config('session.table', 'sessions'))->where('id', $sessionId)->delete();
    }

    public function assertTrainingUser(User $user): void
    {
        $agency = Agency::withoutGlobalScopes()->find($user->agency_id);
        if (! $agency || ! $agency->is_training) {
            throw new \RuntimeException('Training videos are only ever recorded as a user of the training agency - refusing ' . $user->email . '.');
        }
    }

    /** The training user a script logs in as: the agent / manager / admin of the training agency. */
    public function userFor(string $loginAs): User
    {
        $agency = Agency::withoutGlobalScopes()->where('is_training', true)->first();
        if (! $agency) {
            throw new \RuntimeException('There is no training agency yet - run: php artisan training:seed-agency');
        }
        $user = User::withoutGlobalScopes()->where('agency_id', $agency->id)->where('email', "training.{$loginAs}@" . self::EMAIL_DOMAIN)->first();
        if (! $user) {
            throw new \RuntimeException("The training agency has no '{$loginAs}' user - run: php artisan training:seed-agency");
        }

        return $user;
    }
}
