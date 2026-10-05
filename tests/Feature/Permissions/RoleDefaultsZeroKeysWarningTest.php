<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use App\Models\Role;
use App\Models\RolePermission;
use App\Services\Permissions\RoleDefaultsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * `corex:sync-permissions --merge-defaults` fired a false "role_defaults
 * entry present but resolved to ZERO keys" WARNING for the 'assistant' role
 * in every agency, every deploy (2026-10-05) — ~100+ WARNING lines on QA1
 * alone. Root cause: the warning was keyed off the RESOLVED RESULT
 * (empty($expectedKeys)), which is indistinguishable between two different
 * states — a malformed/unrecognised role_defaults shape (the thing this
 * warning exists to catch) and a VALID, deliberately empty closed include
 * (AT-267: 'assistant' => ['include' => []], an identity-label role meant
 * to carry zero config-default grants). Fix: key the warning off the SHAPE
 * (RoleDefaultsResolver::isRecognizedShape()), not the resolved result.
 */
final class RoleDefaultsZeroKeysWarningTest extends TestCase
{
    use RefreshDatabase;

    private function runSync(): array
    {
        $exitCode = Artisan::call('corex:sync-permissions', ['--merge-defaults' => true]);

        return [$exitCode, Artisan::output()];
    }

    public function test_isRecognizedShape_accepts_the_three_documented_shapes(): void
    {
        $this->assertTrue(RoleDefaultsResolver::isRecognizedShape('*'));
        $this->assertTrue(RoleDefaultsResolver::isRecognizedShape(['exclude' => ['x']]));
        $this->assertTrue(RoleDefaultsResolver::isRecognizedShape(['include' => []]));
        $this->assertTrue(RoleDefaultsResolver::isRecognizedShape(['include' => ['x']]));
    }

    public function test_isRecognizedShape_rejects_malformed_or_unknown_shapes(): void
    {
        $this->assertFalse(RoleDefaultsResolver::isRecognizedShape(['inlcude' => ['x']])); // typo
        $this->assertFalse(RoleDefaultsResolver::isRecognizedShape(null));
        $this->assertFalse(RoleDefaultsResolver::isRecognizedShape('something-else'));
        $this->assertFalse(RoleDefaultsResolver::isRecognizedShape([]));
    }

    public function test_the_real_assistant_config_entry_is_a_deliberately_empty_closed_include(): void
    {
        $def = config('corex-permissions.role_defaults.assistant');

        $this->assertTrue(RoleDefaultsResolver::isRecognizedShape($def), 'assistant must be a recognised shape, not malformed.');
        $this->assertSame([], RoleDefaultsResolver::keysForDef($def, ['any.key']), 'assistant must resolve to zero keys by design (AT-267).');
    }

    public function test_a_deliberately_empty_closed_include_role_produces_no_warning(): void
    {
        Config::set('corex-permissions.role_defaults.zero_key_role_fixture', ['include' => []]);
        Role::forceCreate(['name' => 'zero_key_role_fixture', 'label' => 'Zero Key Fixture', 'agency_id' => null, 'is_owner' => false]);

        [$exitCode, $output] = $this->runSync();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('WARNING', $output, 'A legitimately-empty closed include must never produce the malformed-shape WARNING.');
        $this->assertStringContainsString('zero_key_role_fixture', $output);
        $this->assertSame(0, RolePermission::where('role', 'zero_key_role_fixture')->count(), 'No grants should be written for a role whose config is deliberately empty.');
    }

    public function test_a_malformed_role_defaults_shape_still_produces_the_warning(): void
    {
        Config::set('corex-permissions.role_defaults.typo_role_fixture', ['inlcude' => ['some.key']]); // deliberate typo
        Role::forceCreate(['name' => 'typo_role_fixture', 'label' => 'Typo Fixture', 'agency_id' => null, 'is_owner' => false]);

        [$exitCode, $output] = $this->runSync();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('WARNING', $output, 'A genuinely malformed role_defaults shape must still surface the WARNING — this is the case the check exists to catch.');
        $this->assertStringContainsString('typo_role_fixture', $output);
    }

    public function test_running_against_the_real_config_produces_no_assistant_warning(): void
    {
        foreach (['super_admin', 'admin', 'branch_manager', 'agent', 'viewer', 'office_admin', 'assistant'] as $name) {
            Role::forceCreate(['name' => $name, 'label' => ucfirst($name), 'agency_id' => null, 'is_owner' => $name === 'super_admin']);
        }

        [$exitCode, $output] = $this->runSync();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('WARNING', $output, 'Real config, real roles — no role should produce the malformed-shape WARNING.');
    }
}
