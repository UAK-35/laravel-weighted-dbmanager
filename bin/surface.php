<?php

declare(strict_types=1);

/**
 * bin/surface.php — the shared half of every bin/ command that reads this package.
 *
 * WHAT A SURFACE IS
 * -----------------
 *   A surface is what a consumer of this package can name: the classes,
 *   interfaces, traits and enums a file declares, and of each one the public
 *   methods, constants, enum cases and properties — plus, for a config file, the
 *   keys it returns and the env vars it reads. Every command needs it: the release
 *   script weighs it against the last tag, bin/blame.php asks whether it holds one
 *   name, and the inventory writes it down.
 *
 *   The reading is deliberately syntactic, from tokens rather than a semantic
 *   model. A changed parent class is not reported, and a member marked `@internal`
 *   in a docblock still counts. Both directions lean the same way: a surface read
 *   as changed costs a bump, while one read as unchanged costs a consumer a broken
 *   release.
 *
 * WHAT LIVES HERE
 * ---------------
 *     levels      the order patch < minor < breaking that everything compares on
 *     reading     a surface out of the working tree, or out of a tag, via git
 *     diffing     two surfaces compared, and a symbol key turned into a sentence
 *     inventory   files.tsv, methods.tsv and surface.tsv: their format, their reader,
 *                 their writer
 *
 * NO SHEBANG, ON PURPOSE
 * ----------------------
 *   This file is only ever included, never run. PHP does not strip a shebang from
 *   an included file — it prints it — and bin/release.php writes a plan a human
 *   reads, so one stray line at the top would be a bug in every release.
 */

// ─────────────────────────────────────────────────────────────────────────────
// Levels
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The order of the levels weighing and the gate compare on. `major` and
 * `breaking` are one rung said two ways: a breaking change is what asks for a
 * major, and `--major` is how somebody declares one.
 */
function severityRank(string $level): int
{
    return match ($level) {
        'breaking', 'major' => 2,
        'minor' => 1,
        default => 0,
    };
}

// ─────────────────────────────────────────────────────────────────────────────
// Reading the repository
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Run git in the package root. The command is an argument list rather than a
 * shell string: no quoting rules to get wrong on Windows, and no shell profile
 * to depend on.
 *
 * @param list<string> $arguments
 * @return array{exit: int, output: string}
 */
function git(string $root, array $arguments): array
{
    $process = @proc_open(
        ['git', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes,
        $root,
    );

    if (!is_resource($process)) {
        return ['exit' => 127, 'output' => 'git could not be started'];
    }

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return ['exit' => proc_close($process), 'output' => $output];
}

/**
 * @return list<string>
 */
function lines(string $output): array
{
    return array_values(array_filter(
        array_map('trim', explode("\n", $output)),
        static fn (string $line): bool => $line !== '',
    ));
}

/**
 * Every .php file under a directory, sorted, dot-directories skipped.
 *
 * @return list<string>
 */
function phpFilesUnder(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $entry): bool => !$entry->isDir()
                || !str_starts_with($entry->getFilename(), '.'),
        ),
    );

    $files = [];

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
    }

    sort($files);

    return $files;
}

/**
 * A path as the inventory spells it: relative to the package root, forward
 * slashes, so a row reads the same on Windows and on Linux.
 */
function relativeTo(string $root, string $path): string
{
    $path = str_replace('\\', '/', $path);

    return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
}

/**
 * The most recent v-prefixed release tag that is an **ancestor of HEAD**, or null
 * when this branch has none — a fresh package whose first release is still ahead
 * of it.
 *
 * Ancestry is the question, not a detail of it: the base is the version the branch
 * being released actually contains. A tag on a branch HEAD cannot reach is a real
 * tag and still not one of this line's — reading it as the base measures the bump
 * against a release this line never made, and the range `vX.Y.Z..HEAD` then walks a
 * history the tag was never part of.
 *
 * `git describe` already reads only reachable tags, which is what makes it the base.
 * Its fallback did not: `git tag --list` sees every tag in the repository whatever
 * HEAD can reach, so a branch with no tag of its own took the highest tag anywhere —
 * including a dev tag cut on a side branch. Both paths now ask the same question.
 */
function latestTag(string $root): ?string
{
    $result = git($root, ['describe', '--tags', '--abbrev=0', '--match', 'v[0-9]*']);

    if ($result['exit'] !== 0) {
        $result = git($root, ['tag', '--merged', 'HEAD', '--list', 'v[0-9]*', '--sort=-v:refname']);
    }

    $tag = trim(strtok($result['output'], "\n") ?: '');

    return $tag === '' ? null : $tag;
}

// ─────────────────────────────────────────────────────────────────────────────
// Surfaces
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The surface of a directory as a tag holds it — read out of git rather than out
 * of the checkout, so an uncommitted edit cannot be mistaken for a released one.
 *
 * @param callable(string): array<string, string> $parse
 * @param array<string, list<string>>|null $declaredBy filled in by mergeSurface(), one
 *        entry per symbol naming the files that declare it, when the caller asks
 * @return array<string, string>
 */
function taggedSurface(string $root, string $tag, string $directory, callable $parse, ?array &$declaredBy = null): array
{
    $listing = git($root, ['ls-tree', '-r', '--name-only', $tag, '--', $directory]);

    if ($listing['exit'] !== 0) {
        return [];
    }

    $surface = [];

    foreach (lines($listing['output']) as $path) {
        if (!str_ends_with($path, '.php')) {
            continue;
        }

        $blob = git($root, ['show', $tag . ':' . $path]);

        if ($blob['exit'] !== 0) {
            continue;
        }

        $surface = mergeSurface($surface, $parse($blob['output']), $path, $declaredBy);
    }

    return $surface;
}

/**
 * The surface of a directory as it sits on disk.
 *
 * @param callable(string): array<string, string> $parse
 * @param array<string, list<string>>|null $declaredBy filled in by mergeSurface(), one
 *        entry per symbol naming the files that declare it, when the caller asks
 * @return array<string, string>
 */
