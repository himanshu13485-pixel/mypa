<?php

namespace App\Services\Voice;

use App\Models\Category;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Turns a spoken transcript (English or Hindi) into a structured, reviewable
 * command. Never executes anything destructive itself — the frontend shows the
 * interpretation for confirmation first.
 */
class VoiceCommandService
{
    public function __construct(protected VoiceDateParser $dates)
    {
    }

    public function interpret(User $user, string $transcript, string $language = 'en'): array
    {
        $tz = $user->profile?->timezone ?? config('app.timezone');
        $text = trim(preg_replace('/\s+/', ' ', mb_strtolower($transcript)));

        if ($text === '') {
            return $this->unknown($language);
        }

        if ($this->matchesQuery($text)) {
            return $this->interpretQuery($text, $language);
        }

        // Explicit creation verbs win, so "create a task to get the car done"
        // never gets mistaken for a completion command.
        $explicitCreate = (bool) preg_match(
            '/^\s*(remind me|create|add|new task|schedule|मुझे|याद दिला|टास्क बनाओ|बनाओ)/u',
            $text,
        );

        if (! $explicitCreate && $this->matchesComplete($text)) {
            return $this->interpretComplete($user, $text, $language);
        }

        // Default: create a task / reminder.
        return $this->interpretCreate($user, $text, $language, $tz);
    }

    // --- Intent: query -------------------------------------------------------

    protected function matchesQuery(string $text): bool
    {
        return (bool) preg_match('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(show|list|display|what are|दिखाओ|दिखाइए|बताओ)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', $text);
    }

    protected function interpretQuery(string $text, string $language): array
    {
        $filters = [];

        if (preg_match('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(important|ज़रूरी|जरूरी|महत्वपूर्ण)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', $text)) {
            $filters['important'] = 1;
        }
        if (preg_match('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(overdue|late|बकाया|देरी)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', $text)) {
            $filters['overdue'] = 1;
        }
        if (preg_match('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(completed|done|finished|पूर्ण|पूरे|पूरा)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', $text)) {
            $filters['status'] = 'completed';
        } elseif (preg_match('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(pending|open|remaining|बाकी|लंबित)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', $text)) {
            $filters['status'] = 'not_started,planned,in_progress,waiting,on_hold';
        }
        if (preg_match('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(today|आज)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', $text)) {
            $filters['date_from'] = now()->toDateString();
            $filters['date_to'] = now()->toDateString();
        }

        return [
            'intent' => 'query_tasks',
            'language' => $language,
            'data' => ['filters' => $filters],
            'speech' => $language === 'hi'
                ? 'ठीक है, आपके टास्क दिखा रही हूँ।'
                : 'Okay, showing your tasks.',
        ];
    }

    // --- Intent: complete ----------------------------------------------------

    protected function matchesComplete(string $text): bool
    {
        $boundary = '(?:(?<![\p{L}\d])|(?![\p{L}\d]))';

        // Classic: "mark X as completed" / "पूरा करो".
        if (preg_match(
            "/{$boundary}(mark|complete|finish|done){$boundary}.*{$boundary}(as )?(completed|done|complete|finished){$boundary}|{$boundary}पूरा (करो|करें|हुआ|कर दो){$boundary}/u",
            $text,
        )) {
            return true;
        }

        // Leading verb: "complete X", "close X", "mark X", "finish X".
        if (preg_match("/^\s*(mark|complete|close|finish){$boundary}/u", $text)) {
            return true;
        }

        // Natural forms: any close/complete word alongside "task" —
        // e.g. "make call rahul task complete", "call rahul task closed",
        // "set the rent task done", "कॉल टास्क पूरा".
        $completionWord = "{$boundary}(complete|completed|close|closed|finish|finished|done|पूरा|पूर्ण){$boundary}";
        $taskWord = "{$boundary}(task|टास्क){$boundary}";

        return (bool) (preg_match("/{$completionWord}/u", $text) && preg_match("/{$taskWord}/u", $text));
    }

