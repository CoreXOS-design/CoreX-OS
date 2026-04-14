<?php

namespace App\Jobs;

use App\Models\P24ImportRow;
use App\Models\P24ImportRun;
use App\Models\User;
use App\Services\Importer\P24ImageDownloader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Processes an agent-kind P24 import run:
 *  - Creates inactive user rows (or updates) per confirmed row
 *  - Downloads profile photos
 * Listings runs land rows straight to the review queue (status=pending)
 * and are confirmed one-by-one on the review screen.
 */
class ProcessImporterRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $runId) {}

    public function handle(P24ImageDownloader $downloader): void
    {
        $run = P24ImportRun::with('rows')->find($this->runId);
        if (!$run) return;

        $run->update(['status' => 'importing']);

        try {
            if ($run->kind === 'agents') {
                $this->processAgents($run, $downloader);
            }
            // Listings runs: rows are already in 'pending' review queue, nothing else to write here.
            $run->update([
                'status'       => 'completed',
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('ProcessImporterRunJob failed', ['run_id' => $run->id, 'error' => $e->getMessage()]);
            $run->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    private function processAgents(P24ImportRun $run, P24ImageDownloader $downloader): void
    {
        $rows = $run->rows()->where('row_type', 'agent')->where('status', '!=', 'excluded')->get();

        foreach ($rows as $row) {
            if (!empty($row->errors_json)) continue;
            $mapped = $row->mapped_json ?? [];

            DB::transaction(function () use ($row, $mapped, $run, $downloader) {
                $email = strtolower(trim($mapped['email'] ?? ''));
                if ($email === '') return;

                $user = User::withTrashed()->where('email', $email)->first();
                if ($user && $user->agency_id && $user->agency_id !== (int)$run->agency_id) {
                    // Collision across agencies — skip per spec §13 Q1
                    $row->update([
                        'status'      => 'error',
                        'errors_json' => ['Email belongs to another agency — skipped'],
                    ]);
                    return;
                }

                $attrs = [
                    'name'             => $mapped['name'] ?? $email,
                    'email'            => $email,
                    'phone'            => $mapped['phone'] ?? null,
                    'cell'             => $mapped['cell'] ?? null,
                    'role'             => 'agent',
                    'is_active'        => false,
                    'agency_id'        => $run->agency_id,
                    'p24_agent_id'     => $mapped['p24_agent_id'] ?? null,
                    'source_reference' => $mapped['source_reference'] ?? null,
                    'designation'      => $mapped['designation'] ?? null,
                ];

                if (!$user) {
                    $attrs['password'] = Hash::make(Str::random(48));
                    $user = User::create($attrs);
                    $row->action = 'create';
                } else {
                    $user->fill($attrs)->save();
                    $row->action = 'update';
                }

                // Profile photo
                $photoUrl = $mapped['profile_photo_url'] ?? null;
                if ($photoUrl) {
                    $dest = "agents/{$user->id}.jpg";
                    $stored = $downloader->download($photoUrl, $dest);
                    if ($stored) {
                        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'profile_photo_path')) {
                            $user->profile_photo_path = $stored;
                            $user->save();
                        } elseif (\Illuminate\Support\Facades\Schema::hasColumn('users', 'agent_photo_path')) {
                            $user->agent_photo_path = $stored;
                            $user->save();
                        }
                    }
                }

                $row->update([
                    'status'       => 'confirmed',
                    'target_id'    => $user->id,
                    'confirmed_at' => now(),
                ]);
            });
        }
    }
}