function workingSurface(string $directory, callable $parse, ?array &$declaredBy = null): array
{
    $surface = [];

    foreach (phpFilesUnder($directory) as $path) {
        $content = @file_get_contents($path);

        if ($content === false) {
            continue;
        }

        $surface = mergeSurface($surface, $parse($content), $path, $declaredBy);
    }

    return $surface;
}

/**
 * One file's surface folded into another's — the map's `+=`, with the collision said out
 * loud when the caller asks for it.
 *
 * The surface is keyed by name, so a name two files declare is one entry here and the
 * first file read is the one it came from. That is right for the map, and it is silent in
 * a way that matters, because the two declarations are not always the same thing: a config
 * key read by two files is `timezone` in one file and `timezone` in the next, one entry in
 * this surface, and two distinct keys at runtime — `app.timezone` and `db-manager.timezone`.
 * A change to the second file's key is then a change the tag diff cannot see: the name is
 * still in both surfaces, from the file that did not change.
 *
 * So the drop is *reported* rather than left implicit, and the caller decides how loudly.
 * The weighing turns it into a plan note, because the surface it diffs is the surface this
 * narrows; `bin/blame.php` asks about the names it was given, and `bin/inventory.php` passes
 * nothing and reads the map alone. The inventory is the one artefact that keeps both: its
 * rows carry the file, so a symbol two files declare is two rows there, which is why the
 * note can point at it.
 *
 * @param array<string, list<string>>|null $declaredBy out: symbol => the files declaring it,
 *        one entry per symbol the merge saw. Collisions are the entries with two or more,
 *        which is what the caller filters for — recording the single-file case too is what
 *        lets the report name the file that *won*, not merely the ones that lost.
 */
function mergeSurface(array $surface, array $declares, string $path, ?array &$declaredBy): array
{
    foreach ($declares as $symbol => $description) {
        if ($declaredBy !== null) {
            $declaredBy[$symbol] = [...($declaredBy[$symbol] ?? []), $path];
        }

        if (array_key_exists($symbol, $surface)) {
            continue;
        }

        $surface[$symbol] = $description;
    }

    return $surface;
}

/**
 * The public surface of one PHP file, as a flat map of symbol => description.
 *
 * Only what a consumer can name is kept: the classes, interfaces, traits and
 * enums a file declares, and of each one the public methods, constants, cases and
 * properties. Protected and private members are skipped, and so is everything
 * inside a function body, because a closure is not an API.
 *
 * @return array<string, string>
 */
function fileSurface(string $source): array
{
    $tokens = @token_get_all($source);
    $total = count($tokens);
    $surface = [];
    $namespace = '';
    $depth = 0;
    $parens = 0;
    $scopes = [];      // brace depth => the class-like FQN it belongs to, or null when anonymous
    $pending = null;   // a class-like declaration waiting for the brace that opens its body
    $pendingSet = false;

    for ($i = 0; $i < $total; $i++) {
        $token = $tokens[$i];

        if (is_string($token)) {
            if ($token === '{') {
                $depth++;

                if ($pendingSet) {
                    $scopes[$depth] = $pending;
                    $pendingSet = false;
                }

                continue;
            }

            if ($token === '}') {
                unset($scopes[$depth]);
                $depth--;

                continue;
            }

            if ($token === '(') {
                $parens++;
            } elseif ($token === ')') {
                $parens--;
            }

            continue;
        }

        // A string interpolation opens with a token of its own — `{` or `${` — but
        // closes with a plain `}`, which is counted below. Counting only the plain
        // braces would walk the depth down once per interpolated string and hide
        // every member declared after the first one: silently, and in the direction
        // that under-reports a breaking change.
        if ($token[1] === '{' || $token[1] === '${') {
            $depth++;

            continue;
        }

        $id = $token[0];

        if ($id === T_NAMESPACE) {
            $namespace = namespaceAt($tokens, $i);

            continue;
        }

        if ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT || $id === T_ENUM) {
            // `Foo::class` is a constant read, not a declaration.
            if ($id === T_CLASS && previousMeaningful($tokens, $i) === T_DOUBLE_COLON) {
                continue;
            }

            $kind = match ($id) {
                T_INTERFACE => 'interface',
                T_TRAIT => 'trait',
                T_ENUM => 'enum',
                default => 'class',
            };

            $name = nextMeaningfulName($tokens, $i);
            $fqn = $name === null ? null : ($namespace === '' ? $name : $namespace . '\\' . $name);

            if ($fqn !== null) {
                $modifiers = [];

                // An interface or a trait cannot be final or abstract itself.
                if ($kind === 'class' || $kind === 'enum') {
                    if (hasModifierBefore($tokens, $i, T_ABSTRACT)) {
                        $modifiers[] = 'abstract';
                    }

                    if (hasModifierBefore($tokens, $i, T_FINAL)) {
                        $modifiers[] = 'final';
                    }
                }

                $surface['class:' . $fqn] = trim(implode(' ', [...$modifiers, $kind]));
            }

            $pending = $fqn;
            $pendingSet = true;

            continue;
        }

        // Everything below is a member of the class body we are directly in: one
        // brace deeper is a method body, where the names are locals.
        $innermost = array_key_last($scopes);
        $owner = $innermost === $depth ? $scopes[$depth] : null;

        if ($owner === null) {
            continue;
        }

        if ($id === T_FUNCTION) {
            $name = nextMeaningfulName($tokens, $i);

            if ($name === null || isPrivateEitherWay(visibilityBefore($tokens, $i))) {
                continue;
            }

            $surface['method:' . $owner . '::' . $name] = parameterShape($tokens, $i);

            continue;
        }

        if ($id === T_CASE) {
            $name = nextMeaningfulName($tokens, $i);

            if ($name !== null && !isPrivateEitherWay(visibilityBefore($tokens, $i))) {
                $surface['case:' . $owner . '::' . $name] = 'enum case';
            }

            continue;
        }

        if ($id === T_CONST) {
            if (isPrivateEitherWay(visibilityBefore($tokens, $i))) {
                continue;
            }

            foreach (constNames($tokens, $i) as $name) {
                $surface['const:' . $owner . '::' . $name] = 'public constant';
            }

            continue;
        }

        if ($id === T_VARIABLE) {
            // A promoted constructor property carries a visibility inside the
            // parameter list; a plain parameter is not a property at all, which is
            // why the parenthesis depth is part of the question.
            if (visibilityBefore($tokens, $i, $parens > 0) === 'public') {
                $surface['property:' . $owner . '::' . $token[1]] = 'public property';
            }
        }
    }

    return $surface;
}