    protected function interpretComplete(User $user, string $text, string $language): array
    {
        // Strip command words to isolate the task title.
        $title = preg_replace(
            '/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(make|set|mark|complete|close|closed|finish|finished|the|my|task|as|completed|done|please|को|टास्क|पूरा|पूर्ण|करो|करें|कर दो|हुआ|मेरा)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u',
            ' ',
            $text,
        );
        $title = trim(preg_replace('/\s+/', ' ', $title));

        $match = null;
        if ($title !== '') {
            $match = Task::visibleTo($user)
                ->whereNotIn('status', ['completed', 'cancelled', 'archived'])
                ->where(function ($q) use ($title) {
                    $q->where('title', 'like', "%{$title}%");
                    foreach (array_filter(explode(' ', $title)) as $word) {
                        if (mb_strlen($word) >= 3) {
                            $q->orWhere('title', 'like', "%{$word}%");
                        }
                    }
                })
                ->orderByRaw('CASE WHEN title LIKE ? THEN 0 ELSE 1 END', ["%{$title}%"])
                ->first();
        }

        if (! $match) {
            return [
                'intent' => 'complete_task',
                'language' => $language,
                'data' => ['task' => null, 'heard_title' => $title],
                'speech' => $language === 'hi'
                    ? 'मुझे वह टास्क नहीं मिला।'
                    : "I couldn't find that task.",
            ];
        }

        return [
            'intent' => 'complete_task',
            'language' => $language,
            'data' => [
                'task' => ['uuid' => $match->uuid, 'title' => $match->title, 'status' => $match->status],
                'heard_title' => $title,
            ],
            'speech' => $language === 'hi'
                ? "क्या मैं “{$match->title}” को पूरा मार्क कर दूँ?"
                : "Mark “{$match->title}” as completed?",
        ];
    }

    // --- Intent: create ------------------------------------------------------

