<?php

namespace App\Services\Voice;

/**
 * Cleans a spoken transcript before any pattern rule sees it.
 *
 * The rules are precise but literal: "can you please call rahul" missed the
 * call pattern purely because of the four words in front of it, and speech
 * recognition mis-hearing "meeting" as "meating" was enough to lose the
 * command entirely. Normalising once here lifts every rule at the same time,
 * instead of teaching each pattern about politeness and mis-hearings
 * separately.
 *
 * Deliberately conservative — it only removes words that carry no meaning for
 * any command, and only repairs strings that are not words in their own right.
 */
class TranscriptNormalizer
{
    /**
     * Openers people put in front of a command. Stripped only from the start,
     * and only in this order, so "tell rahul I want to leave" keeps its
     * message body intact — the opener there is not at the front.
     */
    protected const LEADING_FILLERS = [
        'um', 'uh', 'erm', 'hmm', 'hey', 'hi', 'hello', 'ok', 'okay', 'so',
        'well', 'right', 'now', 'please', 'kindly', 'quickly',
        'can you', 'could you', 'would you', 'will you', 'can u',
        'i want to', 'i wanna', 'i would like to', "i'd like to", 'i need to',
        'i want you to', 'i need you to', 'let us', "let's", 'lets',
        'go ahead and', 'help me', 'try to', 'try and',
        // Hindi openers. "मुझे" is deliberately absent — the task creator
        // matches on it ("मुझे याद दिला"), so removing it would break
        // reminder commands.
        'ज़रा', 'जरा', 'प्लीज़', 'प्लीज', 'अरे', 'हे',
        'क्या आप', 'क्या तुम', 'आप', 'तुम',
    ];

    /** Politeness people put after a command; carries no meaning either. */
    protected const TRAILING_FILLERS = [
        'please', 'thanks', 'thank you', 'for me', 'right now', 'now',
        'ok', 'okay', 'plz', 'asap',
        'प्लीज़', 'प्लीज', 'धन्यवाद', 'शुक्रिया', 'अभी',
    ];

    /**
     * Speech-recognition mis-hearings, mapped back to the intended word.
     *
     * Every key here is a string that is not itself a word, so a repair can
     * never change what the speaker actually said. "massage" is intentionally
     * missing for that reason — it is a real word, and "book a massage" is a
     * plausible task.
     */
    protected const MISHEARINGS = [
        'meating' => 'meeting',
        'meetin' => 'meeting',
        'meting' => 'meeting',
        'shedule' => 'schedule',
        'schedual' => 'schedule',
        'schedjule' => 'schedule',
        'mesage' => 'message',
        'messsage' => 'message',
        'mssage' => 'message',
        'calender' => 'calendar',
        'calandar' => 'calendar',
        'taks' => 'task',
        'tsk' => 'task',
        'remind me to' => 'remind me to',
        'habbit' => 'habit',
        'habitt' => 'habit',
        'conection' => 'connection',
        'conections' => 'connections',
        'setings' => 'settings',
        'seting' => 'settings',
        'dashbord' => 'dashboard',
        'dashboad' => 'dashboard',
        'projct' => 'project',
        'reminde' => 'remind',
        'cancle' => 'cancel',
        'recieve' => 'receive',
        'electricty' => 'electricity',
        'electicity' => 'electricity',
    ];

    public function normalize(string $text): string
    {
        $text = $this->repairMishearings($text);
        $text = $this->stripLeading($text);
        $text = $this->stripTrailing($text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    protected function repairMishearings(string $text): string
    {
        foreach (self::MISHEARINGS as $wrong => $right) {
            $text = preg_replace(
                '/(?<![\p{L}\d])'.preg_quote($wrong, '/').'(?![\p{L}\d])/u',
                $right,
                $text,
            );
        }

        return $text;
    }

    /**
     * Peel openers off the front, repeatedly — "ok so can you call rahul" has
     * three stacked. Stops as soon as nothing matches, and never consumes the
     * whole transcript.
     */
    protected function stripLeading(string $text): string
    {
        $changed = true;

        while ($changed) {
            $changed = false;

            foreach (self::LEADING_FILLERS as $filler) {
                $pattern = '/^\s*'.preg_quote($filler, '/').'(?![\p{L}\d])[\s,]*/u';
                $candidate = preg_replace($pattern, '', $text, 1);

                if ($candidate !== null && $candidate !== $text && trim($candidate) !== '') {
                    $text = $candidate;
                    $changed = true;
                    break;
                }
            }
        }

        return $text;
    }

    protected function stripTrailing(string $text): string
    {
        $changed = true;

        while ($changed) {
            $changed = false;

            foreach (self::TRAILING_FILLERS as $filler) {
                $pattern = '/[\s,]*(?<![\p{L}\d])'.preg_quote($filler, '/').'\s*[.!?]*$/u';
                $candidate = preg_replace($pattern, '', $text, 1);

                if ($candidate !== null && $candidate !== $text && trim($candidate) !== '') {
                    $text = $candidate;
                    $changed = true;
                    break;
                }
            }
        }

        return $text;
    }
}