/**
 * The config surface of one config file: the keys it returns and the env vars it
 * reads. An installation sets both, which is why the policy counts a removed one
 * as breaking.
 *
 * @return array<string, string>
 */
function configSurface(string $source): array
{
    $surface = [];

    if (preg_match_all('/^[ \t]*\'([^\']+)\'[ \t]*=>/m', $source, $matches) > 0) {
        foreach (array_unique($matches[1]) as $key) {
            $surface['config:' . $key] = 'config key';
        }
    }

    if (preg_match_all('/env\(\s*\'([^\']+)\'/', $source, $matches) > 0) {
        foreach (array_unique($matches[1]) as $name) {
            $surface['env:' . $name] = 'env var';
        }
    }

    return $surface;
}

/**
 * Whether a visibility means the member is not part of the surface. `null` is the
 * `public` an undeclared visibility means, which is what an interface method, an
 * enum case and a class constant all leave out.
 */
function isPrivateEitherWay(?string $visibility): bool
{
    return $visibility !== null && $visibility !== 'public';
}

/**
 * The visibility of the member declared at $index, read off the tokens before it.
 *
 * `$explicitOnly` is how a parameter is told from a property: a promoted property
 * carries a visibility, while a plain parameter reads as null rather than as the
 * public a missing modifier would otherwise imply.
 *
 * @return string|null 'public', 'protected', 'private', or null when no visibility is declared
 */
function visibilityBefore(array $tokens, int $index, bool $explicitOnly = false): ?string
{
    $readonly = false;

    for ($i = $index - 1; $i >= 0; $i--) {
        $token = $tokens[$i];

        if (is_array($token)) {
            switch ($token[0]) {
                case T_WHITESPACE:
                case T_COMMENT:
                case T_DOC_COMMENT:
                case T_STATIC:
                case T_FINAL:
                case T_ABSTRACT:
                    continue 2;
                case T_PUBLIC:
                    return 'public';
                case T_PROTECTED:
                    return 'protected';
                case T_PRIVATE:
                    return 'private';
                case T_VAR:
                    return $explicitOnly ? null : 'public';
                case T_READONLY:
                    // `readonly` alone means public; behind a visibility, that one wins.
                    $readonly = true;

                    continue 2;
                case T_STRING:
                case T_ARRAY:
                case T_CALLABLE:
                case T_NAME_QUALIFIED:
                case T_NAME_FULLY_QUALIFIED:
                case T_NAME_RELATIVE:
                    // A declared type sits between the visibility and the variable it
                    // modifies — `public string $label`, `private Foo $dep` — both in a class
                    // body and in a property promoted through a constructor's parameter list.
                    // Walking past it is what makes a *typed* property a member of the
                    // surface: the walk used to stop at the type name, so every property that
                    // declared one was invisible, to the tag diff as well as to the
                    // inventory. Nothing else can stand between a visibility and a variable,
                    // so this cannot walk past the member it started in.
                    continue 2;
                default:
                    return $readonly ? 'public' : null;
            }
        }

        if (trim($token) === '') {
            continue;
        }

        // The punctuation of a nullable, union or intersection type: `public ?string $label`,
        // `public int|string $x`. Reachable from a variable for the same reason a type name
        // is — there is nothing else it can be.
        if ($token === '?' || $token === '|' || $token === '&') {
            continue;
        }

        if ($token === ']') {
            $i = skipAttribute($tokens, $i);

            if ($i < 0) {
                break;
            }

            continue;
        }

        break;
    }

    return $readonly ? 'public' : null;
}

/**
 * Step back over a whole attribute group, so `#[Attr] public function` reads its
 * visibility from behind the attribute. Returns the index to continue from, or -1
 * when the group does not close.
 */
function skipAttribute(array $tokens, int $index): int
{
    $depth = 0;

    for ($i = $index; $i >= 0; $i--) {
        $token = $tokens[$i];

        if (is_array($token) && $token[0] === T_ATTRIBUTE) {
            $depth--;

            if ($depth <= 0) {
                return $i - 1;
            }

            continue;
        }

        $text = is_string($token) ? $token : $token[1];

        if ($text === ']') {
            $depth++;
        } elseif ($text === '[') {
            $depth--;

            if ($depth <= 0) {
                return $i - 1;
            }
        }
    }

    return -1;
}

/**
 * A method's parameter list as one comparable string: how many arguments it
 * requires of how many it takes, and the shape of each — enough to tell an added
 * required argument, which breaks every caller, from an added optional one.
 */
function parameterShape(array $tokens, int $index): string
{
    $total = count($tokens);
    $opened = false;
    $nested = 0;
    $current = '';
    $parts = [];

    for ($i = $index; $i < $total; $i++) {
        $token = $tokens[$i];

        if (is_array($token) && $token[0] === T_ATTRIBUTE) {
            $nested++;
            $current .= '#[';

            continue;
        }

        $text = is_string($token) ? $token : $token[1];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $text = ' ';
        }

        if (!$opened) {
            if ($text === '(') {
                // The opening parenthesis is the list itself, so it counts as the
                // first level of nesting — otherwise the `)` that closes an empty
                // list would leave the depth below zero and swallow the body.
                $opened = true;
                $nested = 1;
            }

            continue;
        }

        if (in_array($text, ['(', '[', '{'], true)) {
            $nested++;
            $current .= $text;

            continue;
        }

        if (in_array($text, [')', ']', '}'], true)) {
            $nested--;

            if ($text === ')' && $nested === 0) {
                $parts[] = $current;

                break;
            }

            $current .= $text;

            continue;
        }

        if ($text === ',' && $nested === 0) {
            $parts[] = $current;
            $current = '';

            continue;
        }

        $current .= $text;
    }

    $required = 0;
    $shape = [];

    foreach ($parts as $part) {
        $part = trim((string) preg_replace('/\s+/', ' ', $part));

        if ($part === '') {
            continue;
        }

        // The default value itself is not part of the shape; whether there is one is.
        $shape[] = (string) preg_replace('/\s*=.*$/s', '=', $part);
        $required += str_contains($part, '=') ? 0 : 1;
    }

    return sprintf('%d/%d %s', $required, count($shape), implode(',', $shape));
}

