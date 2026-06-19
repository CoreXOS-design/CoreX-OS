<?php

namespace App\Services\AI;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Wraps the self-hosted faster-whisper endpoint on /opt/hf-ai (port 3100).
 * Audio stays on-shore for POPIA compliance — never sent to a third party.
 */
class SpeechToTextService
{
    private string $baseUrl;
    private int $timeout;
    private int $maxSeconds;
    /** @var array<string,string> Sensitivity params forwarded to the /transcribe endpoint. */
    private array $tuning;

    public function __construct()
    {
        $this->baseUrl    = rtrim((string) (config('services.hf_ai.base_url') ?? env('HF_AI_BASE_URL', 'http://127.0.0.1:3100')), '/');
        $this->timeout    = (int) (config('services.hf_ai.transcribe_timeout') ?? env('HF_AI_TRANSCRIBE_TIMEOUT', 15));
        $this->maxSeconds = (int) (config('services.hf_ai.voice_max_seconds') ?? env('AI_VOICE_MAX_SECONDS', 30));

        // Sent as multipart form fields alongside the audio. Booleans are sent
        // as '0'/'1' strings; the Python endpoint coerces them back. A blank
        // field is omitted so the endpoint keeps its own default.
        $this->tuning = array_filter([
            'vad_filter'          => filter_var(config('services.hf_ai.vad_filter'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'no_speech_threshold' => $this->stringOrNull(config('services.hf_ai.no_speech_threshold')),
            'log_prob_threshold'  => $this->stringOrNull(config('services.hf_ai.log_prob_threshold')),
            'initial_prompt'      => $this->stringOrNull(config('services.hf_ai.initial_prompt')),
        ], fn ($v) => $v !== null);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * Transcribe an uploaded audio file.
     *
     * @return array{text:string, language:string, duration_seconds:float, elapsed_ms:int}
     * @throws \RuntimeException on transport, oversize, or transcription failure.
     */
    public function transcribe(UploadedFile $audio): array
    {
        $path = $audio->getRealPath();
        if (!$path || !is_readable($path)) {
            throw new \RuntimeException('Audio upload is unreadable.');
        }

        $response = Http::timeout($this->timeout)
            ->attach('audio', file_get_contents($path), $audio->getClientOriginalName() ?: 'audio.webm', [
                'Content-Type' => $audio->getMimeType() ?: 'application/octet-stream',
            ])
            ->post($this->baseUrl . '/transcribe', $this->tuning);

        if (!$response->successful()) {
            Log::warning('SpeechToTextService: transcribe failed', [
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 500),
            ]);
            throw new \RuntimeException('Transcription service returned HTTP ' . $response->status());
        }

        $data = $response->json();
        if (!is_array($data) || !isset($data['text'])) {
            throw new \RuntimeException('Transcription service returned malformed response.');
        }

        if (isset($data['duration_seconds']) && (float) $data['duration_seconds'] > $this->maxSeconds) {
            throw new \RuntimeException(sprintf('Audio clip exceeds %ds limit.', $this->maxSeconds));
        }

        return [
            'text'             => (string) ($data['text'] ?? ''),
            'language'         => (string) ($data['language'] ?? 'en'),
            'duration_seconds' => (float)  ($data['duration_seconds'] ?? 0),
            'elapsed_ms'       => (int)    ($data['elapsed_ms'] ?? 0),
        ];
    }
}
