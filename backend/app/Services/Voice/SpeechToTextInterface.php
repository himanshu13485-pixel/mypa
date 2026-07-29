<?php

namespace App\Services\Voice;

use Illuminate\Http\UploadedFile;

/**
 * Server-side speech-to-text provider abstraction. The default deployment uses
 * the browser's Web Speech API (no server STT), but providers such as OpenAI
 * Whisper, Google Speech-to-Text, or Azure Speech can be plugged in by
 * implementing this interface and binding it in a service provider.
 */
interface SpeechToTextInterface
{
    /** Whether this provider can actually transcribe audio. */
    public function isConfigured(): bool;

    /** @return array{transcript: string, language: string, confidence: ?float} */
    public function transcribe(UploadedFile $audio, string $language): array;
}