/**
 * The names a `const` statement declares — one statement can declare several.
 *
 * @return list<string>
 */
function constNames(array $tokens, int $index): array
{
    $total = count($tokens);
    $names = [];
    $expecting = true;

    for ($i = $index + 1; $i < $total; $i++) {
        $token = $tokens[$i];

        if (is_string($token)) {
            if ($token === ';' || $token === '{') {
                break;
            }

            if ($token === ',' && !$expecting) {
                $expecting = true;
            }

            continue;
        }

        if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        // A name is a bare word followed by `=`, which is what tells it from the
        // type of a typed constant.
        if ($expecting && $token[0] === T_STRING) {
            if (nextMeaningfulToken($tokens, $i) === '=') {
                $names[] = $token[1];
                $expecting = false;
            }

            continue;
        }

        $expecting = false;
    }

    return $names;
}

/**
 * The namespace declared at $index: its name, or '' for the block form
 * `namespace { ... }`.
 */
function namespaceAt(array $tokens, int $index): string
{
    $name = '';

    for ($i = $index + 1, $total = count($tokens); $i < $total; $i++) {
        $token = $tokens[$i];

        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($token[0] === T_STRING || $token[0] === T_NAME_QUALIFIED || $token[0] === T_NS_SEPARATOR) {
                $name .= $token[1];

                continue;
            }

            break;
        }

        if ($token === ';' || $token === '{') {
            break;
        }

        if ($token === '\\') {
            $name .= '\\';
        }
    }

    return trim($name, '\\');
}

/**
 * The name a class-like keyword or `function` declares, or null when there is
 * none — `new class` and a closure both have none.
 */
function nextMeaningfulName(array $tokens, int $index): ?string
{
    for ($i = $index + 1, $total = count($tokens); $i < $total; $i++) {
        $token = $tokens[$i];

        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token[0] === T_STRING ? $token[1] : null;
        }

        // `function &name()` — the reference is part of the declaration.
        if ($token === '&') {
            continue;
        }

        return null;
    }

    return null;
}

/**
 * @return int|string|null the token id, or the character of a single-token one
 */
function nextMeaningfulToken(array $tokens, int $index): int|string|null
{
    for ($i = $index + 1, $total = count($tokens); $i < $total; $i++) {
        $token = $tokens[$i];

        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token[0];
        }

        if (trim($token) === '') {
            continue;
        }

        return $token;
    }

    return null;
}

/**
 * @return int|string|null
 */
function previousMeaningful(array $tokens, int $index): int|string|null
{
    for ($i = $index - 1; $i >= 0; $i--) {
        $token = $tokens[$i];

        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token[0];
        }

        if (trim($token) === '') {
            continue;
        }

        return $token;
    }

    return null;
}

/**
 * Whether the modifiers before a class-like keyword include $modifier.
 */
function hasModifierBefore(array $tokens, int $index, int $modifier): bool
{
    for ($i = $index - 1; $i >= 0; $i--) {
        $token = $tokens[$i];

        if (!is_array($token)) {
            return false;
        }

        if ($token[0] === $modifier) {
            return true;
        }

        if (!in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_FINAL, T_ABSTRACT, T_READONLY], true)) {
            return false;
        }
    }

    return false;
}

// ─────────────────────────────────────────────────────────────────────────────
// Diffing
// ─────────────────────────────────────────────────────────────────────────────

/**
 * @param array<string, string> $before
 * @param array<string, string> $after
 * @return array{source: string, severity: string, summary: string, evidence: list<string>}
 */
function surfaceSignal(string $label, array $before, array $after): array
{
    $diff = surfaceDiff($before, $after);

    return [
        'source' => $label,
        'severity' => $diff['severity'],
        'summary' => $diff['evidence'] === []
            ? 'nothing removed, renamed or added since the last tag'
            : sprintf('%d change(s) to the surface since the last tag', count($diff['evidence'])),
        'evidence' => $diff['evidence'],
    ];
}

/**
 * What changed between two surfaces. A symbol that disappeared is breaking; one
 * that appeared is a minor; one whose shape changed is read from the shape.
 *
 * @param array<string, string> $before
 * @param array<string, string> $after
 * @return array{severity: string, evidence: list<string>}
 */
function surfaceDiff(array $before, array $after): array
{
    $severity = 'patch';
    $evidence = [];

    foreach ($before as $key => $description) {
        if (!array_key_exists($key, $after)) {
            $severity = 'breaking';
            $evidence[] = 'removed ' . describeSymbol($key);

            continue;
        }

        if ($after[$key] === $description) {
            continue;
        }

        $change = describeChange($key, $description, $after[$key]);

        if (severityRank($change['severity']) > severityRank($severity)) {
            $severity = $change['severity'];
        }

        $evidence[] = $change['text'];
    }

    foreach ($after as $key => $description) {
        if (array_key_exists($key, $before)) {
            continue;
        }

        if (severityRank('minor') > severityRank($severity)) {
            $severity = 'minor';
        }

        $evidence[] = 'added ' . describeSymbol($key);
    }

    return ['severity' => $severity, 'evidence' => $evidence];
}

/**
 * A symbol key as a sentence. The key is written for comparison, so this is where
 * it becomes something a person can act on.
 */
