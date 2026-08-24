# Hazard: `Auth::logout()` silently persists dirty attributes on the authenticated user model

**Date:** 2026-08-24
**Found by:** cc2, while verifying the properties share-button chooser (unrelated feature — see `.ai/audits/` share-attribution-chooser work; this note stands alone)
**Severity:** Real, reproducible, framework-level. Not CoreX-specific code, but CoreX has no guard against it.
**Impact this time:** Corrupted `users.role` on a real account (id 22, johan@hfcoastal.co.za) on QA1 — `role` was silently forced to `'assistant'` while `is_admin`/`is_assistant` were separately reverted, leaving an invalid combination (`role='assistant', is_admin=1, is_assistant=0`) until manually fixed. Confirmed and corrected (`role` restored to `'admin'`, matching every other admin peer).

## What happened

While writing a verification script to test the properties share-button's assistant-hiding logic, I did this (via `php artisan tinker`, outside a real HTTP request):

```php
$user = \App\Models\User::find(22);   // fresh fetch, real row
$user->is_assistant = true;           // in-memory only — NO ->save() call anywhere
auth()->login($user);
view('some.partial', ['property' => $property])->render();
auth()->logout();
```

No line here calls `->save()`, `->update()`, or anything that looks like persistence. I expected this to be a pure read-only render. It was not: after `auth()->logout()` ran, the real `users` row for id 22 had `is_assistant = 1` in the database. The same thing happened a second time (during root-cause reproduction) and additionally forced `role = 'assistant'` — because `User::booted()`'s `static::saving` hook (see `app/Models/User.php:331-334`) enforces "an assistant is always `role='assistant'`, `is_admin=false`" on every save, including this invisible one.

## Root cause — exact file:line

`vendor/laravel/framework/src/Illuminate/Auth/EloquentUserProvider.php:92-103`:

```php
public function updateRememberToken(UserContract $user, #[\SensitiveParameter] $token)
{
    $user->setRememberToken($token);

    $timestamps = $user->timestamps;
    $user->timestamps = false;
    $user->save();                    // <-- persists EVERY dirty attribute on $user, not just remember_token
    $user->timestamps = $timestamps;
}
```

Called from `vendor/laravel/framework/src/Illuminate/Auth/SessionGuard.php:650-658`:

```php
public function logout()
{
    $user = $this->user();
    $this->clearUserDataFromStorage();
    if (! is_null($this->user) && ! empty($user->getRememberToken())) {
        $this->cycleRememberToken($user);   // -> updateRememberToken() above
    }
    ...
}
```

`cycleRememberToken()` (`SessionGuard.php:723-728`) only sets a new remember-token value on the model, then calls `$this->provider->updateRememberToken($user, $token)` — which calls `$user->save()` on the **entire model**, disabling timestamps first (which is why `updated_at` does NOT move — that was the tell that made this reproducible and traceable, not a normal `->save()`/`->update()` call).

**Trigger condition:** `logout()` only cycles the remember token — and therefore only saves — when the authenticated user already has a non-empty `remember_token` in the database (`! empty($user->getRememberToken())`, `SessionGuard.php:656`). Most real users on this system have a stored `remember_token` from some prior login, so this is not a narrow edge case — it's live for the majority of real accounts.

## The actual hazard, generalized

**Any code path anywhere in the app that does the following, in one request/process, will silently write whatever is dirty on the user model to the database:**

1. Reads `auth()->user()` (or fetches the same model and calls `Auth::login()`/`Auth::setUser()` on it).
2. Mutates an attribute on that model **for display, comparison, or any in-memory purpose only** — no `->save()` intended.
3. At any point later in the same request, something calls `Auth::logout()` / `auth()->logout()` (impersonation-stop flows, "switch agency" flows, test/verification harnesses, admin "log out as user" tooling, etc.).

Step 3 doesn't need to be related to step 2 at all — it just needs to run in the same request against the same guard instance, because `SessionGuard` holds a single `$this->user` reference and `logout()` operates on whatever `$this->user` currently is, dirty attributes and all.

