<?php

namespace App\Services\Mail;

use Anthropic\Client as AnthropicClient;
use App\Models\AppSetting;
use App\Models\Crm\Organization;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Writing help for mail: draft from a line of instructions, answer a mail,
 * or rework something already written.
 *
 * The company chooses the model - Claude or ChatGPT - with its own key, in
 * Mail settings. Without one it borrows the platform's Claude key, when the
 * platform has one. Either way the mail and the instructions go to that
 * provider and nowhere else, and nothing is sent until the person reads the
 * draft and presses Send themselves.
 */
class MailAssistant
{
    /** Claude's default for drafting mail: a sensible balance of speed, cost and quality. */
    public const DEFAULT_CLAUDE_MODEL = 'claude-sonnet-5';

    public const ACTIONS = [
        'formal' => 'Rewrite it in a more formal, professional tone.',
        'friendly' => 'Rewrite it in a warmer, friendlier tone.',
        'shorter' => 'Make it shorter and more direct, keeping every fact.',
        'longer' => 'Expand it a little with helpful detail, without inventing facts.',
        'grammar' => 'Correct spelling, grammar and punctuation only; change nothing else.',
        'clearer' => 'Make it clearer and easier to read.',
    ];

    /** @return array{provider: string, model: string, key: string}|null */
    public function config(Organization $org): ?array
    {
        $own = (array) data_get($org->settings, 'mails_ai', []);

        if (! empty($own['enabled']) && ! empty($own['api_key'])) {
            try {
                $key = Crypt::decryptString($own['api_key']);
            } catch (Throwable) {
                $key = '';
            }
            $provider = ($own['provider'] ?? 'anthropic') === 'openai' ? 'openai' : 'anthropic';
            $model = trim((string) ($own['model'] ?? ''));
            if ($model === '' && $provider === 'anthropic') {
                $model = self::DEFAULT_CLAUDE_MODEL;
            }
            if ($key !== '' && $model !== '') {
                return ['provider' => $provider, 'model' => $model, 'key' => $key];
            }
        }

        // The platform's own Claude key, as the voice assistant uses it.
        $platformKey = (string) (AppSetting::get('voice_ai_key') ?: config('mypa.voice.ai_key'));
        if ($platformKey !== '' && class_exists(AnthropicClient::class)) {
            return [
                'provider' => 'anthropic',
                'model' => (string) (AppSetting::get('voice_ai_model') ?: self::DEFAULT_CLAUDE_MODEL),
                'key' => $platformKey,
            ];
        }

        return null;
    }

    public function available(Organization $org): bool
    {
        return $this->config($org) !== null;
    }

    /**
     * @param  string  $mode  compose | reply | improve
     * @return array{subject: ?string, body: string}
     */
    public function write(Organization $org, string $mode, array $input): array
    {
        $config = $this->config($org);
        if (! $config) {
            throw new RuntimeException('No AI provider is set up. An admin can add one under Mails > Settings > AI assistant.');
        }

        $system = 'You write emails for a person at a company. Write in the language the instructions are written in. '
            . 'Return JSON only: {"subject": string or null, "body": string}. The body is plain text with blank lines between '
            . 'paragraphs - no markdown, no HTML, no placeholders in square brackets unless information is genuinely missing. '
            . 'Do not add a signature; the mail program adds one. Never invent facts, prices, dates or commitments.';

        $prompt = match ($mode) {
            'reply' => "Write a reply to this email.\n\nFrom: {$input['from']}\nSubject: {$input['subject']}\n\n{$input['original']}\n\n"
                . 'What the reply should say: ' . ($input['instruction'] ?: 'a helpful, polite reply') . "\nTone: " . ($input['tone'] ?? 'professional')
                . "\nSet subject to null.",
            'improve' => (self::ACTIONS[$input['action'] ?? ''] ?? 'Improve it.') . "\n\nThe text:\n{$input['text']}\n\nSet subject to null.",
            default => 'Write a new email. What it should say: ' . $input['instruction']
                . "\nTone: " . ($input['tone'] ?? 'professional')
                . (! empty($input['to']) ? "\nIt is to: {$input['to']}" : '')
                . "\nGive it a short, specific subject.",
        };

        $raw = $config['provider'] === 'openai'
            ? $this->openai($config, $system, $prompt)
            : $this->anthropic($config, $system, $prompt);

        $json = json_decode(trim((string) preg_replace('/^```(json)?|```$/m', '', $raw)), true);
        if (! is_array($json) || ! isset($json['body'])) {
            // Not JSON after all: the words are still the draft.
            return ['subject' => null, 'body' => trim($raw)];
        }

        return ['subject' => $json['subject'] ?? null, 'body' => trim((string) $json['body'])];
    }

    private function anthropic(array $config, string $system, string $prompt): string
    {
        $client = new AnthropicClient(apiKey: $config['key']);
        $message = $client->messages->create(
            model: $config['model'],
            maxTokens: 2048,
            system: $system,
            messages: [['role' => 'user', 'content' => $prompt]],
        );

        foreach ($message->content ?? [] as $block) {
            if (($block->type ?? null) === 'text' && isset($block->text)) {
                return $block->text;
            }
        }

        return '';
    }

    private function openai(array $config, string $system, string $prompt): string
    {
        $response = Http::withToken($config['key'])->timeout(60)->acceptJson()
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $config['model'],
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'response_format' => ['type' => 'json_object'],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('The AI provider refused: ' . ($response->json('error.message') ?? $response->status()));
        }

        return (string) $response->json('choices.0.message.content', '');
    }
}