function describeSymbol(string $key): string
{
    [$type, $name] = array_pad(explode(':', $key, 2), 2, $key);

    return match ($type) {
        'class' => "class {$name}",
        'method' => "public method {$name}()",
        'const' => "public constant {$name}",
        'case' => "enum case {$name}",
        'property' => "public property {$name}",
        'config' => "config key '{$name}'",
        'env' => "env var {$name}",
        default => $key,
    };
}

/**
 * @return array{severity: string, text: string}
 */
function describeChange(string $key, string $before, string $after): array
{
    $symbol = describeSymbol($key);

    if (str_starts_with($key, 'method:')) {
        $wasRequired = shapeCounts($before)[0];
        $nowRequired = shapeCounts($after)[0];

        if ($nowRequired > $wasRequired) {
            return [
                'severity' => 'breaking',
                'text' => "{$symbol} needs {$nowRequired} required argument(s), was {$wasRequired}",
            ];
        }

        return ['severity' => 'minor', 'text' => "{$symbol} signature changed ({$before} -> {$after})"];
    }

    if (str_starts_with($key, 'class:')) {
        $kindBefore = classKind($before);
        $kindAfter = classKind($after);

        if ($kindBefore !== $kindAfter) {
            return ['severity' => 'breaking', 'text' => "{$symbol} is now {$kindAfter}, was {$kindBefore}"];
        }

        foreach (['final', 'abstract'] as $modifier) {
            if (str_contains($after, $modifier) && !str_contains($before, $modifier)) {
                return ['severity' => 'breaking', 'text' => "{$symbol} is now {$modifier}"];
            }
        }
    }

    return ['severity' => 'minor', 'text' => "{$symbol} changed ({$before} -> {$after})"];
}

/**
 * The required/total counts a method's shape description opens with.
 *
 * @return array{0: int, 1: int}
 */
function shapeCounts(string $shape): array
{
    preg_match('#^(\d+)/(\d+)#', $shape, $match);

    return [(int) ($match[1] ?? 0), (int) ($match[2] ?? 0)];
}

function classKind(string $description): string
{
    preg_match('/\b(class|interface|trait|enum)\b/', $description, $match);

    return $match[1] ?? 'class';
}

// ─────────────────────────────────────────────────────────────────────────────
// The inventory
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Three files at the package root, all TSV, all with the same shape: a comment
 * saying what it is and which tag it describes, a comment naming the columns, then
 * one record per line.
 *
 * TSV for two reasons and neither is speed — a record is `explode("\t", $line)` at
 * any size that matters here, and there is no quoting rule to get wrong the way
 * CSV has one. What earns it is review: one symbol per line means `git diff` shows
 * a rename as two lines a person can read, where JSON would rewrite the file.
 *
 * The stamp is what makes these safe to read. It says which release the rows
 * describe, so a file regenerated at the wrong moment is caught and ignored rather
 * than believed — an inventory in step with the tree cannot witness a change, and
 * that is the only thing it is for.
 *
 * @return array{files: string, methods: string, surface: string}
 */
function inventoryPaths(string $root): array
{
    return [
        'files' => $root . '/files.tsv',
        'methods' => $root . '/methods.tsv',
        'surface' => $root . '/surface.tsv',
    ];
}

/**
 * The tag an inventory written right now would describe: the latest release, or
 * `(no tag)` before the first one.
 */
function inventoryTag(string $root): string
{
    return latestTag($root) ?? '(no tag)';
}

/**
 * The tag a ref describes: the ref's own name when it is a tag, the release tag on the
 * commit it resolves to when it is not, and `(no tag)` when neither holds.
 *
 * The ref itself is asked first, so `--at=v0.2.0` stamps `v0.2.0` whatever else points
 * at that commit; a commit named by sha, a branch or `HEAD~3` is asked second, so the
 * rows of a tagged tree are still attributed to its release rather than to the way the
 * caller happened to name it. Anything else names no release, which is what a stamp of
 * `(no tag)` says — the same thing the working-tree reader says about a tree with no tag
 * at all, and the value the weighing compares against.
 *
 * The pattern is `v[0-9]*`, the one `latestTag()` reads with: a tag that is not a release
 * this package numbers is not a release to stamp a record with, however useful it is to
 * name one on the command line.
 */
function inventoryTagAt(string $root, string $ref): string
{
    if (git($root, ['rev-parse', '-q', '--verify', 'refs/tags/' . $ref])['exit'] === 0) {
        return $ref;
    }

    $described = git($root, ['describe', '--tags', '--exact-match', '--match', 'v[0-9]*', $ref]);

    if ($described['exit'] !== 0) {
        return '(no tag)';
    }

    $tag = trim(strtok($described['output'], "\n") ?: '');

    return $tag === '' ? '(no tag)' : $tag;
}

/**
 * The files the inventory covers: the package's own source and its sample config,
 * root-relative and sorted.
 *
 * @return list<string>
 */
function inventoryCovers(string $root): array
{
    $paths = [...phpFilesUnder($root . '/src'), ...phpFilesUnder($root . '/config')];

    $relative = array_map(static fn (string $path): string => relativeTo($root, $path), $paths);
    sort($relative);

    return $relative;
}

/**
 * The rows the inventory holds for the working tree. Thin on purpose: the body is
 * `inventoryRows()`, so this reader and the ref reader below cannot describe one tree
 * two ways.
 *
 * @return array{files: list<array{name: string, path: string, symbol: string}>, methods: list<array{method: string, file: string, class: string, signature: string}>, surface: list<array{kind: string, symbol: string, file: string}>}
 */
function inventoryRecords(string $root): array
{
    $sources = [];

    foreach (inventoryCovers($root) as $path) {
        $source = @file_get_contents($root . '/' . $path);

        if ($source !== false) {
            $sources[$path] = $source;
        }
    }

    return inventoryRows($sources);
}

