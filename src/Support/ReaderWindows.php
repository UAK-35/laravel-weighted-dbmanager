<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Support;

/**
 * What `swrr.reader_windows` may contain, and what happens to an entry that is not
 * that.
 *
 * The accepted shape is one array per window — `['start' => '10:00:00', 'end' =>
 * '14:20:00']`, either key optional. The natural way to write the same thing is the
 * flat string `'10:00-14:20'`, and that is not a window. It used to be dropped
 * without a word, which leaves the resolver with an empty list and therefore in its
 * permissive mode: reads use the replica pool at every hour, the opposite of the
 * fallback the setting describes.
 *
 * Silent inversion is the one outcome worse than a refusal, so entries that are not
 * arrays are returned as *rejected* — named, with the shape they should have had —
 * and both the boot audit (at error level) and `db:doctor` report them. Nothing here
 * decides policy: the callers do, and they read the same fields.
 *
 * @phpstan-type Rejection array{at: string, entry: string}
 * @phpstan-type Window array{start?: string, end?: string}
 */
final class ReaderWindows
{
    /**
     * The shape an entry has to have, in the words every report uses — one sentence,
     * so a log line and a `db:doctor` row cannot describe the same rule differently.
     */
    public const ACCEPTED = "each window must be an array like ['start' => '10:00:00', 'end' => '14:20:00'] — a string such as '10:00-14:20' is not a window";

    /**
     * Split the setting into the windows that can be used and the entries that cannot.
     *
     * `shape` is set when the setting as a whole is not a list at all — `'10:00-14:20'`
     * instead of `['10:00-14:20']` — which is the same mistake one level up: the value
     * is present, and it is not something that can be read as windows.
     *
     * @param mixed $value the raw `swrr.reader_windows` value
     * @return array{usable: list<Window>, rejected: list<Rejection>, shape: string|null}
     */
    public static function split(mixed $value): array
    {
        if ($value !== null && !is_array($value)) {
            return [
                'usable' => [],
                'rejected' => [],
                'shape' => self::describe($value),
            ];
        }

        $usable = [];
        $rejected = [];

        foreach (is_array($value) ? $value : [] as $index => $window) {
            if (!is_array($window)) {
                $rejected[] = [
                    'at' => is_int($index) ? '['.$index.']' : '["'.$index.'"]',
                    'entry' => self::describe($window),
                ];

                continue;
            }

            /** @var Window $entry */
            $entry = [];

            $start = $window['start'] ?? null;
            $end = $window['end'] ?? null;

            if (is_scalar($start)) {
                $entry['start'] = (string) $start;
            }

            if (is_scalar($end)) {
                $entry['end'] = (string) $end;
            }

            $usable[] = $entry;
        }

        return ['usable' => $usable, 'rejected' => $rejected, 'shape' => null];
    }

    /**
     * The rejected entries as `reader_windows[0] is "10:00-14:20"`, joined for a
     * one-line report.
     *
     * @param list<Rejection> $rejected
     */
    public static function describeRejected(array $rejected): string
    {
        return implode(', ', array_map(
            static fn (array $entry): string => 'reader_windows'.$entry['at'].' is '.$entry['entry'],
            $rejected,
        ));
    }

    /**
     * The setting written the way it is read, for a report to print as the repair — or
     * null when a refused piece cannot be re-spelled without guessing at what was meant.
     *
     * This is a suggestion and never a repair. The value stays refused, the boot still
     * logs it and `db:doctor` still fails on it; what is printed is only what the two
     * mistakes a person actually makes reduce to — a range that carries both bounds
     * (`'10:00-14:20'`), and a single window written without its outer list. Both are the
     * spellings this setting's own sentence asks for, so the line says "write it like
     * this" rather than "this is what you meant".
     *
     * Everything else gets no suggestion, and that is the same line the refusal itself
     * draws: a range the resolver cannot express (an overnight one, where the start is not
     * before the end), a string that is not a range at all, and a bound the resolver cannot
     * parse are all values the package will not interpret — and a wrong guess pasted into
     * configuration routes reads the wrong way round, which is the outcome the refusal
     * exists to prevent. An overnight range is the pointed case: wrapping it would hand
     * back a window that can never be entered, trading a refusal for a warning.
     *
     * @param mixed $value the raw `swrr.reader_windows` value
     */
    public static function suggestion(mixed $value): ?string
    {
        // A single map is the one-character mistake the classifier refuses entry by entry:
        // the window is written without its list, so the repair is to put the list back.
        if (is_array($value) && !array_is_list($value)) {
            return self::boundsOf($value) === null ? null : self::literal([$value]);
        }

        // The whole value is one flat range.
        if (!is_array($value)) {
            $window = self::flatWindow($value);

            return $window === null ? null : self::literal([$window]);
        }

        $windows = [];

        foreach ($value as $entry) {
            // As written: a well-formed entry is not this suggestion's subject, and
            // re-spelling it would be an edit nobody asked for.
            if (is_array($entry)) {
                $windows[] = $entry;

                continue;
            }

            $window = self::flatWindow($entry);

            if ($window === null) {
                return null;
            }

            $windows[] = $window;
        }

        return self::literal($windows);
    }

