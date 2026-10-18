<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Support;

/**
 * ConfigSchema — the generation of the published config's key set, and the sentences a copy that is
 * behind it is reported with.
 *
 * WHY A NUMBER RATHER THAN A VERSION
 * ----------------------------------
 *   `config/db-manager.php` is published *into* an application and then edited there, so the copy a
 *   running installation has is frozen at the moment it was published. That is the point of
 *   publishing it — the installer's values are theirs and no install may write over them — and it is
 *   also the failure this class exists for: a key added to the sample after that moment is a key
 *   nothing publishes into the file that already exists, so the installation keeps working on the
 *   key set it had and reads the *default* for everything added since. Nothing is malformed, no row
 *   fails, and the package behaves as the older release did while the changelog describes one that
 *   behaves differently.
 *
 *   A version cannot answer this. `v0.2.0-alpha3` names a release and says nothing about what is in
 *   a file somebody may have edited thirty times since publishing it from `v0.2.0-alpha1` — and the
 *   one question worth asking is exactly whether the file's *keys* are current, not whether a
 *   composer constraint is. So the sample carries one whole number, `KEY`, raised when the key set
 *   changes rather than when the code does, and a published copy declares the generation it was
 *   published from.
 *
 * WHAT THE NUMBER IS NOT
 * ----------------------
 *   It is not a version of this package, not a claim about the file's values (a copy at the current
 *   generation is a copy whose *keys* are current, whatever it says in them), and not something an
 *   installation has to keep in step by hand: publishing the sample is what writes it, and the value
 *   an installer edits is the one they choose to edit.
 *
 * HOW IT IS READ
 * --------------
 *   {@see of()} takes whatever a config file yields — the array a `require` of the published file
 *   returns, or a slice of it — and answers with both halves of the reading: the generation it
 *   declares, and the raw value it declared it with. The raw half matters because a file that
 *   declares `'generation' => 'five'` is a different fault from one that declares nothing at all,
 *   and a row that reported the first as the second would send an installer to republish a file
 *   whose problem is the value in it.
 *
 *   `null` for the generation means the file carries no such key — which is the shape of *every*
 *   file published before the key existed, and is reported as the file predating it rather than as
 *   an error. A file that is current is silent: the row that reads this has already said whether
 *   the file exists and whether anything in it was reviewed, and this reading only ever adds.
 */
final class ConfigSchema
{
    /**
     * The top-level key a `config/db-manager.php` declares its generation under.
     *
     * Top-level rather than inside `swrr`, deliberately: the generation is a fact about the *file*
     * — which keys are in it — and not about any one block's settings, so a key that lived inside a
     * block would move when the block was reorganised and would read as unset for every file
     * published before the block existed.
     */
    public const KEY = 'generation';

    /**
     * The generation this package's sample is — the number `config/db-manager.php` ships.
     *
     * Raised when the sample's *key set* changes — a key added, removed or moved — and not for a
     * changed default, a reworded comment or a new env var behind an existing key, because none of
     * those is something a published copy has to be republished to keep working. `RELEASED_IN` is
     * raised with it.
     */
    public const GENERATION = 2;

    /**
     * The release that introduced each generation, for the warning that names one.
     *
     * A generation is introduced by the release that first ships its key set, and that is the
     * sentence an installer needs: not "your file is old" but "your file was published before
     * v0.2.0, which added `stale_after_missed_runs`". A version written here before it is cut is the
     * plan the notes weigh rather than a tag a consumer can install, which is why
     * {@see release()} says so when the release is still ahead — the alternative is a doctor call
     * that refuses to name anything until the release it is reporting on is a tag.
     *
     * @var array<int, string>
     */
    public const RELEASED_IN = [
        // The key set the row reads was first published by alpha5: the generation itself, and the
        // schedule watchdog's `schedule.stale_after_missed_runs`. The key set v0.2.0 ships adds
        // the repair rate's two settings beside it, which is generation 2.
        1 => 'v0.2.0-alpha5',
        2 => 'v0.2.0',
    ];