/**
 * The same three lists for a ref — a tag, a branch or a commit — read out of git rather
 * than out of the checkout.
 *
 * This is what lets a tag with no written record be backfilled: the rows come from the
 * tree git holds for that ref, so they describe what that release published rather than
 * what the checkout happens to hold now. The stamp comes from the same ref, which is what
 * makes the pair right by construction: a hand regeneration of a moved-on tree stamped
 * with the latest tag describes neither tree, and every rule about a stamp exists to
 * refuse exactly that.
 *
 * An uncommitted edit is invisible here for the reason `taggedSurface()` reads the object
 * store as well: it is not something a release shipped, and describing it under a tag's
 * name is the mistake the stamp is for.
 *
 * @return array{files: list<array{name: string, path: string, symbol: string}>, methods: list<array{method: string, file: string, class: string, signature: string}>, surface: list<array{kind: string, symbol: string, file: string}>}
 */
function refInventoryRecords(string $root, string $ref): array
{
    return inventoryRows(refSources($root, $ref));
}

/**
 * The files the inventory reads as a ref holds them: relative path => bytes, in the shape
 * `inventoryCovers()` builds for the working tree.
 *
 * `ls-tree` then a `show` per file, the two calls `taggedSurface()` makes — and, like it,
 * a listing that fails (a ref this repository does not have) is no sources at all rather
 * than an error here: the caller answers for the ref before it asks, because an empty tree
 * written as an empty inventory is the one outcome worse than a refusal.
 *
 * A path under a dot-directory is skipped because `phpFilesUnder()` never descends into
 * one for the working tree: two readers that disagreed about which files a tree holds
 * would describe one tree two ways, and the whole point of the second reader is that the
 * two answer the same question about different trees.
 *
 * @return array<string, string>
 */
function refSources(string $root, string $ref): array
{
    $listing = git($root, ['ls-tree', '-r', '--name-only', $ref, '--', 'src', 'config']);

    if ($listing['exit'] !== 0) {
        return [];
    }

    $sources = [];

    foreach (lines($listing['output']) as $path) {
        if (!inventoryReads($path)) {
            continue;
        }

        $blob = git($root, ['show', $ref . ':' . $path]);

        if ($blob['exit'] === 0) {
            $sources[$path] = $blob['output'];
        }
    }

    ksort($sources);

    return $sources;
}

/**
 * Whether the inventory reads a path at all: a `.php` file that is not under a
 * dot-directory. The working-tree reader skips the directory and so does the ref reader.
 */
function inventoryReads(string $path): bool
{
    if (!str_ends_with($path, '.php')) {
        return false;
    }

    $segments = explode('/', $path);
    array_pop($segments);

    foreach ($segments as $segment) {
        if (str_starts_with($segment, '.')) {
            return false;
        }
    }

    return true;
}

/**
 * The rows one set of sources produces: the files the package ships, the public methods
 * they declare, and everything else a consumer can name — config keys, env vars, public
 * constants, enum cases and public properties.
 *
 * The `symbol` column is what stops a file rename from being mistaken for a move:
 * under PSR-4 the path and the class name are one fact, so a path that changed
 * while its symbol did not is the only kind of move that costs a consumer nothing.
 *
 * The third list is the one that exists for a tree with no tag to diff against. A
 * tag diff needs two tags and a method's removal is visible to it; a config key or a
 * constant removed since the inventory was written is visible to nothing but this,
 * because the public-API signal compares the tree to a tag and has no "before" of its
 * own when there is none.
 *
 * @param array<string, string> $sources relative path => bytes
 * @return array{files: list<array{name: string, path: string, symbol: string}>, methods: list<array{method: string, file: string, class: string, signature: string}>, surface: list<array{kind: string, symbol: string, file: string}>}
 */
function inventoryRows(array $sources): array
{
    $files = [];
    $methods = [];
    $named = [];

    foreach ($sources as $path => $source) {
        $surface = fileSurface($source);
        $symbol = '(none)';

        foreach (array_keys($surface) as $key) {
            if (str_starts_with($key, 'class:')) {
                $symbol = substr($key, strlen('class:'));

                break;
            }
        }

        $files[] = ['name' => basename($path), 'path' => $path, 'symbol' => $symbol];

        foreach ($surface as $key => $description) {
            if (!str_starts_with($key, 'method:')) {
                continue;
            }

            [$class, $method] = explode('::', substr($key, strlen('method:')), 2);

            $methods[] = [
                'method' => $method,
                'file' => $path,
                'class' => $class,
                'signature' => $description,
            ];
        }

        // The rest of the surface: constants, enum cases and public properties, and —
        // for a config file — the keys it returns and the env vars it reads. The
        // parser already spells each one as `kind:name`, which is why a row is stored
        // as its two halves rather than as the key.
        $namedWith = $surface;

        if (str_starts_with($path, 'config/')) {
            $namedWith += configSurface($source);
        }

        foreach (array_keys($namedWith) as $key) {
            [$kind, $name] = array_pad(explode(':', $key, 2), 2, $key);

            if (in_array($kind, ['const', 'case', 'property', 'config', 'env'], true)) {
                $named[] = ['kind' => $kind, 'symbol' => $name, 'file' => $path];
            }
        }
    }

    usort($files, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);

    usort($methods, static fn (array $a, array $b): int => [$a['class'], $a['method']] <=> [$b['class'], $b['method']]);

    // Grouped by kind and alphabetical inside it, so a diff shows one removal in one
    // place rather than a file's worth of context around it.
    usort($named, static fn (array $a, array $b): int => [$a['kind'], $a['symbol'], $a['file']] <=> [$b['kind'], $b['symbol'], $b['file']]);

    return ['files' => $files, 'methods' => $methods, 'surface' => $named];
}

/**
 * One file's bytes: what it is, which tag it describes, what the columns are, then
 * the rows.
 *
 * @param list<string> $columns
 * @param list<array<string, string>> $rows
 */
