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

        $system = $this->house($input);

        $prompt = match ($mode) {
            'reply' => "Write a reply to this email.\n\nFrom: {$input['from']}\nSubject: {$input['subject']}\n\n"
                . "--- their message ---\n{$input['original']}\n--- end ---\n\n"
                . 'What the reply should say: ' . ($input['instruction'] ?: 'answer them properly') . "\n"
                . 'Tone: ' . ($input['tone'] ?? 'professional') . "\n\n"
                . "Answer what they actually wrote. Take up their specific points, questions, numbers and dates by name. "
                . "If they asked something you have not been told the answer to, say plainly that you are finding out and when "
                . "you will come back, rather than answering vaguely. If their mail needs nothing more than an acknowledgement, "
                . "two sentences is the whole reply - do not pad it out.\n"
                . 'Set subject to null.',
            'improve' => (self::ACTIONS[$input['action'] ?? ''] ?? 'Improve it.')
                . "\n\nKeep the writer's own voice and every fact exactly as it is. Return the whole text, not a comment on it.\n\n"
                . "The text:\n{$input['text']}\n\nSet subject to null.",
            default => 'Write a new email. What it should say: ' . $input['instruction'] . "\n"
                . 'Tone: ' . ($input['tone'] ?? 'professional')
                . (! empty($input['to']) ? "\nIt is to: {$input['to']}" : '')
                . "\n\nOpen by getting to the point, not by introducing yourself or hoping they are well. "
                . "Say what you want to happen next and by when, if the instruction implies one.\n"
                . 'Give it a short, specific subject - what the mail is about, not a greeting.',
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

    /**
     * How the house writes.
     *
     * The first version of this said little more than "write an email", and
     * got back what that asks for: "We have received your email and noted the
     * information provided. We will proceed accordingly." Three sentences
     * that answer nothing, which somebody then has to rewrite - so the
     * assistant costs more time than it saves.
     *
     * So the rules are the ones a good correspondent already follows. Answer
     * the actual thing. Be specific. Use the sender's own words for the
     * matter at hand. Say what happens next and who does it. Say nothing you
     * were not told, and leave nothing for the reader to fill in.
     */
    private function house(array $input): string
    {
        $who = trim((string) ($input['writer'] ?? ''));
        $firm = trim((string) ($input['company'] ?? ''));

        $lines = [
            'You draft business email for a working professional, and your drafts are read and sent by them.',
            $who !== '' ? "You are writing as {$who}" . ($firm !== '' ? " at {$firm}." : '.') : '',
            'Write in the language the other person used, or the language of the instructions if there is no other mail.',
            '',
            'HOW TO WRITE',
            '- Say the thing. The first sentence carries the point of the mail, not a greeting about the weather or the hope that they are well.',
            '- Be specific. Name the invoice, the date, the amount, the person, the shipment - whatever the matter actually is. A reply that would fit any mail at all is a failed reply.',
            '- One idea to a paragraph, two or three sentences each. Most business mail is under 120 words.',
            '- Plain, direct English. No "please be advised", "kindly do the needful", "as per our discussion", "I hope this email finds you well", "at your earliest convenience", "we would like to inform you".',
            '- End with what happens next: who does what, by when. If nothing needs doing, end without a task.',
            '- A greeting line ("Dear Harsh," or "Hi Priya,") and a closing line ("Regards," or "Thanks,") - nothing more ornate.',
            '',
            'WHAT NOT TO DO',
            '- Never write a placeholder, a bracket to fill in, or a note to the person using you. Not "[insert date]", not "(add greetings)", not "XXX". If a fact is missing, write the sentence in a way that does not need it, or ask for it in the mail.',
            '- Never invent a fact, a price, a date, a name or a commitment you were not given.',
            '- Never restate their whole message back to them before answering it.',
            '- Never write a sign-off block with a name, title, phone or company after the closing line; the mail program adds the signature.',
            '- Never explain what you are about to write, apologise, or comment on the task. Return the mail and nothing else.',
            '',
            'Return JSON only: {"subject": string or null, "body": string}. The body is plain text, blank line between paragraphs, no markdown and no HTML.',
        ];

        return implode("\n", array_filter($lines, fn ($line) => $line !== ''));
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
