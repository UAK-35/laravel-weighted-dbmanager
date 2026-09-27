<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Support;

use RuntimeException;

/**
 * A throwaway package in a temp directory, for tests that drive `bin/release.php`.
 *
 * The release script resolves its own root as `dirname(__DIR__)` and runs every git
 * command with that for a working directory, so it cannot be *pointed* at a
 * repository — it has to live in one. The fixture therefore copies the real `bin/`
 * into the temp tree and hands the script a package of its own: the weighing is done
 * by the code under test, not by a stand-in for it, and the tags it reads are ones
 * this test made rather than the package's own release history.
 *
 * git is also given an environment of its own — `GIT_CONFIG_GLOBAL` at a file the
 * fixture writes, `GIT_CONFIG_NOSYSTEM=1`, and an identity in the environment — so
 * the developer's global config (a signing key, an `init.defaultBranch`, a
 * `core.hooksPath` pointing at this very repository's hooks) cannot decide what
 * these tests see. Without that, a release test would pass or fail on whose machine
 * it ran on.
 *
 * ONE REPOSITORY IS PLANTED, AND EVERY TEST COPIES IT
 * --------------------------------------------------
 * `make()` used to build the tree it hands back: four directories and three files,
 * `git init`, `git add`, `git commit`. That is about 350ms on Windows — every one of
 * those steps is a process spawn — and the suite asks for a fixture 24 times to run
 * the 35 tests that share it, so 8 of the release suite's 38 seconds went on planting
 * the same repository over and over.
 *
 * So a repository is planted **once per changelog body** and copied for each test that
 * asks for one. The body is the only thing `make()` varies, and it has to be committed
 * rather than merely written (the release script refuses to release from a tree it
 * cannot vouch for), which is why the templates are keyed by it instead of one template
 * being patched afterwards. A copy is a recursive file copy — `cp -r` in PHP, because
 * there is no portable command for it — and it carries `.git` with it: git stores no
 * absolute path in a freshly initialised repository, and a copied index reads as
 * modified-by-content rather than by stat, so the copy is clean and `git status` says so.
 * It costs about 80ms, and the suite runs in 31 seconds instead of 38.
 *
 * The copy is also a fraction of what a checkout would be, because the repository is
 * initialised from an empty template: 26 files in a stock `.git` against 10 in this
 * one, and the fourteen hook samples that make up the difference are inert — git runs
 * a hook under its real name, and nothing here renames one.
 *
 * Isolation is unchanged, and that is the point of copying rather than resetting: the
 * template is written once and never touched again, every test gets a tree of its own
 * to commit, tag and overwrite in, and nothing one test does can reach another. The
 * fixture would be worse than slow if the shortcut were a shared working tree.
 */
final class ReleaseRepo
{
    private const NAME = 'Release Fixture';
    private const EMAIL = 'fixture@example.test';

    private const SCAFFOLD = "### Fixed\n\n- A ported defect, fixed.\n";

    /** one planted repository per changelog body, copied for every test that asks */
    private static array $templates = [];

    /** everything a run leaves behind, removed when the process ends */
    private static array $trash = [];

    private static bool $sweeper = false;

    /**
     * @param string $root   the package directory — the repo, and the script's root
     * @param string $config the git config handed to every command; a sibling of
     *                       `$root` rather than a file in it, so it is never tracked
     */
    /** the bare repository `withRemote()` creates, once a test asks for one */
    private ?string $remote = null;

    private function __construct(
        private readonly string $root,
        private readonly string $config,
    ) {
    }

    /**
     * A package with one changelog section, one class and one config file, committed
     * and ready to be tagged.
     *
     * `$unreleased` is the `## Unreleased` body, so a test picks the signal it is
     * about by picking the heading — which is exactly the lever the policy gives an
     * author. It is also the only thing that varies, which is why it is the cache key:
     * a body that differs from the planted one has to be written *and committed*, since
     * the release script refuses to cut a release from a tree it cannot vouch for.
     */
    public static function make(string $unreleased = self::SCAFFOLD): self
    {
        $template = self::template($unreleased);

        $root = sys_get_temp_dir() . '/swrr-release-' . bin2hex(random_bytes(6));

        self::copyTree($template->root, $root);
        self::sweep([$root]);

        // The config is shared with the template and every other copy: it is written
        // once, handed to each child as `GIT_CONFIG_GLOBAL`, and never written again, so
        // a copy of it would be a copy of a constant.
        return new self($root, $template->config);
    }

