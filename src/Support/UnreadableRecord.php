<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Support;

use RuntimeException;

/**
 * The audit record on disk is there and is not a record.
 *
 * WHY THIS IS AN EXCEPTION AND NOT AN EMPTY LIST
 * ----------------------------------------------
 * `BootAudit::read()` answers "what did a previous boot record" and, for a file it cannot
 * parse, answers "nothing" — which is right for its callers: a boot that cannot read the
 * record cannot carry its entries over, and a diagnostic never stops a boot. But the same
 * answer printed by a *surface* is a claim nobody can make: `db:replica-status` would say
 * "nothing standing" and `/health/db` would say `available: true, count: 0` about an
 * installation whose record — a warning that has stood for three weeks, say — is sitting
 * on disk unread. The failure mode of the tolerant reader is one line too few; the failure
 * mode of the tolerant *report* is a false all-clear.
 *
 * So the two readers are split, and the strict one throws this: `BootAudit::standing()` is
 * what a surface asks, and `BootAudit::reported()` catches this and turns it into the
 * `available: false` block with the reason attached, which is the pair those blocks were
 * always shaped around and could never reach while an unreadable file read as an empty one.
 *
 * The message is the reason alone, phrased to finish the sentence the surfaces print —
 * `db:replica-status` renders it as `Audit: unreadable — <message>` and `/health/db`
 * publishes it as `audit.error` — so it names the file an operator has to look at and what
 * is wrong with it, and nothing else. A named type rather than a bare `RuntimeException` so
 * a caller that wants to treat "the record is not a record" differently from "reading the
 * record blew up in some other way" can, without matching on a message.
 */
final class UnreadableRecord extends RuntimeException
{
    /**
     * The record at `$file`, and what about it is not readable.
     *
     * `$reason` is a predicate: "could not be read", "is empty", "is not the JSON object a
     * record is", "does not hold a map of findings". Callers render the message rather than
     * branching on it — see the class docblock — so the spelling is for a human.
     */
    public static function of(string $file, string $reason): self
    {
        return new self(sprintf('%s %s', $file, $reason));
    }
}
