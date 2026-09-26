<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Support;

use Uak35\WeightedDbManager\Database\Weighted\TimeWindowResolver;
use Uak35\WeightedDbManager\Pgcat\PgcatConfigFlipper;
use Uak35\WeightedDbManager\Pgcat\SupervisorStep;

/**
 * A pgcat installation in a temp directory, for tests that drive `db:pgcat-flip`.
 *
 * Three things make a flip possible at all, and a test that forgets one of them is really
 * testing a refusal: the two variant files and the target have to exist, the configured
 * command has to name an executable that resolves, and supervisor has to be answerable. So
 * they are built together, here, with a stand-in supervisorctl on the platform's terms — a
 * `.cmd` on Windows, an executable file elsewhere — and the config slice that points at them.
 *
 * The stand-in is never executed: the flipper is given a runner that answers in its place,
 * and the only question asked of it is the read-only one.
 */
final class PgcatInstallation
{
    /**
     * @param array{
     *     config_path: string,
     *     readers_path: string,
     *     no_readers_path: string,
     *     state_file: string,
     *     lock_file: string,
     *     restart_command: string,
     *     reload_command: string,
     *     supervisorctl: string,
     * } $paths
     */
    private function __construct(private readonly array $paths)
    {
    }

    /**
     * Lay the installation out under `$dir`, which the caller owns and cleans up.
     *
     * @param array<string, string> $overrides path entries to replace, e.g. a missing source
     */
    public static function make(string $dir, array $overrides = []): self
    {
        $bin = $dir.'/bin';
        @mkdir($bin, 0o777, true);

        $script = PHP_OS_FAMILY === 'Windows' ? "@echo off\r\n" : "#!/bin/sh\n";
        $supervisorctl = $bin.'/supervisorctl'.(PHP_OS_FAMILY === 'Windows' ? '.cmd' : '');
        file_put_contents($supervisorctl, $script);
        @chmod($supervisorctl, 0o777);

        $paths = [
            'config_path' => $dir.'/pgcat.toml',
            'readers_path' => $dir.'/pgcat-readers.toml',
            'no_readers_path' => $dir.'/pgcat-no-readers.toml',
            'state_file' => $dir.'/pgcat-flip-state.json',
            'lock_file' => $dir.'/pgcat-flip.lock',
            'restart_command' => $supervisorctl.' restart "pgcat:*"',
            'reload_command' => $supervisorctl.' signal HUP "pgcat:*"',
            'supervisorctl' => $supervisorctl,
        ];

        // The files are written at their default locations and *then* the overrides are
        // applied: a row that points a path at something missing has to be able to say so,
        // and a fixture that helpfully created the file would be testing the opposite case.
        file_put_contents($paths['config_path'], "# live file pgcat reads\npool = 'unknown'\n");
        file_put_contents($paths['readers_path'], "# readers variant\npool = 'readers'\n");
        file_put_contents($paths['no_readers_path'], "# writer-only variant\npool = 'writer-only'\n");

        return new self([...$paths, ...$overrides]);
    }

    /**
     * Point the booted application at this installation, the way a host does it: the config
     * slice, the inspector the flipper will resolve, and the singletons both are built from.
     *
     * The resolver goes into readers mode over a full-day window, so a flip is something that
     * would happen — the condition every row of an exit-code matrix has to start from. A row
     * that wants the other mode says so by changing the window or the recorded state.
     *
     * @param array<string, mixed> $overrides config keys to replace
     */
    public function install(array $overrides = [], ?FakeSupervisor $supervisor = null): FakeSupervisor
    {
        $supervisor ??= new FakeSupervisor();

        app()->instance(SupervisorStep::class, new SupervisorStep($supervisor->runner()));

        config()->set('db-manager.swrr.pgcat', [...$this->config(), ...$overrides]);
        config()->set('db-manager.swrr.reader_windows', [['start' => '00:00:00', 'end' => '23:59:59']]);
        config()->set('db-manager.swrr.reader_days', [1, 2, 3, 4, 5, 6, 7]);

        app()->forgetInstance(TimeWindowResolver::class);
        app()->forgetInstance(PgcatConfigFlipper::class);

        return $supervisor;
    }

    /**
     * The `db-manager.swrr.pgcat` slice this installation is.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return [
            'enabled' => true,
            'config_path' => $this->paths['config_path'],
            'readers_path' => $this->paths['readers_path'],
            'no_readers_path' => $this->paths['no_readers_path'],
            'state_file' => $this->paths['state_file'],
            'lock_file' => $this->paths['lock_file'],
            'restart_command' => $this->paths['restart_command'],
            'reload_command' => $this->paths['reload_command'],
            'use_reload' => false,
        ];
    }

    public function path(string $key): string
    {
        return $this->paths[$key];
    }

    /**
     * What a flip did to the target — the file a claim about the swap is about.
     */
    public function targetContents(): string
    {
        return (string) file_get_contents($this->paths['config_path']);
    }

    /**
     * The record of the last applied mode: present only when a flip succeeded, which is how
     * a test tells "nothing happened" from "something happened and was undone".
     */
    public function stateRecorded(): bool
    {
        return is_file($this->paths['state_file']);
    }

    /**
     * The record's bytes, which is how a test tells "this run did not write it" from "this
     * run wrote it" — the flip's payload carries a timestamp, so any real write changes them.
     */
    public function stateContents(): ?string
    {
        return is_file($this->paths['state_file'])
            ? (string) file_get_contents($this->paths['state_file'])
            : null;
    }

    /**
     * Write a mode into the record, as a previous successful flip would have.
     */
    public function recordMode(?string $mode): void
    {
        file_put_contents($this->paths['state_file'], (string) json_encode(['last_mode' => $mode]));
    }
}
