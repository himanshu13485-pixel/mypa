<?php

namespace App\Support;

/**
 * The backgrounds and sidebars on offer.
 *
 * The drawing lives in the client (frontend/src/lib/backgrounds.ts), where the
 * gradients and SVG tiles are; the server only needs the names, so it can
 * refuse one it does not know rather than store a value no screen can draw.
 * The two lists have to agree.
 */
class Appearance
{
    /** @var array<string, string> key => label, as the announcement names it */
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

    public static function backgroundKeys(): array
    {
        return array_keys(self::BACKGROUNDS);
    }

    public static function sidebarKeys(): array
    {
        return array_keys(self::SIDEBARS);
    }
}
