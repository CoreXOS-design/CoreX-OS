<?php

namespace App\Services;

/**
 * System-wide maintenance mode for CoreX (AT-93).
 *
 * Source of truth is a single flag FILE under storage/framework — a
 * filesystem stat with ZERO database or cache dependency. This is
 * deliberate: the maintenance gate runs on every web request and the
 * branded down-page must render even when the DB is stressed or down
 * (the most likely thing to be stressed during a go-live cutover).
 *
 * This is NOT Laravel's `php artisan down`. Laravel's mechanism
 * short-circuits in PreventRequestsDuringMaintenance *before* routing
 * and auth, so it cannot let an authenticated System Owner through and
 * can block the route needed to lift it. CoreX maintenance mode is a
 * gate that runs AFTER session boot, so it knows WHO the user is and
 * always lets System Owners (and the login/toggle routes) through.
 *
 * Scope is GLOBAL / system-level — the whole platform, all agencies.
 * It is intentionally not agency-scoped.
 *
 * Spec: .ai/specs/maintenance-mode.md
 */
class MaintenanceMode
{
    /**
     * Absolute path of the flag file. Lives in storage/framework, which is
     * always present and writable (Laravel keeps its own down-file here).
     */
    public function flagPath(): string
    {
        return storage_path('framework/corex-maintenance.flag');
    }

    /**
     * Is maintenance mode currently ON? A pure filesystem stat — never
     * throws, never touches the DB.
     */
    public function isActive(): bool
    {
        return is_file($this->flagPath());
    }

    /**
     * Turn maintenance mode ON. Records who enabled it and when so the
     * control panel and down-page can show context. Idempotent.
     */
    public function enable(?string $by = null, ?string $message = null): void
    {
        $payload = [
            'enabled_at'      => now()->toIso8601String(),
            'enabled_by'      => $by,
            'message'         => $message,
        ];

        @file_put_contents(
            $this->flagPath(),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Turn maintenance mode OFF. This is a state change (lifting the gate),
     * not a data delete — no record is destroyed. Idempotent.
     */
    public function disable(): void
    {
        if (is_file($this->flagPath())) {
            @unlink($this->flagPath());
        }
    }

    /**
     * Metadata stored when maintenance was enabled (enabled_at / enabled_by /
     * message). Empty array when OFF or the flag is unreadable/malformed —
     * never throws.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        if (!$this->isActive()) {
            return [];
        }

        $raw = @file_get_contents($this->flagPath());
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