    /**
     * A value as it would be written in configuration: one line, short array syntax, string
     * keys and string values quoted.
     *
     * Shared with `ReaderDays`, for the same reason `describe()` is: a repair spelled one
     * way for one setting and another way for the setting beside it would be two spellings
     * of the same rule. One line, because a report has one line per thing it says.
     */
    public static function literal(mixed $value): string
    {
        if (!is_array($value)) {
            return match (true) {
                is_string($value) => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'",
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => 'null',
                is_int($value), is_float($value) => (string) $value,
                // An object or a resource cannot be configuration; naming it keeps the
                // line from printing something that is not PHP at all.
                default => get_debug_type($value),
            };
        }

        $list = array_is_list($value);
        $entries = [];

        foreach ($value as $key => $entry) {
            $rendered = self::literal($entry);

            $entries[] = $list ? $rendered : self::literal($key).' => '.$rendered;
        }

        return '['.implode(', ', $entries).']';
    }

    /**
     * The flat range as a window, or null when the string is not one this resolver can
     * enter: both bounds have to parse and the start has to be strictly before the end,
     * because the end is exclusive and within the same day.
     *
     * @return Window|null
     */
    private static function flatWindow(mixed $value): ?array
    {
        if (!is_string($value)) {
            return null;
        }

        $parts = explode('-', $value);

        if (count($parts) !== 2) {
            return null;
        }

        $start = self::time(trim($parts[0]));
        $end = self::time(trim($parts[1]));

        if ($start === null || $end === null || $start >= $end) {
            return null;
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Whether a window array holds bounds the resolver can read and can be entered at all.
     * A missing bound is not a problem — the resolver defaults it to a whole day — but a
     * bound it would normalise to midnight behind the operator's back, or a start that is
     * not before its end, is.
     *
     * @param array<mixed> $window
     * @return array{0: string, 1: string}|null
     */
    private static function boundsOf(array $window): ?array
    {
        $start = $window['start'] ?? null;
        $end = $window['end'] ?? null;

        $from = $start === null ? '00:00:00' : self::bound($start);
        $to = $end === null ? '23:59:59' : self::bound($end);

        if ($from === null || $to === null || $from >= $to) {
            return null;
        }

        return [$from, $to];
    }

    private static function bound(mixed $value): ?string
    {
        return is_scalar($value) ? self::time(trim((string) $value)) : null;
    }

    /**
     * A bound as the resolver reads it — `HH:MM` or `HH:MM:SS`, normalised to `HH:MM:SS`
     * so the comparison is lexical and the suggestion is in the spelling the setting's
     * sentence asks for. Null for anything else, which the resolver would silently read as
     * midnight.
     */
    private static function time(string $value): ?string
    {
        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            return $value.':00';
        }

        return preg_match('/^\d{2}:\d{2}:\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * A value as it reads in a report: strings quoted, other types named.
     *
     * Shared with `ReaderDays`, which describes its own entries through it, so a value
     * cannot be quoted one way in a windows report and another way in a days report —
     * including the comma-separated string both settings refuse.
     */
    public static function describe(mixed $value): string
    {
        if (is_string($value)) {
            return '"'.$value.'"';
        }

        if (is_array($value)) {
            return 'an array of '.(count($value) === 1 ? 'one entry' : count($value).' entries');
        }

        return get_debug_type($value);
    }
}
