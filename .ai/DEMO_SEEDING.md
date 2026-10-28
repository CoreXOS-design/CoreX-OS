# Demo Seeding — Safe Commands (read before any demo/seeder work)

> **The real working database is `nexus_os`. Demo + seeder-verification work
> belongs in `nexus_os_demo` ONLY.** On 2026-05-19 a
> `migrate:fresh --seed --seeder=DemoDataSeeder` run against `nexus_os`
> wiped real local data. These rules + the in-code guard exist so it cannot
> happen again.

## The `demo` connection

`config/database.php` defines a dedicated **`demo`** connection — identical
to `mysql` but pinned to a separate database:

```
database => env('DB_DEMO_DATABASE', 'nexus_os_demo')
```

`.env` keeps `DB_DATABASE=nexus_os` (the app's real DB) untouched. Optionally
set `DB_DEMO_DATABASE=nexus_os_demo` in `.env` (the default already is that).

## The hard guard

`DemoDataSeeder::protectedDatabaseRefusal()` aborts with a clear error if the
target database is in `DemoDataSeeder::PROTECTED_DATABASES` (`['nexus_os']`).
It runs inside `DemoDataSeeder::run()` and as a pre-flight in `demo:seed` and
`demo:cleanup`. A guard pass GUARANTEES you are not on the real DB.

## Safe commands — use these

| Goal | Command |
|------|---------|
| Seed the demo dataset | `php artisan demo:seed` — auto-targets the `demo` connection |
| Clean demo data | `php artisan demo:cleanup` — auto-targets the `demo` connection |
| Rebuild demo schema + data from scratch | `php artisan migrate:fresh --database=demo --seed --seeder="Database\Seeders\DemoDataSeeder"` |
| Run any one seeder for demo verification | `php artisan db:seed --database=demo --class="Database\Seeders\<Seeder>"` |

## NEVER do this

```
# DROPS the real working DB — the guard fires AFTER migrate:fresh has
# already dropped tables, so it cannot save you here. Just never run it.
php artisan migrate:fresh --seed --seeder="Database\Seeders\DemoDataSeeder"
php artisan db:seed --class="Database\Seeders\DemoDataSeeder"   # no --database
```

`migrate:fresh` is a core Artisan command and drops tables **before** any
seeder guard runs. The `--database=demo` flag is therefore mandatory for any
demo `migrate:fresh`. The guard still hard-stops the plain `db:seed` path and
screams loudly if you forget `--database=demo`.

## Rule of thumb

If a command can drop/rewrite tables and you did not type `--database=demo`
(or use `demo:seed`/`demo:cleanup`), STOP — you are about to hit `nexus_os`.

## Demo-box fixes (28 Oct 2026) — the code now does what the database patches did

1. **`is_demo` is set by the code, not a trigger.** On a host with `COREX_INSTANCE_ROLE=demo` (`App\Support\Instance::isDemo()`), the trait
   `App\Models\Concerns\FlagsDemoRows` saves every `properties`, `deals`, `market_reports`, `market_report_comp_rows` and `scheme_owners` row
   with `is_demo = 1`, whatever flow created it (screen, import, AI report read, job). An explicit `is_demo = false` does not survive on the demo
   box. `tracked_properties` are deliberately NOT covered (Johan's ruling: that layer is shared market intelligence). Only Eloquent saves are
   covered; a raw `DB::table()->insert()` bypasses model events by design, and none of these five tables has one in app code. The DB trigger on
   the demo box can be dropped once this is deployed there. Test: `tests/Feature/Demo/DemoRowFlagTest.php`.
2. **`demo:refresh` / a hand-run `DemoDataSeeder` fills the web templates' fields.** `WebTemplateSeeder` inserts the web lease templates with
   `fields_json = []`; Stage 0 of `DemoDataSeeder` now runs `populate:web-template-fields` straight after the seeder loop (template 26 on the demo box
   had an empty field list). Test: `tests/Feature/Demo/DemoSeedWebTemplateFieldsTest.php`.
3. **Every queue lane needs a worker on every box.** See `docs/DEPLOY.md` "Queue lanes", `docs/infra/supervisor/corex-worker-lane.conf.template`,
   `scripts/render-queue-lane-conf.sh` (prints, installs nothing) and the `corex:queue-worker-coverage` check (every 5 minutes, scheduler).
4. **`scripts/deploy.sh` reloads the php-fpm service the box actually runs** (live = `php8.3-fpm`, staging/demo = `php8.2-fpm`), detected from
   systemd; `PHP_FPM_SERVICE=<unit>` overrides; two pools with no way to tell which = it refuses rather than guesses. `qa-deploy.sh` keeps its
   fixed `php8.2-fpm` (QA1 shares the 8.2 pool).