    /**
     * Give this copy a remote to push to: a bare repository beside it, created when it is
     * asked for rather than with every fixture, because `--push` is the only thing here
     * that needs one and a caller that never pushes should not pay for a `git init --bare`
     * on every test.
     *
     * Beside the root rather than inside it, for the same reason the config is: inside, it
     * would be copied and then committed into the fixture; shared between copies, a push
     * from one test would land in another test's history.
     */
    public function withRemote(): self
    {
        if ($this->remote !== null) {
            return $this;
        }

        $remote = $this->root . '.git-remote';

        if (!mkdir($remote, 0o777, true) && !is_dir($remote)) {
            throw new RuntimeException("Could not create {$remote}");
        }

        $this->git('init', '--bare', '--quiet', $remote);
        $this->git('remote', 'add', 'origin', $remote);

        self::sweep([$remote]);

        $this->remote = $remote;

        return $this;
    }

    public function path(string $relative): string
    {
        return $this->root . '/' . $relative;
    }

    public function read(string $relative): string
    {
        $contents = @file_get_contents($this->path($relative));

        if ($contents === false) {
            throw new RuntimeException("Could not read {$relative}");
        }

        return $contents;
    }

    public function exists(string $relative): bool
    {
        return is_file($this->path($relative));
    }

    public function write(string $relative, string $contents): void
    {
        if (file_put_contents($this->path($relative), $contents) === false) {
            throw new RuntimeException("Could not write {$relative}");
        }
    }

    /** Replace the `## Unreleased` body, for a test that wants a different signal. */
    public function release_notes(string $unreleased): void
    {
        $this->write('CHANGELOG.md', "# Release Notes\n\n## Unreleased\n\n" . $unreleased);
    }

    /** Stage everything and commit it, so a signal has something to read. */
    public function commit(string $message): void
    {
        $this->git('add', '--', '.');
        $this->git('commit', '-m', $message);
    }

    /** An annotated tag, the way a release leaves one behind. */
    public function tag(string $name): void
    {
        $this->git('tag', '-a', $name, '-m', $name);
    }

    /** Every tag in the fixture, one per line. */
    public function tags(): string
    {
        return $this->git('tag', '--list');
    }

    /** Write both inventories, stamped with the latest tag, as a release would. */
    public function refreshInventory(): void
    {
        $this->script('inventory.php');
    }

    /**
     * Rewrite only the stamp, leaving the rows alone: the file then describes a tag
     * that is not the one being released from, which is the state a hand
     * regeneration at some other moment produces.
     */
    public function restampInventory(string $tag): void
    {
        foreach (['files.tsv', 'methods.tsv'] as $inventory) {
            $stamped = (string) preg_replace(
                '/(describes the tree at ).*$/m',
                '$1' . $tag,
                $this->read($inventory),
            );

            $this->write($inventory, $stamped);
        }
    }

    /**
     * Add a row for a public method that is not in the tree. A committed inventory
     * can only be forged this way: the tree itself is what every other signal reads,
     * so a row nobody's source backs is the one change the inventory alone can see.
     */
    public function inventPublicMethod(string $class, string $method, string $file = 'src/Thing.php'): void
    {
        $this->append('methods.tsv', sprintf("%s\t%s\t%s\t0/0 ", $method, $file, $class));
    }

    /**
     * Run the release command, exactly as `composer release` does.
     */
    public function release(string ...$arguments): ReleaseRun
    {
        [$exit, $output, $error] = $this->exec(
            [PHP_BINARY, $this->root . '/bin/release.php', ...$arguments],
        );

        return new ReleaseRun($exit, $output, $error);
    }

