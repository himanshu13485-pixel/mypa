<?php

namespace App\Services\Voice;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Pattern rules for the communication side of the assistant: calls, messages,
 * meetings, screen sharing, and plain navigation. Returns null when the
 * transcript is not a communication command, so task interpretation (and the
 * AI fallback) can take over.
 *
 * Same contract as the task intents: nothing is executed here — the frontend
 * shows the interpretation and acts only on confirmation.
 */
class CommunicationCommandService
{
    /**
     * Every sidebar destination, with the spoken forms that mean it. Keys are
     * the page ids the frontend routes on; 'path' overrides let a phrase land
     * on a filtered view or a specific admin tab.
     */
    protected const PAGES = [
        'dashboard' => ['dashboard', 'home', 'home page', 'डैशबोर्ड', 'होम'],
        'connections' => ['connections', 'connection', 'contacts', 'my connections', 'कनेक्शन', 'संपर्क'],
        'groups' => ['family & teams', 'family and teams', 'family teams', 'family', 'teams', 'groups', 'my family', 'परिवार', 'टीम', 'ग्रुप'],
        'messages' => ['messages', 'message', 'chats', 'chat', 'inbox', 'मैसेज', 'चैट', 'संदेश'],
        'calls' => ['calls', 'call history', 'call log', 'कॉल', 'कॉल हिस्ट्री'],
        'meetings' => ['meetings', 'meeting list', 'my meetings', 'मीटिंग', 'बैठक'],
        'screen' => ['screen', 'screen sharing', 'screen share', 'स्क्रीन'],
        'projects' => ['projects', 'project', 'ledger', 'प्रोजेक्ट'],
        'notes' => ['notes', 'my notes', 'नोट्स', 'नोट'],
        'files' => ['files', 'documents', 'my files', 'फाइल', 'दस्तावेज़'],
        'tasks' => ['tasks', 'task list', 'my tasks', 'todo', 'to do', 'टास्क', 'काम'],
        'important' => ['important', 'important tasks', 'starred tasks', 'ज़रूरी टास्क', 'महत्वपूर्ण'],
        'calendar' => ['calendar', 'my calendar', 'कैलेंडर'],
        'categories' => ['categories', 'category', 'श्रेणियां', 'कैटेगरी'],
        'habits' => ['habits', 'my habits', 'आदतें', 'हैबिट'],
        'goals' => ['goals', 'my goals', 'लक्ष्य', 'गोल'],
        'bills' => ['bills', 'my bills', 'बिल'],
        'reports' => ['reports', 'my reports', 'रिपोर्ट'],
        'subscription' => ['subscription', 'my plan', 'my subscription', 'plan', 'सब्सक्रिप्शन', 'प्लान'],
        'settings' => ['settings', 'my settings', 'preferences', 'सेटिंग', 'सेटिंग्स'],
        'admin' => ['admin', 'admin panel', 'एडमिन', 'एडमिन पैनल'],
    ];

    /** Spoken form => concrete path, for filtered views and admin tabs. */
    protected const SUBPAGES = [
        'paid bills' => '/bills?status=paid',
        'unpaid bills' => '/bills?status=unpaid',
        'all bills' => '/bills?status=all',
        'pending bills' => '/bills?status=unpaid',
        'admin overview' => '/admin?tab=overview',
        'admin settings' => '/admin?tab=overview',
        'admin users' => '/admin?tab=users',
        'admin members' => '/admin?tab=active',
        'active members' => '/admin?tab=active',
        'admin plans' => '/admin?tab=plans',
        'admin approvals' => '/admin?tab=approvals',
        'approvals' => '/admin?tab=approvals',
        'admin activity' => '/admin?tab=activity',
        'admin logins' => '/admin?tab=logins',
        'admin moderation' => '/admin?tab=moderation',
        'moderation' => '/admin?tab=moderation',
        'internal work' => '/admin?tab=internal',
        'my users' => '/admin?tab=sales',
    ];

    public function __construct(protected ContactResolver $contacts)
    {
    }

