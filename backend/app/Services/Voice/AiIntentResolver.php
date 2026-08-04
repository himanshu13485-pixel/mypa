<?php

namespace App\Services\Voice;

use Anthropic\Client;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fallback interpreter: when the pattern rules do not recognise a command,
 * Claude is asked to map the transcript onto the same intent vocabulary.
 *
 * It only ever classifies — the result still goes to the user for confirmation
 * exactly like a rule-matched command, so a misreading cannot act on its own.
 * Disabled unless an API key is configured, in which case the assistant simply
 * behaves as it did before.
 */
class AiIntentResolver
{
    /** The intents the model may return; anything else is discarded. */
    protected const INTENTS = [
        'call_person', 'message_person', 'start_meeting', 'share_screen',
        'navigate', 'create_task', 'complete_task', 'query_tasks',
    ];

    /**
     * Admin → Settings is the source of truth (toggle + key). The .env values
     * remain as a fallback key/model for installs that predate the UI.
     */
    public function isEnabled(): bool
    {
        return \App\Models\AppSetting::get('voice_ai_enabled') === '1'
            && $this->apiKey() !== ''
            && class_exists(Client::class);
    }

    protected function apiKey(): string
    {
        return \App\Models\AppSetting::get('voice_ai_key')
            ?: (string) config('mypa.voice.ai_key');
    }

    protected function model(): string
    {
        return \App\Models\AppSetting::get('voice_ai_model')
            ?: (string) config('mypa.voice.ai_model', 'claude-opus-5');
    }

    /**
     * @return array<string, mixed>|null null when unavailable or unusable, so
     *                                   the caller falls back to "unknown"
     */
    public function resolve(User $user, string $transcript, string $language): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $client = new Client(apiKey: $this->apiKey());

            $message = $client->messages->create(
                model: $this->model(),
                maxTokens: 1024,
                system: $this->systemPrompt($language),
                messages: [['role' => 'user', 'content' => $transcript]],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $this->schema()]],
            );

            $decoded = json_decode($this->textOf($message), true);
        } catch (Throwable $e) {
            // The assistant must keep working without the model.
            Log::warning('[voice] AI interpretation failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! is_array($decoded) || ! in_array($decoded['intent'] ?? '', self::INTENTS, true)) {
            return null;
        }

        return $decoded;
    }

    /** Pull the text out of whichever content block carries it. */
    protected function textOf(mixed $message): string
    {
        foreach ($message->content ?? [] as $block) {
            if (($block->type ?? null) === 'text' && isset($block->text)) {
                return $block->text;
            }
        }

        return '';
    }

    protected function systemPrompt(string $language): string
    {
        $today = now()->toDateString();

        return <<<PROMPT
        You classify short spoken commands for Netvork, a client-operations app.
        Return the single intent the speaker most likely meant, with its details.

        Intents:
        - call_person: start a call. person = who they named. call_type = "video"
          when video is mentioned or implied, otherwise "audio".
        - message_person: send a chat message. person = who, text = what to send
          (empty when they only asked to open the conversation).
        - start_meeting: create/start a meeting. people = anyone they want in it.
        - share_screen: start screen sharing. people = who to share with.
        - navigate: they just want to open a part of the app. page = one of
          dashboard, connections, messages, calls, meetings, screen, tasks,
          projects, notes, files, calendar, settings.
        - create_task / complete_task / query_tasks: anything about their own
          tasks and reminders.

        Rules:
        - person/people are names exactly as spoken; never invent one.
        - Today is {$today}.
        - The speaker may use English or Hindi; reply fields stay in {$language}.
        - Be literal. If the command is ambiguous between a call and a message,
          prefer what the verb says.
        PROMPT;
    }

    /** @return array<string, mixed> */
    protected function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['intent'],
            'properties' => [
                'intent' => ['type' => 'string', 'enum' => self::INTENTS],
                'person' => ['type' => 'string', 'description' => 'Name as spoken; empty when none.'],
                'people' => ['type' => 'array', 'items' => ['type' => 'string']],
                'call_type' => ['type' => 'string', 'enum' => ['audio', 'video']],
                'text' => ['type' => 'string', 'description' => 'Message body, when dictating one.'],
                'page' => ['type' => 'string'],
                'title' => ['type' => 'string', 'description' => 'Task title, for task intents.'],
                'speech' => ['type' => 'string', 'description' => 'One short sentence to read back to the speaker.'],
            ],
        ];
    }
}