This is standard Laravel behavior (framework code, not a CoreX bug), and I found no evidence it currently fires in a real production request path — nothing in the app's own controllers mutates `auth()->user()` in memory and then calls `Auth::logout()` in the same request today, as far as this investigation covered. It bit here because a verification script did exactly that pattern outside the normal request lifecycle. But it is a live footgun: any future code — impersonation/assistant-switch flows, admin tooling, test harnesses, a "preview as this role" feature — that mutates the authenticated user object and later logs out is one edit away from silently corrupting a real row, with no timestamp bump to flag it.

## Safe pattern going forward

**Never mutate a real, DB-backed `User` (or any Eloquent model tied to the authenticated guard) in memory unless you intend the mutation to be saved.** Specifically:

- **To test/verify role- or flag-gated Blade/view logic against a hypothetical user state:** render the view directly (`view(...)->render()`) with a user object that is either (a) the real user with the flag **actually toggled and intentionally saved**, then reverted afterward via a raw `DB::table(...)->update()` (not `->save()`, to avoid re-triggering model hooks/side effects you don't want) — or (b) a transient, non-persisted model instance with a fake/nonexistent primary key (`new User(); $u->id = 999999999; $u->is_assistant = true;`) so that even if something *does* try to save it, there's no real row to corrupt.
- **Never call `auth()->login($mutatedUser)` on a real, existing user id purely to drive a Blade `auth()->user()` read**, if anything downstream might call `logout()` in the same process — which includes most `tinker`/script-based verification, since it's easy to add a cleanup `auth()->logout()` without realizing it's not a no-op.
- If you must exercise a real authenticated request end-to-end (not just a view render) against a modified user state, make the change via an explicit, intentional `DB::table('users')->update([...])` (or a real `->save()` if the model hooks are meant to apply, e.g. testing the `is_assistant` invariant hook itself), verify the row, run the request, then explicitly revert via the same raw-update path — and re-check the row afterward, not just assume the revert worked, since (as this incident shows) a *second* silent save can happen during the same investigation and touch columns you didn't revert.

## Recommendation (not applied — flagging only, per this repo's report-don't-fix-outside-scope rule)

Worth considering, if this needs a permanent CoreX-side guard rather than relying on developer discipline: `AuthenticateSession`/a custom middleware or a `Login`/`Logout` event listener that asserts `! auth()->user()?->isDirty()` before allowing logout to proceed past `cycleRememberToken()`, or a `User::booted()` `static::saving` early-exit when `$user->timestamps === false` (i.e. explicitly refuse to silently persist attribute drift that arrived via the remember-token save path). This is a judgment call for whoever owns auth/session architecture, not something this note is authorizing.

## Addendum 2026-08-24 — served checkouts and branch discipline

A second, unrelated incident the same day involved the same root habit — losing track of which state a shared checkout is actually in — so it belongs on the record here rather than as a separate note.

`/corex-qa1` is a served QA environment, not a personal scratch checkout. After the share-attribution-chooser work in this note landed on Staging, the checkout was left sitting on the feature branch it was built on (`share-attribution-chooser`, itself cut from a WIP branch 21 commits ahead of `origin/main`) instead of being returned to `QA1`. The next lane needing QA1 (an e-sign task) hit real merge conflicts against files with nothing to do with the work that had been done there, correctly refused to switch blind, and blocked until this was untangled.

Diagnosis before touching anything: every commit on the stranded branch was confirmed reachable elsewhere first (the 21 base commits on `origin/qa1-property-status-prospecting`, the 1 feature commit on `origin/Staging` via an identical patch) — so it was disposable as a checkout occupant, but that had to be *proven*, not assumed, before switching. Switching to `QA1` also surfaced a stale compiled autoloader (`vendor/composer`'s classmap still referenced a file only the previous branch's `composer.json` declared) and a stale `public/build` bundle carrying code `QA1`'s own source doesn't have — both silent failure modes that only show up by actually rendering a page, not by checking the branch switched cleanly.

**Rule going forward: a served checkout (QA1, Staging, live) gets returned to its normal branch when a lane finishes working in it — every time, not just when someone downstream gets blocked by the alternative.** Before leaving a served checkout on a feature branch even briefly, confirm the commits on it exist elsewhere; before declaring a branch switch on a served checkout complete, run `composer dump-autoload` and rebuild frontend assets (`npm run build`) if the branches differ in either area, then render a real page — don't stop at "checkout succeeded."
