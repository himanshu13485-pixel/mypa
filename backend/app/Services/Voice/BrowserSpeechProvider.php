<?php

namespace App\Services\Voice;

use Illuminate\Http\UploadedFile;

/**
 * Null provider: speech recognition happens in the browser (Web Speech API),
 * so the server never receives audio. Swap the container binding for a real
 * provider (Whisper, Google, Azure) to enable server-side transcription.
 */
class BrowserSpeechProvider implements SpeechToTextInterface
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function transcribe(UploadedFile $audio, string $language): array
    {
        throw new \RuntimeException('No server-side speech-to-text provider is configured.');
    }
}
