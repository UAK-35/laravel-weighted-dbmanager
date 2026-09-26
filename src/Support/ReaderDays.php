<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Support;

/**
 * What `swrr.reader_days` may contain, and what happens to an entry that is not that.
 *
 * Day numbers are lenient on purpose: `.env` values arrive as strings, and `(array)
 * '3'` used to produce `['3']`, which never matched the resolver's integer
 * comparison and silently pushed every read onto the writer. A bare scalar now means
 * that single day, and a numeric string is the number it spells.
 *
 * Everything else is *refused* rather than dropped, one key over from the same
 * decision for `reader_windows`. `'1,2,3'` is a list written as one string — the way
 * a list is written in `.env`, and not the way this setting is read — and dropping it
 * leaves a restrictive setting reading as its opposite: with no day left, the
 * resolver is permissive and reads use the replica pool all week. An entry that
 * cannot be a day is named, with the shape it should have had, so the boot audit (at
 * error level) and `db:doctor` describe the same mistake the same way.
 *
 * Two questions are asked of the result, and both belong here rather than at each
 * call site: which days the setting produced, and which of them are days the resolver
 * can match at all. `db:doctor` asks the second question too, so a row and the boot
 * audit cannot disagree about whether a configuration can ever put a read on a
 * replica.
 *
 * @phpstan-type Rejection array{at: string, entry: string}
 */
final class ReaderDays
{
    /**
     * The shape an entry has to have, in the words every report uses — one sentence,
     * so a log line and a `db:doctor` row cannot describe the same rule differently.
     */
    public const ACCEPTED = "each day must be an ISO-8601 day number, 1 = Monday … 7 = Sunday — a list written as one string such as '1,2,3' is not one";

    /**
     * Split the setting into the days that can be used and the entries that cannot.
     *
     * `shape` is set when the setting as a whole is not something that can be read as
     * days — which is where the comma-separated list lands: the value is present, and
     * it is one string rather than a list of day numbers. A bare `'3'` stays accepted,
     * because a single day can be read exactly one way; a list cannot be read at all
     * without deciding what a list of days is spelled like, and that decision is not
     * the package's to make.
     *
     * @param mixed $value the raw `swrr.reader_days` value
     * @return array{usable: list<int>, rejected: list<Rejection>, shape: string|null}
     */
    public static function split(mixed $value): array
    {
        if ($value !== null && !is_array($value)) {
            $day = self::dayNumber($value);

            return $day === null
                ? ['usable' => [], 'rejected' => [], 'shape' => ReaderWindows::describe($value)]
                : ['usable' => [$day], 'rejected' => [], 'shape' => null];
        }

        $usable = [];
        $rejected = [];

        foreach (is_array($value) ? $value : [] as $index => $day) {
            $number = self::dayNumber($day);

            if ($number === null) {
                $rejected[] = [
                    'at' => is_int($index) ? '['.$index.']' : '["'.$index.'"]',
                    'entry' => ReaderWindows::describe($day),
                ];

                continue;
            }

            $usable[] = $number;
        }

        return ['usable' => $usable, 'rejected' => $rejected, 'shape' => null];
    }

    /**
     * The days a setting produces: the entries of `split()` that are day numbers, in
     * the order they were written. Entries that are not are left out here and reported
     * from `split()` — the callers that only route reads need this half, and the ones
     * that report need both.
     *
     * @return list<int>
     */
    public static function normalise(mixed $value): array
    {
        return self::split($value)['usable'];
    }

    /**
     * The rejected entries as `reader_days[1] is "1,2,3"`, joined for a one-line report.
     *
     * @param list<Rejection> $rejected
     */
    public static function describeRejected(array $rejected): string
    {
        return implode(', ', array_map(
            static fn (array $entry): string => 'reader_days'.$entry['at'].' is '.$entry['entry'],
            $rejected,
        ));
    }

    /**
     * The setting written the way it is read — the days it names, once each, in the order
     * they first appear — or null when a refused entry cannot be re-spelled without
     * guessing.
     *
     * `'1,2,3'` is the one spelling this reduces. It is the `.env` way of writing a list,
     * every comma-separated piece of it is already a day number, and the sentence the row
     * prints asks for exactly the array that comes out — so printing it is the repair
     * rather than a symptom. Nothing else is interpreted: an entry that is not a day
     * number and not a list of them (`'mon'`, a nested array, a name) stops the suggestion,
     * because a day list that has been read the wrong way round sends reads somewhere the
     * operator did not choose, and that is the failure the refusal exists to prevent.
     *
     * The value is left refused: this is what `db:doctor` prints, not what the package
     * applies to the configuration it was given.
     *
     * @param mixed $value the raw `swrr.reader_days` value
     */
    public static function suggestion(mixed $value): ?string
    {
        $entries = is_array($value) ? array_values($value) : ($value === null ? [] : [$value]);

        $days = [];

        foreach ($entries as $entry) {
            $day = self::dayNumber($entry);

            if ($day !== null) {
                $days[] = $day;

                continue;
            }

            $named = self::tokens($entry);

            if ($named === []) {
                return null;
            }

            foreach ($named as $token) {
                $days[] = $token;
            }
        }

        return $days === []
            ? null
            // A day list is a membership test, so the same day twice is the same day: the
            // suggestion is the days it names, not the entries it was written as.
            : ReaderWindows::literal(array_values(array_unique($days)));
    }

    /**
     * A comma-separated string read as the days it names, or an empty list when it is not
     * one — every token has to be a day number by the same rule a single entry is read by,
     * so the tokens cannot be read one way here and another way in an entry.
     *
     * @return list<int>
     */
    private static function tokens(mixed $value): array
    {
        if (!is_string($value) || !str_contains($value, ',')) {
            return [];
        }

        $days = [];

        foreach (explode(',', $value) as $token) {
            $day = self::dayNumber(trim($token));

            if ($day === null) {
                return [];
            }

            $days[] = $day;
        }

        return $days;
    }

    /**
     * The subset the resolver can match: days outside 1…7 can never be a reader day.
     *
     * @param list<int> $days
     * @return list<int>
     */
    public static function inIsoRange(array $days): array
    {
        return array_values(array_filter(
            $days,
            static fn (int $day): bool => $day >= 1 && $day <= 7,
        ));
    }

    /**
     * An entry as a day number, or null when it cannot be one. Ints and numeric strings
     * are the accepted spellings, which is what `.env` produces; everything else — a
     * name, a boolean, a nested list, and a list written as one string — is not a day.
     */
    private static function dayNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : null;
    }
}