    protected function interpretCreate(User $user, string $text, string $language, string $tz): array
    {
        $isReminder = (bool) preg_match('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(remind me|reminder|याद दिलाना|याद दिलाओ)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', $text);

        // Repeat
        $repeat = null;
        $repeatPatterns = [
            'daily' => '/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(every day|daily|रोज़|रोज|हर दिन)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u',
            'weekly' => '/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(every week|weekly|हर हफ्ते|हर सप्ताह)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u',
            'monthly' => '/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(every month|monthly|हर महीने|मासिक)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u',
            'yearly' => '/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(every year|yearly|annually|हर साल|सालाना)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u',
        ];
        foreach ($repeatPatterns as $frequency => $pattern) {
            if (preg_match($pattern, $text)) {
                $repeat = ['frequency' => $frequency, 'interval' => 1];
                $text = preg_replace($pattern, ' ', $text);
                break;
            }
        }

        // Reminder offset: "three days before (the due date)" / "तीन दिन पहले"
        $offsetMinutes = null;
        if (preg_match('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(\d+|\w+) (minute|minutes|hour|hours|day|days|week|weeks) before( the due date| due date)?(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', $text, $m)
            || preg_match('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(\d+|[\x{0900}-\x{097F}]+) (मिनट|घंटे|दिन|हफ्ते) पहले(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', $text, $m)) {
            $amount = $this->dates->toNumber($m[1]);
            if ($amount !== null) {
                $unit = $m[2];
                $offsetMinutes = match (true) {
                    str_starts_with($unit, 'minute'), $unit === 'मिनट' => $amount,
                    str_starts_with($unit, 'hour'), $unit === 'घंटे' => $amount * 60,
                    str_starts_with($unit, 'week'), $unit === 'हफ्ते' => $amount * 7 * 1440,
                    default => $amount * 1440,
                };
                $text = str_replace($m[0], ' ', $text);
            }
        }

        // Priority / importance
        $isImportant = (bool) preg_match('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(important|urgent|ज़रूरी|जरूरी|महत्वपूर्ण)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', $text);
        $priority = preg_match('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(urgent|तुरंत)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', $text) ? 'urgent' : ($isImportant ? 'high' : 'normal');
        $text = preg_replace('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(important|urgent|ज़रूरी|जरूरी|महत्वपूर्ण|तुरंत)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', ' ', $text);

        // Category hint: "family task", "work task", "परिवार"
        $categoryUuid = null;
        $categoryName = null;
        $categoryHints = [
            'family' => ['family', 'परिवार', 'घर का'],
            'work' => ['work', 'office', 'काम', 'ऑफिस', 'दफ्तर'],
            'health' => ['health', 'doctor', 'सेहत', 'डॉक्टर'],
            'bills' => ['bill', 'bills', 'बिल'],
            'shopping' => ['shopping', 'groceries', 'खरीदारी'],
            'calls' => ['call', 'कॉल', 'फोन'],
            'meetings' => ['meeting', 'मीटिंग', 'बैठक'],
        ];
        foreach ($categoryHints as $canonical => $hints) {
            foreach ($hints as $hint) {
                if (preg_match('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))' . preg_quote($hint, '/') . '(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', $text)) {
                    $category = Category::visibleTo($user)
                        ->whereRaw('LOWER(name) = ?', [$canonical])
                        ->first();
                    if ($category) {
                        $categoryUuid = $category->uuid;
                        $categoryName = $category->name;
                    }
                    break 2;
                }
            }
        }

        // Date/time
        $parsed = $this->dates->parse($text, $tz);
        $due = $parsed['due'];
        $text = $parsed['remaining'];

        // Title: strip leading command phrases and fillers.
        $title = preg_replace([
            '/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(remind me to|remind me|create (a |an )?(family |work )?(task|reminder)( to| for)?|add (a )?task( to| for)?|new task|schedule (a )?(meeting|task)( with)?|reminder to|मुझे|याद दिलाना|याद दिलाओ|टास्क बनाओ|बनाओ|एक)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u',
            '/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(a|an|the|to|for|please|कृपया|को|के लिए)(?:(?<![\p{L}\d])|(?![\p{L}\d]))\s*$/u',
        ], ' ', $text);
        $title = trim(preg_replace('/\s+/', ' ', $title), " \t.,");
        $title = $title !== '' ? mb_convert_case(mb_substr($title, 0, 1), MB_CASE_UPPER) . mb_substr($title, 1) : '';

        // Offset without an explicit due date is meaningless; keep it only with due.
        $reminders = [];
        if ($offsetMinutes !== null && $due) {
            $reminders[] = ['offset_minutes' => $offsetMinutes];
        } elseif ($isReminder && $due) {
            $reminders[] = ['offset_minutes' => 0];
        }

        $dueLocal = $due?->format('Y-m-d H:i:s');

        $speechDue = $due
            ? ($language === 'hi'
                ? ' ' . $due->locale('hi')->isoFormat('D MMMM, h:mm A') . ' के लिए'
                : ' for ' . $due->isoFormat('D MMMM, h:mm A'))
            : '';

        return [
            'intent' => 'create_task',
            'language' => $language,
            'data' => [
                'task' => array_filter([
                    'title' => $title !== '' ? $title : ($language === 'hi' ? 'नया टास्क' : 'New task'),
                    'due_at' => $dueLocal,
                    'priority' => $priority,
                    'is_important' => $isImportant,
                    'repeat_config' => $repeat,
                    'category_uuid' => $categoryUuid,
                    'category_name' => $categoryName,
                    'reminders' => $reminders,
                ], fn ($v) => $v !== null && $v !== [] && $v !== false),
                'confidence' => $title !== '' ? ($due || $repeat ? 'high' : 'medium') : 'low',
            ],
            'speech' => $language === 'hi'
                ? "टास्क तैयार है{$speechDue}। सेव करने से पहले जाँच लें।"
                : "I've prepared the task{$speechDue}. Review it before saving.",
        ];
    }

    protected function unknown(string $language): array
    {
        return [
            'intent' => 'unknown',
            'language' => $language,
            'data' => [],
            'speech' => $language === 'hi'
                ? 'माफ़ कीजिए, मैं समझ नहीं पाई। फिर से कोशिश करें।'
                : "Sorry, I didn't catch that. Please try again.",
        ];
    }
}
