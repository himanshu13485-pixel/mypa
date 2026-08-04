<?php

namespace App\Services\Voice;

use App\Models\Habit;
use App\Models\User;

/**
 * Pattern rules for the Life modules: habits, goals and bills. Returns null
 * when the transcript is none of those, so task interpretation continues.
 *
 * As everywhere else in the assistant: nothing is created here — the frontend
 * shows the parsed habit/goal/bill for confirmation (and editing) first.
 */
class LifeCommandService
{
    public function __construct(protected VoiceDateParser $dates)
    {
    }

    /** @return array<string, mixed>|null */
    public function match(User $user, string $text, string $language, string $timezone): ?array
    {
        return $this->matchLogHabit($user, $text, $language)
            ?? $this->matchCreateHabit($text, $language)
            ?? $this->matchCreateGoal($text, $language, $timezone)
            ?? $this->matchCreateBill($text, $language, $timezone);
    }

    // --- Habits --------------------------------------------------------------

    protected function matchCreateHabit(string $text, string $language): ?array
    {
        $patterns = [
            // "add/start a (daily) habit to do yoga", "create habit: drink water"
            '/(?:add|create|start|new|make|build)\s+(?:a\s+|the\s+)?(?:daily\s+|weekly\s+|monthly\s+)?habit\s*(?:to|of|for|:)?\s*(.+)/u',
            // "yoga की आदत बनाओ", "आदत जोड़ो रोज़ पानी पीना"
            '/(.+?)\s*(?:की|का)\s*(?:आदत|हैबिट)\s*(?:बनाओ|बनाएं|जोड़ो|डालो|शुरू\s*करो)/u',
            '/(?:आदत|हैबिट)\s*(?:बनाओ|बनाएं|जोड़ो|डालो)\s*(.+)/u',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $text, $m)) {
                continue;
            }

            $name = trim($m[1]);

            $frequency = 'daily';
            if (preg_match('/(weekly|every week|once a week|हर\s*हफ़्ते|हर\s*हफ्ते|साप्ताहिक)/u', $text)) {
                $frequency = 'weekly';
            } elseif (preg_match('/(monthly|every month|once a month|हर\s*महीने|मासिक)/u', $text)) {
                $frequency = 'monthly';
            }

            [$reminderTime, $name] = $this->extractTime($name);
            $name = $this->tidy(preg_replace(
                '/\b(daily|every\s*day|weekly|every\s*week|monthly|every\s*month|रोज़|रोज|हर\s*दिन|हर\s*हफ़्ते|हर\s*महीने|प्रतिदिन)\b/u',
                ' ',
                $name,
            ));

            if ($name === '') {
                return null;
            }

