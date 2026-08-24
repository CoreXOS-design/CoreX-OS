<?php

namespace App\Services\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * AT-163 Stage 2 — on-box voice-note transcription (§2.1).
 *
 * A thin PHP seam over the whisper.cpp worker CLI (mirrors WahaMediaClient):
 * shells out with a hard timeout + a nice'd CPU cap, parses whisper's native
 * JSON (result.language + transcription[].text), and writes the transcript onto
 * the message row with AT-148-style states (pending → processing → done/failed,
 * retry-capped).
 *
 * POPIA / consent (§4): a transcript is body content, produced ONLY for a note
 * whose media was captured WITH consent. An embargoed / consent_pending /
 * opted-out note has NO stored audio (the ingestor withholds media bytes) and a
 * withheld body_status, so it is excluded by transcribableAttachment() — no
 * backdoor. The transcript inherits the message row's AT-118/132 access gate for
 * viewing AND search.
 */
class TranscriptionService
{
    /** body_status values that mean the body/media is withheld — never transcribe. */
    private const WITHHELD = ['embargoed', 'consent_pending', 'embargo_purged'];

    /**
     * Transcribe a voice-note message end to end. Idempotent-ish: sets processing,
     * runs, writes done/failed. Returns a small status array.
     *
     * @return array{status:string,lang?:string,error?:string}
     */
    public function transcribe(Communication $comm, ?string $modelOverride = null, ?string $languageOverride = null): array
    {
        if (! (bool) config('communications.transcription.enabled', true)) {
            return ['status' => 'disabled'];
        }

        $att = $this->transcribableAttachment($comm);
        if (! $att) {
            // Nothing consent-eligible to transcribe — clear any stale state.
            $comm->forceFill(['transcript_status' => null])->save();
            return ['status' => 'skipped'];
        }

        $disk = config('communications.disk', 'local');
        if (! Storage::disk($disk)->exists($att->storage_path)) {
            return $this->markFailed($comm, 'audio_missing');
        }
        // AT-173 — media may be encrypted at rest; the whisper worker reads a raw
        // path, so decrypt to a short-lived temp file (removed in finally). Legacy
        // plaintext passes through the seam unchanged.
        $plainBytes = app(\App\Services\Communications\CommunicationStorageService::class)->get($att->storage_path);
        if ($plainBytes === null) {
            return $this->markFailed($comm, 'audio_missing');
        }
        $ext = pathinfo($att->storage_path, PATHINFO_EXTENSION) ?: 'ogg';
        $tmpBase = tempnam(sys_get_temp_dir(), 'cxe_tx_');
        $audioPath = $tmpBase . '.' . $ext;
        @rename($tmpBase, $audioPath);
        file_put_contents($audioPath, $plainBytes);

        $model   = $modelOverride ?: (string) config('communications.transcription.model', 'medium');
        $threads = (string) (int) config('communications.transcription.threads', 8);
        $binary  = (string) config('communications.transcription.binary', '/opt/corex-transcribe/transcribe.sh');
        $timeout = (int) config('communications.transcription.timeout_seconds', 900);
        // AT-194 — per-agency whisper language hint (null/unknown => 'auto'). Fed to the
        // worker as `-l <lang>`; 'auto' preserves the historical per-note auto-detect.
        $language = $languageOverride ?: $this->resolveLanguage($comm);

        $comm->forceFill(['transcript_status' => 'processing'])->save();

        try {
            $process = new Process([$binary, $audioPath, $model, $threads, $language]);
            $process->setTimeout($timeout);
            $process->run();

            if (! $process->isSuccessful()) {
                return $this->markFailed($comm, 'worker_exit_' . $process->getExitCode());
            }

            $parsed = $this->parse($process->getOutput());
            if ($parsed === null) {
                return $this->markFailed($comm, 'unparseable_output');
            }

            [$text, $lang] = $parsed;
            $text = trim($text);

            $comm->forceFill([
                'transcript_text'       => $text !== '' ? $text : null,
                'transcript_preview'    => $text !== '' ? Str::limit($text, 255, '') : null,
                'transcript_status'     => 'done',
                'transcript_lang'       => $lang ?: null,
                'transcript_model'      => $model,
                'transcript_error'      => null,
                'transcript_at'         => now(),
            ])->save();

            return ['status' => 'done', 'lang' => $lang];
        } catch (ProcessTimedOutException $e) {
            return $this->markFailed($comm, 'timeout');
        } catch (\Throwable $e) {
            Log::warning('AT-163 transcription error', ['communication_id' => $comm->id, 'error' => $e->getMessage()]);
            return $this->markFailed($comm, Str::limit($e->getMessage(), 200, ''));
        } finally {
            // AT-173 — never leave the decrypted temp audio on disk.
            if (isset($audioPath) && is_file($audioPath)) {
                @unlink($audioPath);
            }
        }
    }

    /**
     * AT-194 — the whisper language hint for this note, from its agency setting.
     * Unknown agency / null value => 'auto' (historical per-note auto-detect).
     */
    private function resolveLanguage(Communication $comm): string
    {
        $agency = $comm->agency_id ? \App\Models\Agency::find($comm->agency_id) : null;

        return $agency ? $agency->transcriptionLanguage() : 'auto';
    }

    /**
     * The consent-eligible audio attachment to transcribe, or null. A withheld
     * body (embargoed/pending/purged) or an unstored/absent audio never qualifies.
     */
    public function transcribableAttachment(Communication $comm): ?CommunicationAttachment
    {
        if ($comm->channel !== Communication::CHANNEL_WHATSAPP) {
            return null;
        }
        if ($comm->purged_at !== null || in_array($comm->body_status, self::WITHHELD, true)) {
            return null;
        }

        return $comm->attachments
            ->first(fn ($a) => is_string($a->mime)
                && str_starts_with($a->mime, 'audio')
                && $a->media_status === CommunicationAttachment::MEDIA_STORED
                && filled($a->storage_path));
    }

    /** True when the box is too loaded to accept an interactive "Transcribe now". */
    public function isBoxBusy(): bool
    {
        $ceiling = (float) config('communications.transcription.load_avg_ceiling', 12.0);
        $load = function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? 0.0) : 0.0;

        return $load > $ceiling;
    }

    /**
     * Parse whisper.cpp's native JSON → [text, language]. Concatenates all
     * transcription segments (handles multi-segment / code-mixed notes).
     *
     * @return array{0:string,1:?string}|null
     */
    private function parse(string $json): ?array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }
        if (isset($data['error'])) {
            return null;
        }
        $lang = $data['result']['language'] ?? null;
        $segments = $data['transcription'] ?? [];
        $text = '';
        foreach ((array) $segments as $seg) {
            $text .= ($seg['text'] ?? '');
        }

        return [$text, is_string($lang) ? $lang : null];
    }

    private function markFailed(Communication $comm, string $error): array
    {
        $max = max(1, (int) config('communications.transcription.max_retries', 3));
        $retries = (int) $comm->transcript_retry_count + 1;

        $comm->forceFill([
            'transcript_retry_count' => $retries,
            'transcript_status'      => $retries >= $max ? 'failed' : 'pending',
            'transcript_error'       => $error,
        ])->save();

        return ['status' => $retries >= $max ? 'failed' : 'pending', 'error' => $error];
    }
}
