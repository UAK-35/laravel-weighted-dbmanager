<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Tests\Support;

/**
 * Stands in for the process `db:doctor` starts to ask supervisor about a program: it
 * answers with whatever a test needs supervisorctl to have said, and records every
 * command it was asked to run.
 *
 * The recording is the point. The `pgcat supervisor` row's whole claim is that it
 * inspects the flip's last step without taking it, so a test asserts on the commands
 * that reached a runner: that there was one, that it asked for `status`, and that no
 * restart, signal or stop was ever run.
 */
final class FakeSupervisor
{
    /** @var list<string> */
    public array $ran = [];

    public function __construct(
        private readonly int $exit = 0,
        private readonly string $stdout = 'pgcat:pgcat_00                       RUNNING   pid 4242, uptime 0:12:34',
        private readonly string $stderr = '',
    ) {
    }

    /**
     * @return \Closure(string): array{0: int, 1: string, 2: string}
     */
    public function runner(): \Closure
    {
        return function (string $command): array {
            $this->ran[] = $command;

            return [$this->exit, $this->stdout, $this->stderr];
        };
    }
}
