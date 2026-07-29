<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Voice\SpeechToTextInterface;
use App\Services\Voice\VoiceCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoiceController extends Controller
{
    /** Interpret a transcript into a reviewable command (nothing is saved here). */
    public function interpret(Request $request, VoiceCommandService $service): JsonResponse
    {
        $data = $request->validate([
            'transcript' => ['required', 'string', 'max:1000'],
            'language' => ['sometimes', 'in:en,hi'],
        ]);

        $result = $service->interpret(
            $request->user(),
            $data['transcript'],
            $data['language'] ?? 'en',
        );

        return response()->json(['data' => $result + ['transcript' => $data['transcript']]]);
    }

    /**
     * Server-side transcription. Returns 501 until a provider (Whisper, Google,
     * Azure, …) is bound to SpeechToTextInterface — the browser Web Speech API
     * is the default recognizer.
     */
    public function transcribe(Request $request, SpeechToTextInterface $stt): JsonResponse
    {
        if (! $stt->isConfigured()) {
            return response()->json([
                'message' => 'Server-side transcription is not configured. Use the browser speech recognition.',
            ], 501);
        }

        $request->validate([
            'audio' => ['required', 'file', 'max:10240'],
            'language' => ['sometimes', 'in:en,hi'],
        ]);

        return response()->json([
            'data' => $stt->transcribe($request->file('audio'), $request->input('language', 'en')),
        ]);
    }
}