    /** @return array<string, mixed>|null */
    public function match(User $user, string $text, string $language): ?array
    {
        return $this->matchHelp($text, $language)
            ?? $this->matchEndCall($text, $language)
            ?? $this->matchNavigate($text, $language)
            ?? $this->matchCall($user, $text, $language)
            ?? $this->matchMessage($user, $text, $language)
            ?? $this->matchMeeting($user, $text, $language)
            ?? $this->matchScreen($user, $text, $language);
    }

    // --- Help ----------------------------------------------------------------

    protected function matchHelp(string $text, string $language): ?array
    {
        if (! preg_match(
            '/^(?:help|what (?:all )?can you do|what do you do|commands|capabilities|मदद|हेल्प|तुम क्या( क्या)? कर सकती हो|क्या( क्या)? कर सकती हो|क्या कर सकते हो)\??$/u',
            $text,
        )) {
            return null;
        }

        return [
            'intent' => 'help',
            'language' => $language,
            'data' => [],
            'speech' => $language === 'hi'
                ? 'मैं कॉल, मैसेज, मीटिंग, टास्क, आदतें, लक्ष्य, बिल — सब संभाल सकती हूँ, और ऐप का कोई भी हिस्सा खोल सकती हूँ।'
                : 'I can call and message your connections, run meetings and screen shares, manage tasks, habits, goals and bills, and open any part of the app.',
        ];
    }

    // --- Hang up -------------------------------------------------------------

