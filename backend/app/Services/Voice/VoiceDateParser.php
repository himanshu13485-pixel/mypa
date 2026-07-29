<?php

namespace App\Services\Voice;

use Illuminate\Support\Carbon;

/**
 * Extracts a date/time from a spoken English or Hindi phrase and returns the
 * remaining text with the date words removed. All parsing happens in the
 * user's timezone; the caller converts to UTC for storage.
 */
class VoiceDateParser
{
    protected const WEEKDAYS = [
        'monday' => Carbon::MONDAY, 'tuesday' => Carbon::TUESDAY, 'wednesday' => Carbon::WEDNESDAY,
        'thursday' => Carbon::THURSDAY, 'friday' => Carbon::FRIDAY, 'saturday' => Carbon::SATURDAY,
        'sunday' => Carbon::SUNDAY,
        'सोमवार' => Carbon::MONDAY, 'मंगलवार' => Carbon::TUESDAY, 'बुधवार' => Carbon::WEDNESDAY,
        'गुरुवार' => Carbon::THURSDAY, 'शुक्रवार' => Carbon::FRIDAY, 'शनिवार' => Carbon::SATURDAY,
        'रविवार' => Carbon::SUNDAY,
    ];

    protected const NUMBER_WORDS = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'eleven' => 11, 'twelve' => 12,
        'एक' => 1, 'दो' => 2, 'तीन' => 3, 'चार' => 4, 'पांच' => 5, 'पाँच' => 5,
        'छह' => 6, 'सात' => 7, 'आठ' => 8, 'नौ' => 9, 'दस' => 10,
        'ग्यारह' => 11, 'बारह' => 12,
    ];

    /**
     * @return array{due: ?Carbon, remaining: string, matched: bool}
     */
    public function parse(string $text, string $timezone): array
    {
        $now = Carbon::now($timezone);
        $due = null;
        $matched = false;

        $text = ' ' . mb_strtolower($text) . ' ';

        // --- Relative days ---------------------------------------------------
        $dayPatterns = [
            '/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(day after tomorrow|परसों)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u' => fn () => $now->copy()->addDays(2),
            '/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(tomorrow|कल)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u' => fn () => $now->copy()->addDay(),
            '/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(today|आज)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u' => fn () => $now->copy(),
            '/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(tonight|आज रात)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u' => fn () => $now->copy()->setTime(20, 0),
            '/(?:(?<![\p{L}\d])|(?![\p{L}\d]))next week|अगले हफ्ते|अगले सप्ताह(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u' => fn () => $now->copy()->addWeek()->startOfWeek(),
            '/(?:(?<![\p{L}\d])|(?![\p{L}\d]))next month|अगले महीने(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u' => fn () => $now->copy()->addMonthNoOverflow()->startOfMonth(),
        ];

        foreach ($dayPatterns as $pattern => $resolver) {
            if (preg_match($pattern, $text)) {
                $due = $resolver();
                $text = preg_replace($pattern, ' ', $text);
                $matched = true;
                break;
            }
        }

        // --- "next <weekday>" / "on <weekday>" / bare weekday ---------------
        if (! $due) {
            foreach (self::WEEKDAYS as $word => $dayNumber) {
                if (preg_match('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(next |on |अगले )?' . preg_quote($word, '/') . '(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', $text, $m)) {
                    $due = $now->copy()->next($dayNumber);
                    $text = preg_replace('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(next |on |अगले )?' . preg_quote($word, '/') . '(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', ' ', $text);
                    $matched = true;
                    break;
                }
            }
        }

        // --- "in X minutes/hours/days" / "X दिन बाद" -------------------------
        if (! $due && preg_match('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))in (\d+|\w+) (minute|minutes|hour|hours|day|days)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', $text, $m)) {
            $amount = $this->toNumber($m[1]);
            if ($amount !== null) {
                $due = match (true) {
                    str_starts_with($m[2], 'minute') => $now->copy()->addMinutes($amount),
                    str_starts_with($m[2], 'hour') => $now->copy()->addHours($amount),
                    default => $now->copy()->addDays($amount),
                };
                $text = str_replace($m[0], ' ', $text);
                $matched = true;
            }
        }
        if (! $due && preg_match('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(\d+|[\x{0900}-\x{097F}]+) (मिनट|घंटे|दिन) (बाद|में)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', $text, $m)) {
            $amount = $this->toNumber($m[1]);
            if ($amount !== null) {
                $due = match ($m[2]) {
                    'मिनट' => $now->copy()->addMinutes($amount),
                    'घंटे' => $now->copy()->addHours($amount),
                    default => $now->copy()->addDays($amount),
                };
                $text = str_replace($m[0], ' ', $text);
                $matched = true;
            }
        }

        // --- Time of day: "at 3 pm", "at 15:30", "3 बजे", "शाम 5 बजे" -------
        $time = null;
        if (preg_match('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))at (\d{1,2})(?::(\d{2}))?\s*(am|pm|a\.m\.|p\.m\.)?(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', $text, $m)) {
            $time = $this->resolveTime((int) $m[1], isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0, $m[3] ?? null);
            $text = str_replace($m[0], ' ', $text);
            $matched = true;
        } elseif (preg_match('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(सुबह|दोपहर|शाम|रात)?\s*(\d{1,2}|[\x{0900}-\x{097F}]+)\s*बजे(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', $text, $m)) {
            $hour = $this->toNumber($m[2]);
            if ($hour !== null && $hour >= 0 && $hour <= 23) {
                $time = $this->resolveHindiTime($hour, $m[1] ?: null);
                $text = str_replace($m[0], ' ', $text);
                $matched = true;
            }
        } elseif (preg_match('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(morning|सुबह)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', $text, $m)) {
            $time = [9, 0];
            $text = str_replace($m[0], ' ', $text);
        } elseif (preg_match('/(?:(?<![\p{L}\d])|(?![\p{L}\d]))(evening|शाम को)(?:(?<![\p{L}\d])|(?![\p{L}\d]))/u', $text, $m)) {
            $time = [18, 0];
            $text = str_replace($m[0], ' ', $text);
        }

        if ($time) {
            $due = ($due ?? $now->copy());
            $due->setTime($time[0], $time[1]);
            if ($due->isPast() && $due->isSameDay($now)) {
                // "at 3 pm" said after 3 pm → assume tomorrow.
                $due->addDay();
            }
        } elseif ($due && $due->equalTo($due->copy()->startOfDay())) {
            $due->setTime(9, 0);
        } elseif ($due && ! $matched) {
            $due = null;
        }

        return [
            'due' => $due,
            'remaining' => trim(preg_replace('/\s+/', ' ', $text)),
            'matched' => $matched,
        ];
    }

    protected function resolveTime(int $hour, int $minute, ?string $meridiem): array
    {
        $meridiem = $meridiem ? str_replace('.', '', strtolower($meridiem)) : null;

        if ($meridiem === 'pm' && $hour < 12) {
            $hour += 12;
        } elseif ($meridiem === 'am' && $hour === 12) {
            $hour = 0;
        } elseif ($meridiem === null && $hour >= 1 && $hour <= 7) {
            // Bare "at 3" more likely means 3 pm during the day.
            $hour += 12;
        }

        return [min(23, $hour), min(59, $minute)];
    }

    protected function resolveHindiTime(int $hour, ?string $period): array
    {
        $hour = match ($period) {
            'दोपहर' => $hour < 12 ? $hour + 12 : $hour,
            'शाम' => $hour < 12 ? $hour + 12 : $hour,
            'रात' => $hour <= 3 ? $hour : ($hour < 12 ? $hour + 12 : $hour),
            'सुबह' => $hour === 12 ? 0 : $hour,
            default => $hour >= 1 && $hour <= 7 ? $hour + 12 : $hour,
        };

        return [min(23, $hour), 0];
    }

    public function toNumber(string $word): ?int
    {
        if (is_numeric($word)) {
            return (int) $word;
        }

        return self::NUMBER_WORDS[mb_strtolower($word)] ?? null;
    }
}