    /**
     * Run a helper script shipped in `bin/`, e.g. the inventory generator, and insist
     * that it worked: a script that exits non-zero while a fixture is being built has
     * broken the test's premise, not the thing it is about.
     */
    public function script(string $script, string ...$arguments): string
    {
        $run = $this->scriptRun($script, ...$arguments);

        if ($run->exitCode !== 0) {
            throw new RuntimeException("bin/{$script} exited {$run->exitCode}: {$run->output}{$run->error}");
        }

        return $run->output;
    }

    /**
     * Run a helper script and hand back what it did, a non-zero exit included: a test about
     * a refusal needs the code the run returned rather than an exception, and the two streams
     * stay apart because a refusal goes to stderr while a report goes to stdout. The
     * insist-on-success half is `script()`.
     */
    public function scriptRun(string $script, string ...$arguments): ReleaseRun
    {
        [$exit, $output, $error] = $this->exec(
            [PHP_BINARY, $this->root . '/bin/' . $script, ...$arguments],
        );

        return new ReleaseRun($exit, $output, $error);
    }

    /** Run git in the fixture and return its stdout, refusing to paper over a failure. */
    public function git(string ...$arguments): string
    {
        [$exit, $output, $error] = $this->exec(['git', ...$arguments]);

        if ($exit !== 0) {
            throw new RuntimeException(sprintf(
                'git %s exited %d: %s%s',
                implode(' ', $arguments),
                $exit,
                $output,
                $error,
            ));
        }

        return $output;
    }

    /**
     * The planted repository for one changelog body, built on first use and copied from
     * then on. Returned rather than exposed: a test gets a copy, never this.
     */
    private static function template(string $unreleased): self
    {
        if (isset(self::$templates[$unreleased])) {
            return self::$templates[$unreleased];
        }

        $package = dirname(__DIR__, 2);
        $root = sys_get_temp_dir() . '/swrr-release-template-' . bin2hex(random_bytes(6));
        $config = $root . '.gitconfig';

        // An empty `git init --template`: a stock repository ships fourteen inert hook
        // samples, a `description` and an `info/exclude`, and every one of them would be
        // copied for the whole suite without git ever reading it. The template is passed as
        // a sibling of the root for the same reason the config is — it must not be part of
        // the tree that gets copied, or it would be committed into the fixture as well.
        $initTemplate = $root . '.git-template';

        if (!mkdir($initTemplate, 0o777, true) && !is_dir($initTemplate)) {
            throw new RuntimeException("Could not create {$initTemplate}");
        }

        foreach (['', '/bin', '/src', '/config'] as $directory) {
            if (!mkdir($root . $directory, 0o777, true) && !is_dir($root . $directory)) {
                throw new RuntimeException("Could not create {$root}{$directory}");
            }
        }

        // The real scripts, copied rather than reimplemented: surface.php holds the
        // symbol reader and the inventory format, inventory.php is what a hand
        // regeneration runs, and release.php is the thing under test.
        foreach (['release.php', 'surface.php', 'inventory.php'] as $script) {
            if (!copy($package . '/bin/' . $script, $root . '/bin/' . $script)) {
                throw new RuntimeException("Could not copy bin/{$script}");
            }
        }

        $repo = new self($root, $config);
        self::sweep([$root, $config, $initTemplate]);

        if (file_put_contents($config, self::gitconfig()) === false) {
            throw new RuntimeException("Could not write {$config}");
        }

        $repo->scaffold($unreleased, $initTemplate);

        return self::$templates[$unreleased] = $repo;
    }

