<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Services\Syndication\Property24\Property24ApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * One-off importer: brings the two Property24 vetting agents
 * (Jon Snow #77836, Pauly Shore #77837) into CoreX as User records
 * under Home Finders Coastal, then updates their P24 sourceReference
 * to CoreX-Agent-{user_id} so the admin users page matches them.
 *
 * Idempotent. Safe to re-run.
 */
class P24ImportVettingAgents extends Command
{
    protected $signature = 'p24:import-vetting-agents {--branch= : Branch id to assign (defaults to first HFC branch)}';
    protected $description = 'Import the P24 vetting agents (Jon Snow, Pauly Shore) into CoreX and relink their sourceReference.';

    private const AGENTS = [
        [
            'p24_id'    => 77836,
            'firstname' => 'Jon',
            'lastname'  => 'Snow',
            'email'     => 'jon.snow+vetting@hfcoastal.co.za',
            'cell'      => '0825550101',
        ],
        [
            'p24_id'    => 77837,
            'firstname' => 'Pauly',
            'lastname'  => 'Shore',
            'email'     => 'pauly.shore+vetting@hfcoastal.co.za',
            'cell'      => '0825550202',
        ],
    ];

    public function handle(Property24ApiClient $client): int
    {
        $agencyId = (int) (Agency::query()->value('id') ?? 0);
        if (!$agencyId) {
            $this->error('No Agency found. Aborting.');
            return self::FAILURE;
        }

        $branchId = (int) $this->option('branch');
        if (!$branchId) {
            $branchId = (int) Branch::when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
                ->orderBy('id')->value('id');
        }

        $this->info("Agency: {$agencyId} | Branch: " . ($branchId ?: 'none'));

        foreach (self::AGENTS as $a) {
            $this->line('');
            $this->info("→ {$a['firstname']} {$a['lastname']} (P24 #{$a['p24_id']})");

            $user = User::withTrashed()->where('email', $a['email'])->first();

            if ($user && $user->trashed()) {
                $user->restore();
                $this->line("  restored soft-deleted user #{$user->id}");
            }

            if (!$user) {
                $user = User::create([
                    'name'       => trim($a['firstname'] . ' ' . $a['lastname']),
                    'email'      => $a['email'],
                    'password'   => \App\Models\User::pendingInvitePassword(),
                    'role'       => 'agent',
                    'agency_id'  => $agencyId,
                    'branch_id'  => $branchId ?: null,
                    'is_active'  => true,
                    'is_admin'   => 0,
                    'cell'       => $a['cell'],
                ]);
                // email_verified_at isn't in $fillable — forceFill to bypass
                $user->forceFill(['email_verified_at' => now()])->save();
                $this->line("  created user #{$user->id}");
            } else {
                $this->line("  user already exists #{$user->id}");
                if (!$user->email_verified_at) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                }
            }

            // Pull P24 agent and update its sourceReference → CoreX-Agent-{userId}
            $fetch = $client->getAgent($a['p24_id']);
            if (!($fetch['success'] ?? false)) {
                $this->warn("  could not fetch P24 agent #{$a['p24_id']}: " . ($fetch['message'] ?? '?'));
                continue;
            }
            $p24Agent = $fetch['data'] ?? [];
            $desiredRef = 'CoreX-Agent-' . $user->id;

            if (($p24Agent['sourceReference'] ?? '') !== $desiredRef) {
                $p24Agent['sourceReference'] = $desiredRef;
                $upd = $client->updateAgent($p24Agent);
                if ($upd['success'] ?? false) {
                    $this->line("  P24 sourceReference updated → {$desiredRef}");
                } else {
                    $this->warn('  P24 update failed: ' . ($upd['message'] ?? '?'));
                }
            } else {
                $this->line('  P24 sourceReference already correct');
            }

            // Pull profile picture from P24 into CoreX if the user doesn't have one
            if (empty($user->agent_photo_path)) {
                $picUrl = $p24Agent['profilePicture']['url']
                    ?? $p24Agent['profilePicture']['Url']
                    ?? null;
                if ($picUrl) {
                    try {
                        $resp = Http::withoutVerifying()->timeout(15)->get($picUrl);
                        if ($resp->successful() && strlen($resp->body()) > 100) {
                            $ext = str_contains($resp->header('Content-Type') ?? '', 'png') ? 'png' : 'jpg';
                            $path = "agents/{$user->id}/photo.{$ext}";
                            Storage::disk('public')->put($path, $resp->body());
                            $user->update(['agent_photo_path' => $path]);
                            $this->line("  photo imported from P24 → {$path}");
                        }
                    } catch (\Throwable $e) {
                        $this->warn('  photo import failed: ' . $e->getMessage());
                    }
                }
            }
        }

        Cache::forget('p24:agent-map:by-source-ref');
        $this->line('');
        $this->info('Done. P24 agent-map cache cleared — reload /admin/users.');

        return self::SUCCESS;
    }
}
