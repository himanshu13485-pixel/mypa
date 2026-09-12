<?php

namespace App\Support;

use App\Http\Controllers\Api\V1\Crm\BirthdayWishController;
use App\Models\AppSetting;
use App\Models\Crm\Member;
use App\Models\Crm\Organization;
use App\Models\User;

/**
 * The backgrounds and sidebars on offer, and whose choice a person sees.
 *
 * The drawing lives in the client (frontend/src/lib/backgrounds.ts), where the
 * gradients and SVG tiles are; the server only needs the names, so it can
 * refuse one it does not know rather than store a value no screen can draw.
 * The two lists have to agree.
 *
 * A look is chosen in three places, nearest first:
 *
 *   1. the person themselves - anybody may, from CRM Theme or Settings;
 *   2. their company's Admin, as the default for the company;
 *   3. Netvork's Super Admin, as the default for everybody else.
 *
 * Each field falls back on its own: somebody who picked a background but not
 * a sidebar still wears the company's sidebar. And the answer is the same on
 * the CRM screens and the personal ones - a company member's theme is theirs
 * everywhere in Netvork, not a CRM setting.
 */
class Appearance
{
    /** @var array<string, string> key => label, as the activity log names it */
    public const BACKGROUNDS = [
        'aurora' => 'Aurora',
        'sunset' => 'Sunset',
        'ocean' => 'Ocean depth',
        'meadow' => 'Meadow',
        'royal' => 'Royal',
        'mesh-candy' => 'Candy mesh',
        'mesh-northern' => 'Northern lights',
        'mesh-peach' => 'Peach glow',
        'dots' => 'Soft dots',
        'grid' => 'Blueprint grid',
        'waves' => 'Waves',
        'diagonal' => 'Pinstripe',
        'honeycomb' => 'Honeycomb',
        'confetti' => 'Confetti',
    ];

    /** @var array<string, string> */
    public const SIDEBARS = [
        'midnight' => 'Midnight',
        'indigo-night' => 'Indigo night',
        'emerald-forest' => 'Emerald forest',
        'plum' => 'Plum',
        'ocean-deep' => 'Ocean deep',
        'ember' => 'Ember',
        'charcoal' => 'Charcoal',
    ];

    /**
     * "No pattern, thank you" - said on purpose.
     *
     * Different from not choosing: somebody whose company wears Confetti can
     * still want a plain screen, and an empty value would hand them the
     * Confetti back.
     */
    public const PLAIN = 'plain';

    private const LOOK = ['background', 'sidebar'];

    private const WORDS = ['default_wish', 'default_reply', 'default_belated'];

    public static function backgroundKeys(): array
    {
        return array_keys(self::BACKGROUNDS);
    }

    public static function sidebarKeys(): array
    {
        return array_keys(self::SIDEBARS);
    }

    /** What a background may be set to, including plain. */
    public static function backgroundChoices(): array
    {
        return [...self::backgroundKeys(), self::PLAIN];
    }

    public static function sidebarChoices(): array
    {
        return [...self::sidebarKeys(), self::PLAIN];
    }

    /**
     * The company a person's theme follows.
     *
     * Their own active membership - never an oversight seat, or the Super
     * Admin would wear whichever company they last looked into.
     */
    public static function membershipOf(User $user): ?Member
    {
        return Member::with('organization')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('is_oversight', false)
            ->get()
            ->first(fn (Member $m) => $m->organization?->status === 'active');
    }

    /**
     * What this person sees, and each layer it came from.
     *
     * The layers travel with the answer so a picker can show "Default" as
     * the look it would actually give, rather than a blank tile.
     */
    public static function layers(User $user, ?Organization $org): array
    {
        $mine = (array) ($user->settings?->appearance ?? []);
        $company = $org ? (array) data_get($org->settings, 'appearance', []) : [];
        $netvork = [
            'background' => AppSetting::get('theme_background') ?: null,
            'sidebar' => AppSetting::get('theme_sidebar') ?: null,
        ];

        $pick = fn (string $f) => ($mine[$f] ?? null) ?: (($company[$f] ?? null) ?: $netvork[$f]);

        return [
            'background' => $pick('background'),
            'sidebar' => $pick('sidebar'),
            'mine' => [
                'background' => $mine['background'] ?? null,
                'sidebar' => $mine['sidebar'] ?? null,
            ],
            'company' => $org ? [
                'name' => $org->name,
                'background' => $company['background'] ?? null,
                'sidebar' => $company['sidebar'] ?? null,
            ] : null,
            'netvork' => $netvork,
        ];
    }