    /**
     * A tree copied file by file, subdirectories and dot-files included.
     *
     * Written out rather than shelled out to: `cp -r` is not a command Windows has, and
     * the one it does have is not the one Linux has either. Modes are carried over
     * because git tracks the executable bit, so a copy that dropped it would show up as a
     * modification to a file nobody edited.
     */
    private static function copyTree(string $from, string $to): void
    {
        if (!mkdir($to, 0o777, true) && !is_dir($to)) {
            throw new RuntimeException("Could not create {$to}");
        }

        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $source = $from . '/' . $entry;
            $target = $to . '/' . $entry;

            if (is_dir($source)) {
                self::copyTree($source, $target);

                continue;
            }

            if (!copy($source, $target)) {
                throw new RuntimeException("Could not copy {$source}");
            }

            @chmod($target, fileperms($source) & 0o777);
        }
    }

    private function scaffold(string $unreleased, string $initTemplate): void
    {
        $this->write('composer.json', self::composer());
        $this->release_notes($unreleased);
        $this->write('src/Thing.php', self::thing());
        $this->write('config/sample.php', "<?php\n\nreturn [\n    'enabled' => true,\n];\n");

        $this->git('init', '-b', 'main', '--template=' . $initTemplate);
        $this->commit('chore: scaffold the release fixture');
    }

    private function append(string $relative, string $line): void
    {
        $this->write($relative, rtrim($this->read($relative), "\r\n") . "\n" . $line . "\n");
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private function exec(array $command): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = @proc_open($command, $descriptors, $pipes, $this->root, $this->environment());

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start: ' . implode(' ', $command));
        }

        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $error];
    }

    /**
     * The child's environment: the machine's own, plus the four settings that make
     * git answerable to the fixture instead of to whoever installed it. PATH and
     * SystemRoot have to survive, or git would not resolve on Windows.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $inherited = getenv();

        if (!is_array($inherited)) {
            $inherited = [];
        }

        return array_merge($inherited, [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => $this->config,
            'GIT_AUTHOR_NAME' => self::NAME,
            'GIT_AUTHOR_EMAIL' => self::EMAIL,
            'GIT_COMMITTER_NAME' => self::NAME,
            'GIT_COMMITTER_EMAIL' => self::EMAIL,
            'GIT_TERMINAL_PROMPT' => '0',
        ]);
    }

    private static function gitconfig(): string
    {
        return implode("\n", [
            '[core]',
            "\tautocrlf = false",
            "\tsafecrlf = false",
            '[commit]',
            "\tgpgsign = false",
            '[tag]',
            "\tgpgsign = false",
            '[init]',
            "\tdefaultBranch = main",
            '[user]',
            "\tname = " . self::NAME,
            "\temail = " . self::EMAIL,
            '',
        ]);
    }

    private static function composer(): string
    {
        return <<<'JSON'
        {
            "name": "fixture/release-target",
            "description": "A package that exists only while a release test runs",
            "extra": {
                "branch-alias": {
                    "dev-dev": "0.0.x-dev",
                    "dev-main": "0.0.x-dev"
                }
            }
        }
        JSON . "\n";
    }

    private static function thing(): string
    {
        return <<<'PHP'
        <?php

        namespace Fixture;

        class Thing
        {
            public function label(): string
            {
                return 'thing';
            }

            public function weight(): int
            {
                return 1;
            }
        }
        PHP . "\n";
    }

    /**
     * @param list<string> $paths
     */
    private static function sweep(array $paths): void
    {
        self::$trash = [...self::$trash, ...$paths];

        if (self::$sweeper) {
            return;
        }

        self::$sweeper = true;

        register_shutdown_function(static function (): void {
            foreach (array_reverse(self::$trash) as $path) {
                self::remove($path);
            }
        });
    }

    /**
     * Remove a tree, chmod-ing as it goes: git writes its object files read-only, and
     * Windows will not unlink a read-only file.
     *
     * The directory itself is retried rather than removed once. A handle can outlive
     * the process that opened it for a moment on Windows, and a single `rmdir` then
     * leaves an empty shell behind in the temp directory on every machine that happens
     * to be busy — which is a leak nobody notices and nobody cleans up.
     */
    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0o777);
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }

        for ($attempt = 0; $attempt < 4; $attempt++) {
            if (@rmdir($path)) {
                return;
            }

            usleep(25_000);
        }
    }
}
