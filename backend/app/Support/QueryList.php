<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * A filter that can hold several values.
 *
 * The list screens filter with checkbox dropdowns: every box ticked sends
 * nothing, some ticked sends those values as `key[]=a&key[]=b`, and none
 * ticked sends a value that matches no row. Older callers send one plain
 * value, or a comma-separated string. This reads all of them the same way.
 */
final class QueryList
{
    /**
     * The values asked for, or null when the filter is not applied.
     *
     * @return list<string>|null
     */
    public static function of(Request $request, string $key): ?array
    {
        $raw = $request->query($key);

        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        $values = is_array($raw) ? $raw : explode(',', (string) $raw);
        $values = array_values(array_unique(array_filter(
            array_map(fn ($v) => trim((string) (is_scalar($v) ? $v : '')), $values),
            fn (string $v) => $v !== '',
        )));

        return $values === [] ? null : $values;
    }

    /**
     * The same, as whole numbers - for id filters. A value that is not a
     * number (the "nothing" marker included) becomes 0, which matches no row.
     *
     * @return list<int>|null
     */
    public static function ids(Request $request, string $key): ?array
    {
        $values = self::of($request, $key);

        return $values === null ? null : array_map(fn (string $v) => ctype_digit($v) ? (int) $v : 0, $values);
    }
}