    /** Not instantiable — a utility class with only static methods over config values. */
    private function __construct()
    {
    }

    /**
     * The generation a config file declares, and the value it declared it with.
     *
     * `present` is a field of its own rather than something the caller infers from `raw`, and that is
     * the whole reason it is here: `['generation' => null]` carries the key with a value nobody can
     * read as a number, which is a *different* file from one that carries no key — a value to fix
     * rather than a copy to republish — and `raw === null` cannot tell the two apart.
     *
     *   `['present' => true,  'declared' => 1,    'raw' => 1]`      — declares a generation this reads
     *   `['present' => false, 'declared' => null, 'raw' => null]`   — no such key (published before it)
     *   `['present' => true,  'declared' => null, 'raw' => 'five']` — the key, with a value this cannot
     *                                                                read as a whole number
     *
     * A numeric string is read as a number, because an `env('SWRR_CONFIG_GENERATION')` declaration in
     * a published file yields one — the spelling the sample's own comment offers an installer — and
     * refusing it would make every copy that used it unreadable.
     *
     * @return array{present: bool, declared: int|null, raw: mixed}
     */
    public static function of(mixed $config): array
    {
        if (!is_array($config) || !array_key_exists(self::KEY, $config)) {
            return ['present' => false, 'declared' => null, 'raw' => null];
        }

        $raw = $config[self::KEY];

        if (is_int($raw)) {
            return ['present' => true, 'declared' => $raw, 'raw' => $raw];
        }

        if (is_string($raw) && preg_match('/^-?\d+$/', trim($raw)) === 1) {
            return ['present' => true, 'declared' => (int) trim($raw), 'raw' => $raw];
        }

        return ['present' => true, 'declared' => null, 'raw' => $raw];
    }

    /**
     * What a file's own generation is worth saying, or null when it is this package's.
     *
     * One method for the whole reading so the row that prints it cannot pick two of the three cases
     * and drop the third: a caller that took "stale" and "ahead" and forgot "unreadable" would
     * report a file declaring `'generation' => 'five'` as current — the one shape the reading exists
     * to keep out of the silence.
     *
     * @param array{present: bool, declared: int|null, raw: mixed} $reading the answer {@see of()} gave
     */
    public static function sentence(array $reading): ?string
    {
        $declared = $reading['declared'];
        $raw = $reading['raw'];

        if (!$reading['present']) {
            return sprintf(
                'the file declares no %s: it was published before the key existed (%s), so every key '
                .'added to the sample since is read from its default — republish with --force and '
                .'review the new values',
                self::KEY,
                self::release(self::GENERATION),
            );
        }

        if ($declared === null) {
            return sprintf(
                'the file declares %s = %s, which is not a whole number, so the key set it holds '
                .'cannot be placed against this package\'s (%s)',
                self::KEY,
                is_scalar($raw) ? var_export($raw, true) : get_debug_type($raw),
                self::GENERATION,
            );
        }

        if ($declared < self::GENERATION) {
            return sprintf(
                'the file is generation %d (%s) and this package is generation %d (%s): the keys '
                .'added since are read from their defaults — republish with --force and review them',
                $declared,
                self::release($declared),
                self::GENERATION,
                self::release(self::GENERATION),
            );
        }

        if ($declared > self::GENERATION) {
            return sprintf(
                'the file is generation %d (%s) and this package is generation %d: it was published '
                .'from a newer release than this installation runs, so the extra keys are ignored',
                $declared,
                self::release($declared),
                self::GENERATION,
            );
        }

        return null;
    }

    /**
     * The release a generation was introduced in, or the sentence for one that is still ahead.
     *
     * A generation this package ships but no version carries yet — the state every generation is in
     * between the commit that raises it and the tag that publishes it — is named as the version the
     * notes weigh rather than as a release, because calling an uncut version a released one is the
     * claim a consumer acts on.
     */
    public static function release(int $generation): string
    {
        $version = self::RELEASED_IN[$generation] ?? null;

        return $version === null
            ? sprintf('generation %d, a version no tag carries yet', $generation)
            : $version;
    }
}