    /**
     * The words a birthday wish and a thank-you start from, for this person.
     *
     * The same three layers as the look, then the built-in wording: a wish
     * is written in the voice of whoever sends it, so it is theirs to word.
     */
    public static function birthday(?User $user, ?Organization $org): array
    {
        $mine = (array) ($user?->settings?->appearance ?? []);
        $company = $org ? (array) data_get($org->settings, 'birthday', []) : [];
        $netvork = [
            'wish' => AppSetting::get('birthday_default_wish') ?: null,
            'reply' => AppSetting::get('birthday_default_reply') ?: null,
            'belated' => AppSetting::get('birthday_default_belated') ?: null,
        ];

        return [
            'wish' => ($mine['default_wish'] ?? null)
                ?: (($company['default_wish'] ?? null) ?: ($netvork['wish'] ?: BirthdayWishController::DEFAULT_WISH)),
            'reply' => ($mine['default_reply'] ?? null)
                ?: (($company['default_reply'] ?? null) ?: ($netvork['reply'] ?: BirthdayWishController::DEFAULT_REPLY)),
            'belated' => ($mine['default_belated'] ?? null)
                ?: (($company['default_belated'] ?? null) ?: ($netvork['belated'] ?: BirthdayWishController::DEFAULT_BELATED)),
            'mine' => [
                'wish' => $mine['default_wish'] ?? null,
                'reply' => $mine['default_reply'] ?? null,
                'belated' => $mine['default_belated'] ?? null,
            ],
            'company' => $org ? [
                'wish' => $company['default_wish'] ?? null,
                'reply' => $company['default_reply'] ?? null,
                'belated' => $company['default_belated'] ?? null,
            ] : null,
            'netvork' => $netvork,
            'built_in' => [
                'wish' => BirthdayWishController::DEFAULT_WISH,
                'reply' => BirthdayWishController::DEFAULT_REPLY,
                'belated' => BirthdayWishController::DEFAULT_BELATED,
            ],
        ];
    }

    /**
     * Save a person's own choices.
     *
     * Only the fields sent are touched, and an empty one means "go back to
     * the default" - the key is removed rather than stored blank. Returns
     * what changed, for the logs.
     */
    public static function saveMine(User $user, array $data): array
    {
        $settings = $user->settings()->firstOrCreate([]);
        $before = (array) ($settings->appearance ?? []);
        $after = self::apply($before, $data, [...self::LOOK, ...self::WORDS]);

        $settings->update(['appearance' => $after ?: null]);
        $user->setRelation('settings', $settings);

        return self::diff($before, $after);
    }

    /**
     * Save a company's defaults: the look in settings.appearance, the words
     * beside the rest of the birthday settings, which are left as they were.
     */
    public static function saveCompany(Organization $org, array $data): array
    {
        $settings = $org->settings ?? [];
        $look = (array) ($settings['appearance'] ?? []);
        $birthday = (array) ($settings['birthday'] ?? []);

        $before = $look + array_intersect_key($birthday, array_flip(self::WORDS));

        $look = self::apply($look, $data, self::LOOK);
        $birthday = self::apply($birthday, $data, self::WORDS);

        $settings['appearance'] = $look;
        $settings['birthday'] = $birthday;
        $org->update(['settings' => $settings]);

        return self::diff($before, $look + array_intersect_key($birthday, array_flip(self::WORDS)));
    }

    /** Only what changed, old to new, in words a person reads. */
    public static function diff(array $before, array $after): array
    {
        $changed = [];

        foreach (self::LOOK as $f) {
            if (($before[$f] ?? null) !== ($after[$f] ?? null)) {
                $changed[$f] = ['from' => self::label($f, $before[$f] ?? null), 'to' => self::label($f, $after[$f] ?? null)];
            }
        }
        foreach (self::WORDS as $f) {
            if (($before[$f] ?? null) !== ($after[$f] ?? null)) {
                $changed[$f] = ['from' => $before[$f] ?? 'Default', 'to' => $after[$f] ?? 'Default'];
            }
        }

        return $changed;
    }

    public static function label(string $field, ?string $key): string
    {
        if ($key === null || $key === '') {
            return 'Default';
        }
        if ($key === self::PLAIN) {
            return 'Plain';
        }

        return ($field === 'background' ? self::BACKGROUNDS : self::SIDEBARS)[$key] ?? $key;
    }

    private static function apply(array $values, array $data, array $fields): array
    {
        foreach ($fields as $f) {
            if (! array_key_exists($f, $data)) {
                continue;
            }
            $value = is_string($data[$f]) ? trim($data[$f]) : $data[$f];

            if ($value === null || $value === '') {
                unset($values[$f]);
            } else {
                $values[$f] = $value;
            }
        }

        return $values;
    }
}