    protected function matchEndCall(string $text, string $language): ?array
    {
        $patterns = [
            // "hang up", "disconnect (the call)"
            '/\b(?:hang\s*up|disconnect)\b(?:\s+(?:the\s+|this\s+)?(?:call|phone))?/u',
            // "end/cut/drop/finish the call"
            '/\b(?:end|cut|drop|finish|stop)\s+(?:the\s+|this\s+)?call\b/u',
            // "कॉल काटो / बंद करो / खत्म करो", "फोन रखो"
            '/(?:कॉल|फ़ोन|फोन)\s*(?:काटो|काट\s*दो|बंद\s*करो|खत्म\s*करो|समाप्त\s*करो|रखो|रख\s*दो)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return [
                    'intent' => 'end_call',
                    'language' => $language,
                    'data' => [],
                    'speech' => $language === 'hi' ? 'ठीक है, कॉल खत्म कर रही हूँ।' : 'Okay, ending the call.',
                ];
            }
        }

        return null;
    }

    // --- Calls ---------------------------------------------------------------

    protected function matchCall(User $user, string $text, string $language): ?array
    {
        // "call rahul task done" is completing a task named after a call, not
        // placing one — leave anything that pairs task-words with
        // completion-words to the task interpreter.
        if (preg_match('/(task|टास्क)/u', $text) && preg_match('/(done|complete|completed|finish|finished|पूरा|पूर्ण)/u', $text)) {
            return null;
        }

        $patterns = [
            // "call rahul", "video call rahul", "please call rahul now"
            '/^(?:please\s+)?(?:video\s+)?call\s+(?:to\s+|with\s+)?(.+?)(?:\s+(?:now|please))?$/u',
            // "make/start/place/connect a (video) call to/with rahul"
            '/(?:make|start|place|connect|begin)\s+(?:a\s+|the\s+)?(?:video\s+)?call\s+(?:to|with)\s+(.+)/u',
            // "connect me with rahul on a call", "get rahul on a call"
            '/(?:connect me with|get)\s+(.+?)\s+on\s+(?:a\s+)?(?:video\s+)?call/u',
            // "ring rahul", "dial rahul", "phone rahul"
            '/^(?:ring|dial|phone)\s+(.+)$/u',
            // "rahul को कॉल/फोन करो|लगाओ|मिलाओ"
            '/(.+?)\s*को\s*(?:वीडियो\s*)?(?:कॉल|फ़ोन|फोन)\s*(?:करो|करें|कीजिए|लगाओ|मिलाओ)/u',
            // "कॉल करो rahul को" word order
            '/(?:वीडियो\s*)?(?:कॉल|फ़ोन|फोन)\s*(?:करो|करें|कीजिए|लगाओ|मिलाओ)\s+(.+?)(?:\s*को)?$/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $type = preg_match('/(video|वीडियो)/u', $text) ? 'video' : 'audio';

                return $this->personIntent($user, 'call_person', $m[1], $language, ['call_type' => $type]);
            }
        }

        return null;
    }

    // --- Messages ------------------------------------------------------------

    protected function matchMessage(User $user, string $text, string $language): ?array
    {
        $patterns = [
            // "message rahul (saying/that) i'll be late", "send a message to rahul"
            '/^(?:please\s+)?(?:send\s+)?(?:a\s+)?(?:message|msg|text)\s+(?:to\s+)?(.+?)(?:\s+(?:saying|that|:)\s+(.+))?$/u',
            // "tell rahul that i'll be late", "tell rahul i am coming"
            '/^tell\s+(.+?)\s+(?:that\s+)?(.+)$/u',
            // "rahul को मैसेज भेजो (कि ...)"
            '/(.+?)\s*को\s*(?:मैसेज|संदेश)\s*(?:भेजो|भेजें|करो|करें|कीजिए)(?:\s*(?:कि|की)\s*(.+))?/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                // "tell me about..." is the user talking to the assistant,
                // not a message to a person called "me".
                if (in_array(trim($m[1]), ['me', 'us', 'मुझे', 'हमें'], true)) {
                    continue;
                }

                return $this->personIntent($user, 'message_person', $m[1], $language, [
                    'text' => trim($m[2] ?? ''),
                ]);
            }
        }

        return null;
    }

    // --- Meetings ------------------------------------------------------------

    protected function matchMeeting(User $user, string $text, string $language): ?array
    {
        $patterns = [
            // "start/create/set up a meeting (with rahul and priya)"
            '/(?:start|create|new|set ?up|begin|host)\s+(?:an?\s+|the\s+)?(?:instant\s+|quick\s+)?meeting(?:\s+(?:with|for)\s+(.+))?/u',
            // "meeting शुरू करो (rahul के साथ)"
            '/(?:(.+?)\s*के\s*साथ\s*)?(?:मीटिंग|बैठक)\s*(?:शुरू|स्टार्ट|चालू)\s*(?:करो|करें|कीजिए)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return $this->groupIntent($user, 'start_meeting', $m[1] ?? '', $language);
            }
        }

        return null;
    }

    // --- Screen sharing ------------------------------------------------------

    protected function matchScreen(User $user, string $text, string $language): ?array
    {
        $patterns = [
            // "share my screen (with rahul)", "start screen sharing with rahul"
            '/(?:share|start sharing)\s+(?:my\s+)?screen(?:\s+(?:with|to)\s+(.+))?/u',
            '/start\s+screen\s*shar(?:e|ing)(?:\s+(?:with|to)\s+(.+))?/u',
            // "स्क्रीन शेयर करो (rahul के साथ)"
            '/(?:(.+?)\s*के\s*साथ\s*)?स्क्रीन\s*(?:शेयर|साझा)\s*(?:करो|करें|कीजिए)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return $this->groupIntent($user, 'share_screen', $m[1] ?? '', $language);
            }
        }

        return null;
    }

    // --- Navigation ----------------------------------------------------------

    protected function matchNavigate(string $text, string $language): ?array
    {
        if (! preg_match(
            '/^(?:open|go to|goto|take me to|show(?:\s+me)?(?:\s+my)?|खोलो|खोलें|दिखाओ|(.+?)\s+(?:खोलो|खोलें|दिखाओ))\s*(.*)$/u',
            $text,
            $m,
        )) {
            return null;
        }

        // "बिल खोलो" puts the page before the verb; "open bills" after it.
        $spoken = trim($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
        $spoken = trim(preg_replace('/\s*(?:page|tab|section|पेज|टैब)$/u', '', $spoken));
        $spoken = trim(preg_replace('/^(?:the|my|मेरा|मेरी|मेरे)\s+/u', '', $spoken));

        if ($spoken === '') {
            return null;
        }

        // Specific views first ("paid bills", "admin plans", "internal work").
        foreach (self::SUBPAGES as $form => $path) {
            if ($spoken === $form) {
                return [
                    'intent' => 'navigate',
                    'language' => $language,
                    'data' => ['page' => $form, 'path' => $path],
                    'speech' => $language === 'hi' ? 'ठीक है, खोल रही हूँ।' : "Okay, opening {$form}.",
                ];
            }
        }

        foreach (self::PAGES as $page => $forms) {
            if (in_array($spoken, $forms, true)) {
                return [
                    'intent' => 'navigate',
                    'language' => $language,
                    'data' => ['page' => $page],
                    'speech' => $language === 'hi' ? 'ठीक है, खोल रही हूँ।' : "Okay, opening {$page}.",
                ];
            }
        }

        // "show my pending tasks" etc. is a task query, not navigation.
        return null;
    }

    // --- Shared builders -----------------------------------------------------

    /** An intent aimed at one person (call, message). */
    protected function personIntent(User $user, string $intent, string $spokenName, string $language, array $extra): array
    {
        $candidates = $this->contacts->resolve($user, $spokenName);

        return [
            'intent' => $intent,
            'language' => $language,
            'data' => $extra + [
                'person_spoken' => trim($spokenName),
                'candidates' => $this->serialize($candidates),
            ],
            'speech' => $this->personSpeech($intent, $candidates, trim($spokenName), $language),
        ];
    }

    /** An intent that may involve several people (meeting, screen share). */
    protected function groupIntent(User $user, string $intent, string $spokenNames, string $language): array
    {
        $names = collect(preg_split('/\s*(?:,|and|और|तथा)\s*/u', trim($spokenNames)))
            ->map(fn ($n) => trim($n))
            ->filter()
            ->values();

        $people = $names->map(fn (string $name) => [
            'spoken' => $name,
            'candidates' => $this->serialize($this->contacts->resolve($user, $name)),
        ]);

        $label = $intent === 'start_meeting'
            ? ($language === 'hi' ? 'मीटिंग' : 'meeting')
            : ($language === 'hi' ? 'स्क्रीन शेयरिंग' : 'screen sharing');

        return [
            'intent' => $intent,
            'language' => $language,
            'data' => ['people' => $people->all()],
            'speech' => $language === 'hi'
                ? "ठीक है, {$label} शुरू करने के लिए तैयार।"
                : "Okay, ready to start {$label}.",
        ];
    }

    /** @param Collection<int, User> $candidates */
    protected function serialize(Collection $candidates): array
    {
        return $candidates->map(fn (User $u) => [
            'uuid' => $u->uuid,
            'name' => $u->name,
            'username' => $u->username,
            'app_id' => optional($u->appId)->app_id,
        ])->all();
    }

    /** @param Collection<int, User> $candidates */
    protected function personSpeech(string $intent, Collection $candidates, string $spoken, string $language): string
    {
        $verb = $intent === 'call_person'
            ? ($language === 'hi' ? 'कॉल' : 'call')
            : ($language === 'hi' ? 'मैसेज' : 'message');

        if ($candidates->isEmpty()) {
            return $language === 'hi'
                ? "माफ़ कीजिए, आपके कनेक्शन में \"{$spoken}\" नहीं मिला।"
                : "Sorry, I couldn't find \"{$spoken}\" in your connections.";
        }

        if ($candidates->count() > 1) {
            return $language === 'hi'
                ? "\"{$spoken}\" नाम के कई लोग मिले — कृपया चुनें।"
                : "I found more than one match for \"{$spoken}\" — please pick one.";
        }

        $name = $candidates->first()->name;

        return $language === 'hi'
            ? "{$name} को {$verb} करें?"
            : "Ready to {$verb} {$name}?";
    }
}
