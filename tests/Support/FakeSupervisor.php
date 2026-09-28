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
 *
 * One answer for every command is what most tests want. A command that names an unknown
 * program is asked twice — the program, then what supervisor is running at all — so a test
 * about that fault passes `answers` and answers per command instead.
 */
final class FakeSupervisor
{
    /** @var list<string> */
    public array $ran = [];

    /** @var (\Closure(string): array{0: int, 1: string, 2: string})|null */
    private readonly ?\Closure $answers;

    /**
     * @param (\Closure(string): array{0: int, 1: string, 2: string})|null $answers
     *        what supervisorctl said to *this* command, when one answer cannot stand for all
     */
    public function __construct(
        private readonly int $exit = 0,
        private readonly string $stdout = 'pgcat:pgcat_00                       RUNNING   pid 4242, uptime 0:12:34',
        private readonly string $stderr = '',
        ?\Closure $answers = null,
    ) {
        $this->answers = $answers;
    }

    /**
     * @return \Closure(string): array{0: int, 1: string, 2: string}
     */
    public function runner(): \Closure
    {
        return function (string $command): array {
            $this->ran[] = $command;

            return $this->answers !== null
                ? ($this->answers)($command)
                : [$this->exit, $this->stdout, $this->stderr];
        };
    }
}