            return [
                'intent' => 'create_habit',
                'language' => $language,
                'data' => [
                    'habit' => array_filter([
                        'name' => $name,
                        'frequency' => $frequency,
                        'target_per_period' => 1,
                        'reminder_time' => $reminderTime,
                    ], fn ($v) => $v !== null),
                ],
                'speech' => $language === 'hi'
                    ? "ठीक है — \"{$name}\" आदत बनाएं?"
                    : "Okay — create the habit \"{$name}\"?",
            ];
        }

        return null;
    }

    protected function matchLogHabit(User $user, string $text, string $language): ?array
    {
        $patterns = [
            // "mark the yoga habit as done", "yoga habit done", "log my yoga habit"
            '/(?:mark\s+)?(?:the\s+|my\s+)?(.+?)\s+habit\s+(?:as\s+)?(?:done|complete|completed|finished)/u',
            '/(?:log|did)\s+(?:the\s+|my\s+)?(.+?)\s+habit/u',
            // "yoga आदत पूरी", "yoga की आदत हो गई"
            '/(.+?)\s*(?:की\s*)?(?:आदत|हैबिट)\s*(?:पूरी|पूरा|हो\s*गई|कर\s*ली|कर\s*दी)/u',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $text, $m)) {
                continue;
            }

            $spoken = $this->tidy($m[1]);
            if ($spoken === '') {
                return null;
            }

            $habit = Habit::where('user_id', $user->id)
                ->whereNull('archived_at')
                ->get()
                ->first(fn (Habit $h) => str_contains(mb_strtolower($h->name), $spoken)
                    || str_contains($spoken, mb_strtolower($h->name)));

            return [
                'intent' => 'log_habit',
                'language' => $language,
                'data' => [
                    'heard_name' => $spoken,
                    'habit' => $habit ? ['uuid' => $habit->uuid, 'name' => $habit->name] : null,
                ],
                'speech' => $habit
                    ? ($language === 'hi' ? "\"{$habit->name}\" आज के लिए पूरी मार्क करें?" : "Mark \"{$habit->name}\" as done for today?")
                    : ($language === 'hi' ? "\"{$spoken}\" नाम की कोई आदत नहीं मिली।" : "I couldn't find a habit called \"{$spoken}\"."),
            ];
        }

        return null;
    }

    // --- Goals ---------------------------------------------------------------

    protected function matchCreateGoal(string $text, string $language, string $timezone): ?array
    {
        $patterns = [
            // "set a goal to save 50000 by december", "create goal: read 12 books"
            '/(?:add|create|set|new|make)\s+(?:a\s+|the\s+)?goal\s*(?:to|of|for|:)?\s*(.+)/u',
            // "50000 बचाने का लक्ष्य बनाओ", "लक्ष्य सेट करो ..."
            '/(.+?)\s*(?:का|की)\s*(?:लक्ष्य|गोल)\s*(?:बनाओ|बनाएं|सेट\s*करो|रखो)/u',
            '/(?:लक्ष्य|गोल)\s*(?:बनाओ|बनाएं|सेट\s*करो)\s*(.+)/u',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $text, $m)) {
                continue;
            }

            $parsed = $this->dates->parse($m[1], $timezone);
            $title = $this->tidy(preg_replace('/\s*\b(by|तक)\b\s*$/u', ' ', $parsed['remaining']));

            if ($title === '') {
                return null;
            }

            return [
                'intent' => 'create_goal',
                'language' => $language,
                'data' => [
                    'goal' => array_filter([
                        'title' => $title,
                        'target_date' => $parsed['due']?->toDateString(),
                    ], fn ($v) => $v !== null),
                ],
                'speech' => $language === 'hi'
                    ? "ठीक है — \"{$title}\" लक्ष्य बनाएं?"
                    : "Okay — create the goal \"{$title}\"?",
            ];
        }

        return null;
    }

    // --- Bills ---------------------------------------------------------------

    protected function matchCreateBill(string $text, string $language, string $timezone): ?array
    {
        $patterns = [
            // "add electricity bill of 2000 due tomorrow"
            '/(?:add|create|new)\s+(?:a\s+|the\s+|my\s+)?(.+?)\s+bill\b(.*)$/u',
            // "add a bill for electricity, 2000, due on the 10th"
            '/(?:add|create|new)\s+(?:a\s+|the\s+)?bill\s+(?:for|of|:)?\s*(.+)/u',
            // "बिजली का बिल जोड़ो 2000 का"
            '/(.+?)\s*(?:का|की)\s*बिल\s*(?:जोड़ो|डालो|बनाओ|ऐड\s*करो)(.*)$/u',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $text, $m)) {
                continue;
            }

            $namePart = trim($m[1]);
            $rest = trim($m[2] ?? '');
            $whole = trim($namePart . ' ' . $rest);

            // Amount: a number tied to money words, or a bare number in the rest.
            $amount = null;
            if (preg_match('/(?:of|for|amount|₹|rs\.?|rupees|रुपये|रु\.?)\s*(\d[\d,]{0,10}(?:\.\d{1,2})?)/u', $whole, $am)
                || preg_match('/(\d[\d,]{0,10}(?:\.\d{1,2})?)\s*(?:₹|rs\.?|rupees|रुपये|रु\.?|का)/u', $whole, $am)) {
                $amount = (float) str_replace(',', '', $am[1]);
            }

            $parsed = $this->dates->parse($rest !== '' ? $rest : $namePart, $timezone);

            $repeat = null;
            foreach ([
                'monthly' => '/(monthly|every month|हर\s*महीने|मासिक)/u',
                'weekly' => '/(weekly|every week|हर\s*हफ़्ते|साप्ताहिक)/u',
                'quarterly' => '/(quarterly|every quarter|तिमाही)/u',
                'yearly' => '/(yearly|annually|every year|हर\s*साल|सालाना|वार्षिक)/u',
            ] as $freq => $freqPattern) {
                if (preg_match($freqPattern, $whole)) {
                    $repeat = $freq;
                    break;
                }
            }

            // The name is the first capture minus money/frequency noise.
            $name = $this->tidy(preg_replace(
                [
                    '/(?:of|for|amount|₹|rs\.?|rupees|रुपये|रु\.?)\s*\d[\d,]{0,10}(?:\.\d{1,2})?/u',
                    '/\d[\d,]{0,10}(?:\.\d{1,2})?\s*(?:₹|rs\.?|rupees|रुपये|रु\.?|का)/u',
                    '/\b(monthly|weekly|quarterly|yearly|annually|every\s+(?:month|week|quarter|year)|हर\s*(?:महीने|हफ़्ते|साल)|मासिक|साप्ताहिक|सालाना|वार्षिक)\b/u',
                    '/\b(due|bill|बिल)\b/u',
                ],
                ' ',
                $this->dates->parse($namePart, $timezone)['remaining'],
            ));

            if ($name === '') {
                continue;
            }

            return [
                'intent' => 'create_bill',
                'language' => $language,
                'data' => [
                    'bill' => array_filter([
                        'name' => ucfirst($name),
                        'amount' => $amount,
                        'due_on' => ($parsed['due'] ?? now($timezone))->toDateString(),
                        'repeat_frequency' => $repeat,
                        'remind_days_before' => 1,
                    ], fn ($v) => $v !== null),
                ],
                'speech' => $language === 'hi'
                    ? 'ठीक है — बिल जोड़ें?'
                    : 'Okay — add this bill?',
            ];
        }

        return null;
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * Pull an "at 7 (am/pm)" / "सुबह 7 बजे" reminder time out of the text.
     *
     * @return array{0: ?string, 1: string} [H:i or null, remaining text]
     */
    protected function extractTime(string $text): array
    {
        if (preg_match('/\bat\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm|a\.m\.|p\.m\.)?/u', $text, $m)) {
            $hour = (int) $m[1];
            $minute = (int) ($m[2] ?? 0);
            $meridiem = str_replace('.', '', $m[3] ?? '');
            if ($meridiem === 'pm' && $hour < 12) {
                $hour += 12;
            }
            if ($meridiem === 'am' && $hour === 12) {
                $hour = 0;
            }

            return [sprintf('%02d:%02d', $hour, $minute), $this->tidy(str_replace($m[0], ' ', $text))];
        }

        if (preg_match('/(सुबह|शाम|रात|दोपहर)?\s*(\d{1,2})\s*बजे/u', $text, $m)) {
            $hour = (int) $m[2];
            if (in_array($m[1], ['शाम', 'रात'], true) && $hour < 12) {
                $hour += 12;
            }
            if ($m[1] === 'दोपहर' && $hour < 11) {
                $hour += 12;
            }

            return [sprintf('%02d:00', $hour), $this->tidy(str_replace($m[0], ' ', $text))];
        }

        return [null, $text];
    }

    protected function tidy(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        // PHP's trim() is byte-based and would corrupt Devanagari — strip
        // punctuation and trailing connector words with multibyte regexes.
        $text = preg_replace('/^[\s.,:\-—]+|[\s.,:\-—]+$/u', '', $text);
        $text = preg_replace('/\s*(का|की|के)$/u', '', $text);

        return trim($text);
    }
}
