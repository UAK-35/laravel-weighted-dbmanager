<?php

declare(strict_types=1);

namespace Uak35\WeightedDbManager\Support;

/**
 * A file this package writes, handed back open to whichever process writes it next.
 *
 * WHY A FILE IS NOT DONE WHEN IT IS WRITTEN
 * -----------------------------------------
 * A boot and a flip are routinely not the same account: the web worker and the scheduled
 * command share one audit record, and the per-minute entry shares a state file and its lock
 * with the one-off `db:pgcat-flip` an operator ran from a shell. What one of them creates
 * under its umask is 0644, and the other cannot open it for writing at all. The file then
 * stops being written while every process keeps reporting the state it holds — the audit
 * record's `swrr.audit.file.unwritable` is the one that names it, and a flip's state write
 * simply does not land. So a write ends by saying that anyone who can reach the file may
 * rewrite it.
 *
 * 0666 rather than a mode copied from the caller: the mode the caller found may itself be
 * the problem (a file pushed from a generator, a volume restored from another host), and
 * there is no reading of "the right mode" here other than the one the next account needs.
 *
 * Windows has no mode to speak of — `chmod()` there is the read-only attribute and nothing
 * else — but one call is the right one on both hosts rather than one per host: a file another
 * process left read-only is a file `fopen(…, 'c')` refuses, and clearing that attribute is the
 * same repair in the only spelling that host has. A branch here would be two spellings of one
 * intention, and the branch that has to be remembered is the one that rots.
 *
 * The answer is *read back* rather than assumed. A filesystem that ignores the call — a FAT
 * mount, a share, an ACL where the mode is not the permission — leaves the file as read-only
 * as it was, and a caller that has to report the state (`BootAudit::repairRecord()`) needs
 * that answer rather than the intention. `clearstatcache()` is what makes the read a reading
 * of the file rather than of the stat PHP made before the call.
 */
final class WritableFile
{
    /**
     * Hand an existing file back writable, and say whether it is writable now.
     *
     * False is the ordinary answer for a path with nothing at it rather than a fault: the audit
     * record is *removed* when the merged one has nothing left to remember, so a release runs
     * over a path that no longer exists, and `chmod()` fails on one on both hosts. This method
     * creates nothing — a file that is not there is a file whose mode is not this question.
     */
    public static function handBack(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        @chmod($path, 0o666);

        clearstatcache(true, $path);

        return is_writable($path);
    }

    /**
     * Whether a write to this path would be judged to land, from metadata alone.
     *
     * A file when there is one, and the directory that would have to take it when there is
     * not: a flip does not create its state directory, and a probe whose timestamp has
     * nowhere to go must not run on every request. Two classes asked this question with two
     * copies of the same reading — `BootAudit::recordIsWritable()` and
     * `PgcatConfigFlipper::stateFileWritable()` — and a pair of readings that can drift about
     * the one fact they share is how a row and a write start disagreeing.
     *
     * It is a judgement, not a promise: a filesystem can refuse the write anyway, which is why
     * the callers that write report what they wrote rather than what they intended.
     */
    public static function canWrite(string $path): bool
    {
        if (is_file($path)) {
            return is_writable($path);
        }

        $directory = dirname($path);

        return is_dir($directory) && is_writable($directory);
    }
}