function renderInventory(string $file, string $tag, array $columns, array $rows): string
{
    $content = sprintf('# %s — generated by bin/inventory.php — describes the tree at %s', $file, $tag) . "\n"
        . '# ' . implode("\t", $columns) . "\n";

    foreach ($rows as $row) {
        $content .= implode("\t", array_map(
            static fn (string $column): string => $row[$column] ?? '',
            $columns,
        )) . "\n";
    }

    return $content;
}

/**
 * The inventory as it should be for this tree and stamp: every file's bytes, and
 * how many rows each holds.
 *
 * The rows can be handed in — that is how a run describing a ref rather than the checkout
 * reaches the one renderer and the one comparison — and are read off the working tree
 * when they are not, which is what every caller but that one wants.
 *
 * @param array{files: list<array{name: string, path: string, symbol: string}>, methods: list<array{method: string, file: string, class: string, signature: string}>, surface: list<array{kind: string, symbol: string, file: string}>}|null $records
 * @return array{files: string, methods: string, surface: string, count: array{files: int, methods: int, surface: int}}
 */
function inventoryDocument(string $root, string $tag, ?array $records = null): array
{
    $records ??= inventoryRecords($root);

    return [
        'files' => renderInventory('files.tsv', $tag, ['name', 'path', 'symbol'], $records['files']),
        'methods' => renderInventory('methods.tsv', $tag, ['method', 'file', 'class', 'signature'], $records['methods']),
        'surface' => renderInventory('surface.tsv', $tag, ['kind', 'symbol', 'file'], $records['surface']),
        'count' => [
            'files' => count($records['files']),
            'methods' => count($records['methods']),
            'surface' => count($records['surface']),
        ],
    ];
}

/**
 * The one place the three files are compared and written, so both commands agree on
 * what "out of step" means: the bytes on disk against the bytes this tree and
 * stamp produce, carriage returns normalised away first.
 *
 * The stamp is part of the comparison on purpose. A stamp that names a different
 * release is how a file written at the wrong moment is caught, and it is the one
 * difference that must never be waved through.
 *
 * @param array{files: list<array{name: string, path: string, symbol: string}>, methods: list<array{method: string, file: string, class: string, signature: string}>, surface: list<array{kind: string, symbol: string, file: string}>}|null $records the rows to compare and write, or null to read the working tree
 * @return array{current: bool, written: bool, count: array{files: int, methods: int, surface: int}}
 */
function syncInventory(string $root, string $tag, bool $write = true, ?array $records = null): array
{
    $document = inventoryDocument($root, $tag, $records);
    $paths = inventoryPaths($root);

    $current = true;

    foreach (['files', 'methods', 'surface'] as $file) {
        $onDisk = @file_get_contents($paths[$file]);

        if ($onDisk === false || str_replace(["\r\n", "\r"], "\n", $onDisk) !== $document[$file]) {
            $current = false;
        }
    }

    if (!$write) {
        return ['current' => $current, 'written' => false, 'count' => $document['count']];
    }

    $written = file_put_contents($paths['files'], $document['files']) !== false
        && file_put_contents($paths['methods'], $document['methods']) !== false
        && file_put_contents($paths['surface'], $document['surface']) !== false;

    return ['current' => $current, 'written' => $written, 'count' => $document['count']];
}

/**
 * One file read back: the tag it says it describes, its column names, and its rows
 * in the order they were written.
 *
 * CRLF is normalised away rather than trusted: the files are pinned to LF in
 * `.gitattributes`, but a checkout on Windows can still hand one back with
 * carriage returns, and a `\r` left on the last cell would make two identical rows
 * compare unequal.
 *
 * @return array{stamp: string, columns: list<string>, rows: list<array<string, string>>}|null
 */
function readInventory(string $path): ?array
{
    $raw = @file_get_contents($path);

    if ($raw === false) {
        return null;
    }

    $stamp = 'unknown';
    $columns = [];
    $rows = [];

    foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $raw)) as $line) {
        if ($line === '') {
            continue;
        }

        if (str_starts_with($line, '#')) {
            if ($stamp === 'unknown' && preg_match('/describes the tree at (.+?)\s*$/', $line, $match) === 1) {
                $stamp = $match[1];
            } elseif ($columns === [] && str_contains($line, "\t")) {
                $columns = explode("\t", ltrim(substr($line, 1)));
            }

            continue;
        }

        $cells = explode("\t", $line);
        $row = [];

        foreach ($columns as $index => $column) {
            $row[$column] = $cells[$index] ?? '';
        }

        if ($row !== []) {
            $rows[] = $row;
        }
    }

    return ['stamp' => $stamp, 'columns' => $columns, 'rows' => $rows];
}

/**
 * Two inventories' config keys, env vars, constants, enum cases and public
 * properties, compared as symbols.
 *
 * The same two rules `surfaceDiff()` applies to a live surface, applied to two
 * written-down ones instead: a row that disappeared is breaking, and one that
 * appeared is a minor. A row cannot change shape — its kind is the row — so there is
 * no third case, which is why these rows are stored as a kind and a name rather than
 * as the description `surfaceDiff()` would have to compare.
 *
 * This is the half of the inventory that answers to a tree with no tag at all. The
 * public-API signal diffs two tags, so with one tag there is nothing for it to see a
 * removal in; a stored row is its own "before".
 *
 * The `file` column is not part of the identity, and that is a decision with a cost: a
 * config key two files return is two rows on disk and one name here, so a key dropped from
 * the second file is a change this verdict cannot see — the name is still declared by the
 * first, and here that is all a name is. The file is not folded away on purpose; the
 * dropped row is still in `surface.tsv`, which is why the plan says the collision out loud
 * rather than leaning on a verdict that cannot carry it.
 *
 * @param list<array<string, string>> $stored the `surface.tsv` rows as the last release wrote them
 * @param list<array{kind: string, symbol: string, file: string}> $current the rows for the tree now
 * @return array{severity: string, evidence: list<string>}
 */
function surfaceRowDiff(array $stored, array $current): array
{
    $before = [];

    foreach ($stored as $row) {
        if (($row['kind'] ?? '') !== '' && ($row['symbol'] ?? '') !== '') {
            $before[$row['kind'] . ':' . $row['symbol']] = true;
        }
    }

    $after = [];

    foreach ($current as $row) {
        $after[$row['kind'] . ':' . $row['symbol']] = true;
    }

    $severity = 'patch';
    $evidence = [];

    foreach (array_keys($before) as $key) {
        if (array_key_exists($key, $after)) {
            continue;
        }

        $severity = 'breaking';
        $evidence[] = 'removed ' . describeSymbol($key);
    }

    foreach (array_keys($after) as $key) {
        if (array_key_exists($key, $before)) {
            continue;
        }

        if (severityRank('minor') > severityRank($severity)) {
            $severity = 'minor';
        }

        $evidence[] = 'added ' . describeSymbol($key);
    }

    return ['severity' => $severity, 'evidence' => $evidence];
}

/**
 * The inventory's verdict on the working tree, weighed exactly as the tag diff
 * weighs a surface.
 *
 * A file that disappeared is breaking; one that appeared is a minor; a path whose
 * declared symbol changed is breaking, because the symbol is what a consumer
 * imports. A path that moved with its symbol intact is a move, and it costs
 * nothing — which is the one reading a path list can add on top of the FQN diff.
 * A method's stored signature is what catches the breaking kind a name alone
 * cannot see: a required argument that was not there before.
 *
 * The rows that are neither files nor methods are weighed by `surfaceRowDiff()`,
 * which is where a config key or a constant removed since the last release is
 * witnessed — by nothing else at all when the tree carries no tag.
 *
 * @param array{files: array{stamp: string, columns: list<string>, rows: list<array<string, string>>}|null, methods: array{stamp: string, columns: list<string>, rows: list<array<string, string>>}|null, surface: array{stamp: string, columns: list<string>, rows: list<array<string, string>>}|null} $stored
 * @param array{files: list<array{name: string, path: string, symbol: string}>, methods: list<array{method: string, file: string, class: string, signature: string}>, surface: list<array{kind: string, symbol: string, file: string}>} $current
 * @return array{severity: string, evidence: list<string>}
 */
function diffInventory(array $stored, array $current): array
{
    $severity = 'patch';
    $evidence = [];

    $storedFiles = [];
    foreach ($stored['files']['rows'] ?? [] as $row) {
        if (($row['path'] ?? '') !== '') {
            $storedFiles[$row['path']] = $row['symbol'] ?? '(none)';
        }
    }

    $currentFiles = [];
    foreach ($current['files'] as $row) {
        $currentFiles[$row['path']] = $row['symbol'];
    }

    $currentSymbols = array_flip($currentFiles);

    $removed = array_diff_key($storedFiles, $currentFiles);
    $added = array_diff_key($currentFiles, $storedFiles);

    // A symbol that shows up at a new path is a move, not a removal and an
    // addition: it is the same thing to import, so it costs nothing.
    $movedTo = [];

    foreach ($removed as $path => $symbol) {
        if ($symbol !== '(none)' && array_key_exists($symbol, $currentSymbols)) {
            $target = $currentSymbols[$symbol];
            $movedTo[$target] = true;
            $evidence[] = sprintf('moved %s: %s -> %s', $symbol, $path, $target);

            continue;
        }

        $severity = 'breaking';
        $evidence[] = $symbol === '(none)'
            ? "removed file {$path}"
            : "removed file {$path} ({$symbol})";
    }

    foreach ($added as $path => $symbol) {
        if (array_key_exists($path, $movedTo)) {
            continue;
        }

        if (severityRank('minor') > severityRank($severity)) {
            $severity = 'minor';
        }

        $evidence[] = $symbol === '(none)'
            ? "added file {$path}"
            : "added file {$path} ({$symbol})";
    }

    foreach ($storedFiles as $path => $symbol) {
        if (!array_key_exists($path, $currentFiles) || $currentFiles[$path] === $symbol) {
            continue;
        }

        $severity = 'breaking';
        $evidence[] = sprintf(
            '%s now declares %s, was %s',
            $path,
            $currentFiles[$path] === '(none)' ? 'nothing' : $currentFiles[$path],
            $symbol === '(none)' ? 'nothing' : $symbol,
        );
    }

    $storedMethods = [];
    foreach ($stored['methods']['rows'] ?? [] as $row) {
        $storedMethods[($row['class'] ?? '') . '::' . ($row['method'] ?? '')] = $row['signature'] ?? '';
    }

    $currentMethods = [];
    foreach ($current['methods'] as $row) {
        $currentMethods[$row['class'] . '::' . $row['method']] = $row['signature'];
    }

    foreach ($storedMethods as $key => $signature) {
        if (!array_key_exists($key, $currentMethods)) {
            $severity = 'breaking';
            $evidence[] = "removed public method {$key}()";

            continue;
        }

        $now = $currentMethods[$key];

        if ($now === $signature) {
            continue;
        }

        $wasRequired = shapeCounts($signature)[0];
        $nowRequired = shapeCounts($now)[0];

        if ($nowRequired > $wasRequired) {
            $severity = 'breaking';
            $evidence[] = "{$key}() needs {$nowRequired} required argument(s), was {$wasRequired}";

            continue;
        }

        if (severityRank('minor') > severityRank($severity)) {
            $severity = 'minor';
        }

        $evidence[] = "{$key}() signature changed ({$signature} -> {$now})";
    }

    foreach ($currentMethods as $key => $signature) {
        if (array_key_exists($key, $storedMethods)) {
            continue;
        }

        if (severityRank('minor') > severityRank($severity)) {
            $severity = 'minor';
        }

        $evidence[] = "added public method {$key}()";
    }

    $named = surfaceRowDiff($stored['surface']['rows'] ?? [], $current['surface']);

    if (severityRank($named['severity']) > severityRank($severity)) {
        $severity = $named['severity'];
    }

    $evidence = [...$evidence, ...$named['evidence']];

    return ['severity' => $severity, 'evidence' => $evidence];
}
