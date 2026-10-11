#!/usr/bin/env python3
# =============================================================================
# git/commit_paths.py — commit exactly the paths you name
# =============================================================================
# Answers one question: how do I commit THIS set of paths, and nothing else?
#
# `git commit -- <paths>` looks like the answer, and for the ordinary case it is: the
# content of the named paths is taken from the working tree. It fails at the two things
# this repository actually asks of it.
#
#   * **It cannot record the removal of a file that is still on disk.** `git rm --cached`
#     is how every per-machine settings file here left the index, and those files stay on
#     disk on purpose — they are what the tools read. Naming such a path re-adds its
#     content instead: the commit keeps the generated file AND the template that replaced
#     it, which is the state the change was meant to end. The machine-path gate in this
#     suite refused a commit for exactly that, which is how the case was found: nine
#     generated files, each carrying the drive paths they were being removed for.
#   * **It cannot leave the other staged entries alone and leave the index consistent.**
#     Committing from a scratch index — the only way to keep a colleague's staged work out
#     of your commit — leaves the real index holding the pre-commit blobs. `git status`
#     then reads `MM` for every path in the commit, and the next plain `git commit`
#     publishes the OLD bytes back over the new ones: the commit is silently reverted, and
#     the person who did it has no reason to look.
#   * **It drops a path whose only change is an executable bit, and does not say so.** A
#     mode is the one change a working tree cannot always report: where `core.fileMode` is
#     false — the default on Windows, because there is no such bit to read — `git add`
#     records whatever HEAD had, so the path is not in the commit at all. Measured in a
#     fixture: the index held 100755, `git commit -- <that path> <another>` committed the
#     other path alone, and the index was left holding a change the new HEAD did not have.
#
# The first two were done by hand once, for one commit; the third is why a mode is carried
# rather than re-derived. This is the command that does all three.
#
# What it does
# ------------
#   * Takes the working-tree content of every named path, so a file staged and then
#     written again commits the file, not the copy that was staged — the state this
#     command exists to resolve.
#   * Except a path the index already holds as a deletion: that is committed as a deletion
#     even though the file is on disk, because that is what staging a deletion meant.
#   * Follows a staged rename to its other half, so naming the new path commits a rename
#     rather than an addition and a file left behind. `--no-pair-renames` turns it off.
#   * Adds with `-f`, because a named path is a decision and an ignore rule is a default.
#     A named path that an ignore rule matches is committed and named in the report.
#   * Carries a mode the index holds when the working tree cannot report one: `git add`
#     cannot write that bit here, so the staged mode is applied to the scratch index with
#     `update-index --chmod` and named in the plan rather than left to chance.
#   * Runs the hooks, and they see exactly this set: the commit is built in a scratch
#     index (`GIT_INDEX_FILE`), which is the index the pre-commit gates read.
#   * Sets the real index to the commit for the same paths afterwards, so nothing is left
#     staged and no later `git commit` can undo this one.
#   * Refuses a `-F` message file that was written before the change it describes, or before
#     the last commit in this repository. A file carries no evidence of when it was written
#     for, so the date it does carry is the evidence: a message older than the work it would
#     be attached to is the leftover of an earlier run.
#
# The plan is built and checked before anything is committed: if a named path cannot be
# resolved, if the scratch index does not hold exactly the named set, or if any staged copy
# is not the file on disk, the command stops and commits nothing.
#
# Usage
# -----
#     python git/commit_paths.py -m "subject" -- <paths...>
#     python git/commit_paths.py -F git/COMMIT_MSG.txt --paths-from-file paths.txt
#     python git/commit_paths.py -m "subject" --paths-from-file paths.txt --dry-run
#     python git/commit_paths.py -m "subject" -- <paths...> --list --no-pair-renames
#     python git/commit_paths.py -F git/COMMIT_MSG.txt -- <paths...> --allow-older-message
#     python git/commit_paths.py --selftest
#
# `--paths-from-file` reads one repo-relative path per line, ignoring blank lines and
# lines that start with `#`. A named path may also be absolute, or given with backslashes.
#
# **`-F` carries a message, and the file it names has a date on it.** `-m` and `-F` together
# are refused as ambiguous rather than letting the file win silently. A message written
# before the change it describes, or before the last commit in this repository, cannot have
# been written for the commit it would be attached to: it is the leftover of an earlier run,
# and taking it silently is how a commit came to carry a message written for a different
# change, describing paths it did not touch. That state is refused — `--allow-older-message` takes it
# deliberately — and the file, its size, its date and its first line are named on every run,
# so a wrong-but-recent message is visible before it is committed.

# Exit status: 0 when the commit was made and the index set to it (or when `--dry-run`
# found nothing wrong), 1 when nothing was committed — a path that could not be resolved,
# a message that was not given, two at once, or one not written for this run, or a gate that
# refused the commit — and 2 in the one case the
# message says out loud, where the commit WAS made but the index could not be set to it.
# On 1 nothing is committed, ever.
# Bypass the hooks for one commit with `git commit --no-verify`, as anywhere else.
#
# Committing only a named set:
#   git/commit_paths.py  — this file
# =============================================================================
from __future__ import annotations

import argparse
import os
import subprocess
import sys
import tempfile
import time
from pathlib import Path
from typing import NamedTuple

PROJECT_ROOT = Path(__file__).resolve().parent.parent

ADD = "add"
UPDATE = "update"
REMOVE = "remove"

# git prints `--name-status -z` as `STATUS\0path\0` pairs: the status is never quoted, and
# the path never is either because `-z` replaces the quoting.
NUL = "\0"


# -----------------------------------------------------------------------------
# Running git
# -----------------------------------------------------------------------------
def git(
    args: list[str],
    repo: Path,
    env: dict[str, str] | None = None,
    live: bool = False,
) -> subprocess.CompletedProcess:
    """Run git in the repository.

    `live` lets the child keep the terminal, which is what the commit itself needs: the
    pre-commit gates print their own findings, and a command that swallowed them would
    report "it did not work" with no way to see why.
    """
    environ = dict(os.environ)
    if env:
        environ.update(env)
    return subprocess.run(
        ["git", *args],
        cwd=str(repo),
        env=environ,
        stdout=None if live else subprocess.PIPE,
        stderr=None if live else subprocess.PIPE,
        check=False,
    )


def text(result: subprocess.CompletedProcess) -> str:
    return (result.stdout or b"").decode("utf-8", "surrogateescape")


def stderr(result: subprocess.CompletedProcess) -> str:
    return (result.stderr or b"").decode("utf-8", "surrogateescape").strip()


def nul_split(raw: bytes) -> list[str]:
    return [field.decode("utf-8", "surrogateescape") for field in raw.split(b"\0") if field]


def pathspec(path: str) -> str:
    """A pathspec that means this path and no other.

    `git add`, `restore` and `diff` read a path as a pattern, so a file called
    `name[1].txt` would also match `name1.txt`. `:(literal)` is git's own way of saying
    "this is a path, not a pattern", and without it a name like that would quietly widen
    the commit. `update-index` takes plain paths and is called with them.
    """
    return f":(literal){path}"


# -----------------------------------------------------------------------------
# Reading the repository
# -----------------------------------------------------------------------------
def repo_root(start: Path) -> Path | None:
    result = git(["rev-parse", "--show-toplevel"], start)
    if result.returncode != 0:
        return None
    return Path(text(result).strip())


def head_exists(repo: Path) -> bool:
    return git(["rev-parse", "--verify", "HEAD"], repo).returncode == 0


def head_paths(repo: Path) -> set[str]:
    result = git(["ls-tree", "-r", "-z", "--name-only", "HEAD"], repo)
    return set(nul_split(result.stdout or b"")) if result.returncode == 0 else set()


def index_paths(repo: Path) -> set[str]:
    """Everything the index holds, intent-to-add entries included."""
    result = git(["ls-files", "-z", "--cached"], repo)
    return set(nul_split(result.stdout or b"")) if result.returncode == 0 else set()


def head_modes(repo: Path) -> dict[str, str]:
    """`path -> the mode HEAD records`, which is what `git add` falls back to here."""
    result = git(["ls-tree", "-r", "-z", "HEAD"], repo)
    return _modes(result.stdout or b"") if result.returncode == 0 else {}


def index_modes(repo: Path) -> dict[str, str]:
    """`path -> the mode the index records`.

    The index is the only writer of an executable bit on a clone where git cannot read one
    off the filesystem: `git add` writes what HEAD had there, so a bit staged with
    `update-index --chmod` is invisible to it and would be missing from any commit built by
    adding files. Everything else about a path is content; this is not.
    """
    result = git(["ls-files", "-s", "-z"], repo)
    return _modes(result.stdout or b"") if result.returncode == 0 else {}


def _modes(raw: bytes) -> dict[str, str]:
    """The records of `ls-tree` and `ls-files -s`, which share `<mode> …\t<path>`."""
    modes: dict[str, str] = {}
    for record in nul_split(raw):
        meta, tab, path = record.partition("\t")
        fields = meta.split()
        if tab and len(fields) == 3:
            modes[path] = fields[0]
    return modes


def staged_paths(repo: Path, env: dict[str, str] | None = None) -> list[str]:
    """Every path the index holds a change for, against HEAD."""
    result = git(["diff", "--cached", "--no-renames", "--name-only", "-z"], repo, env)
    return nul_split(result.stdout or b"")


def staged_entries(repo: Path, env: dict[str, str] | None = None) -> list[tuple[str, str]]:
    """(status, path) for every path the index holds a change for, against HEAD."""
    result = git(["diff", "--cached", "--no-renames", "--name-status", "-z"], repo, env)
    fields = (result.stdout or b"").split(b"\0")
    entries: list[tuple[str, str]] = []
    for index in range(0, len(fields) - 1, 2):
        if not fields[index]:
            continue
        entries.append(
            (
                fields[index].decode("utf-8", "surrogateescape"),
                fields[index + 1].decode("utf-8", "surrogateescape"),
            )
        )
    return entries


def staged_renames(repo: Path) -> dict[str, str]:
    """`new -> old` for every rename the index has staged, as git's own detection sees it.

    Read from `git status` rather than inferred from a deletion and an addition that
    happen to look alike: git has already paired them, and second-guessing that pairing
    here would be a second, worse implementation of rename detection.
    """
    result = git(["status", "--porcelain=v1", "-z", "--untracked-files=no"], repo)
    if result.returncode != 0:
        return {}
    records = nul_split(result.stdout or b"")
    renames: dict[str, str] = {}
    index = 0
    while index < len(records):
        record = records[index]
        columns, _, path = record[:2], record[2:3], record[3:]
        if columns.startswith("R") or columns.endswith("R"):
            # The destination comes first; the source is the next record.
            if index + 1 < len(records):
                renames[path] = records[index + 1]
            index += 1
        index += 1
    return renames


def ignore_rule(repo: Path, path: str) -> str | None:
    """The ignore rule that matches a path, or None.

    `--no-index` on purpose: git never calls a tracked path ignored, so a new file whose
    path a rule already covers reports as ignored to `git add` and as not ignored to
    `git check-ignore`. The question here is what a commit of that path would meet.
    """
    result = git(["check-ignore", "-v", "--no-index", "--", path], repo)
    if result.returncode != 0:
        return None
    first = text(result).splitlines()
    if not first:
        return None
    return first[0].split("\t")[0]


# -----------------------------------------------------------------------------
# The plan
# -----------------------------------------------------------------------------
class Entry(NamedTuple):
    """One path, and what this commit will make of it."""

    path: str
    action: str
    reason: str
    size: int | None = None
    ignored: str | None = None
    mode: str | None = None

    @property
    def removal(self) -> bool:
        return self.action == REMOVE

    def describe(self) -> str:
        mark = {ADD: "+", UPDATE: "~", REMOVE: "-"}[self.action]
        held = "" if self.size is None else f" ({self.size:,} bytes)"
        note = f"  [ignored by {self.ignored}]" if self.ignored else ""
        bit = "" if self.mode is None else f"  [mode {self.mode}]"
        return f"  {mark} {self.path} — {self.reason}{held}{note}{bit}"


class Plan(NamedTuple):
    """The named set, resolved. Empty refusals means it can be committed."""

    entries: list[Entry]
    refusals: list[str]
    foreign: list[str]

    @property
    def paths(self) -> list[str]:
        return [entry.path for entry in self.entries]

    @property
    def from_worktree(self) -> list[str]:
        return [entry.path for entry in self.entries if not entry.removal]

    @property
    def removals(self) -> list[str]:
        return [entry.path for entry in self.entries if entry.removal]

    @property
    def modes(self) -> dict[str, str]:
        """The modes this commit carries over what adding the files would have written."""
        return {entry.path: entry.mode for entry in self.entries if entry.mode is not None}

    @property
    def chmods(self) -> list[tuple[str, list[str]]]:
        """The `update-index --chmod` flags the scratch index needs, grouped by flag.

        A mode is the one thing `git add` cannot always write: where git cannot read an
        executable bit from the filesystem it records what HEAD had, so the bit the index
        holds has to be applied to the scratch index explicitly or the commit lacks it.
        """
        grouped: dict[str, list[str]] = {}
        for path, mode in sorted(self.modes.items()):
            grouped.setdefault("+x" if mode == "100755" else "-x", []).append(path)
        return list(grouped.items())

    def entry(self, path: str) -> Entry | None:
        for item in self.entries:
            if item.path == path:
                return item
        return None

    def counts(self) -> dict[str, int]:
        counts = {ADD: 0, UPDATE: 0, REMOVE: 0}
        for entry in self.entries:
            counts[entry.action] += 1
        return counts

    @property
    def paired(self) -> list[Entry]:
        return [entry for entry in self.entries if "other half" in entry.reason]


def relative(root: Path, raw: str) -> tuple[str | None, str | None]:
    """A repo-relative path, plus why it cannot be one when it cannot."""
    cleaned = raw.strip().replace("\\", "/")
    if not cleaned:
        return None, None
    candidate = Path(cleaned)
    if candidate.is_absolute():
        try:
            cleaned = candidate.resolve().relative_to(root).as_posix()
        except ValueError:
            return None, f"{raw} — outside this repository ({root})"
    else:
        cleaned = cleaned[2:] if cleaned.startswith("./") else cleaned
        if ".." in Path(cleaned).parts:
            try:
                cleaned = (root / cleaned).resolve().relative_to(root).as_posix()
            except ValueError:
                return None, f"{raw} — outside this repository ({root})"
    return cleaned, None


def resolve(root: Path, named: list[str], pair_renames: bool = True) -> Plan:
    """Turn a list of named paths into the exact set this commit would carry."""
    head = head_paths(root)
    index = index_paths(root)
    modes_in_head = head_modes(root)
    modes_in_index = index_modes(root)
    renames = staged_renames(root) if pair_renames else {}

    def carried_mode(path: str) -> str | None:
        """The mode the index holds, when it is not the one adding the file would write.

        `git add` writes 100644 for a new path and HEAD's mode for a tracked one wherever
        the bit cannot be read, so anything else in the index is a decision this commit has
        to carry rather than re-derive from a filesystem that cannot state it.
        """
        held = modes_in_index.get(path)
        if held is None:
            return None
        if path not in modes_in_head:
            return held if held == "100755" else None
        return held if held != modes_in_head[path] else None

    entries: list[Entry] = []
    refusals: list[str] = []
    seen: set[str] = set()

    def target_of(path: str) -> Path:
        return root / path

    def build(path: str, reason: str) -> Entry | None:
        target = target_of(path)
        exists = target.exists() or target.is_symlink()
        if exists and target.is_dir() and not target.is_symlink():
            refusals.append(
                f"{path} — a directory. Name the files: a directory would commit whatever "
                "is inside it at the moment the commit is prepared, which is not a set "
                "anyone can name."
            )
            return None
        if not exists and (path in index or path in head):
            return Entry(path, REMOVE, "gone from the working tree")
        if path in head and path not in index:
            # `git rm --cached`: the index holds the deletion, the file is still here, and it
            # is here on purpose. This is the case `git commit -- <path>` gets wrong — it
            # re-adds the content and the commit keeps both the file and what replaced it —
            # and it is checked before the tracked-path branch below, which would otherwise
            # take this path as an ordinary modification.
            return Entry(
                path,
                REMOVE,
                "the index holds it as a deletion, so the file on disk does not bring it back",
                size=target.stat().st_size if exists else None,
            )
        if path in index or path in head:
            return Entry(
                path,
                UPDATE if path in head else ADD,
                reason,
                size=target.stat().st_size if exists else None,
                ignored=ignore_rule(root, path),
                mode=carried_mode(path),
            )
        if exists:
            return Entry(
                path,
                ADD,
                "unknown to git, added because it was named",
                size=target.stat().st_size,
                ignored=ignore_rule(root, path),
            )
        refusals.append(
            f"{path} — no such path in the working tree, in the index, or in HEAD"
        )
        return None

    for raw in named:
        path, refusal = relative(root, raw)
        if refusal:
            refusals.append(refusal)
            continue
        if path is None:
            continue
        if path in seen:
            continue
        seen.add(path)
        entry = build(path, "the file on disk")
        if entry is not None:
            entries.append(entry)

    # A staged rename is one change, and naming one half of it has to carry the other:
    # committing the destination alone leaves the source file tracked, and committing the
    # source alone deletes it while the file it became stays untracked.
    if renames:
        by_path = {entry.path: entry for entry in entries}
        for path in list(by_path):
            source = renames.get(path)
            if source and source not in by_path and source in head:
                by_path[source] = Entry(
                    source,
                    REMOVE,
                    "the other half of a staged rename — the file it was renamed from",
                )
            elif path in renames.values():
                destination = next((new for new, old in renames.items() if old == path), None)
                if destination and destination not in by_path:
                    target = target_of(destination)
                    if target.exists() or destination in index:
                        by_path[destination] = Entry(
                            destination,
                            UPDATE if destination in head else ADD,
                            "the other half of a staged rename — the file it was renamed to",
                            size=target.stat().st_size if target.exists() else None,
                            ignored=ignore_rule(root, destination),
                            mode=carried_mode(destination),
                        )
        entries = list(by_path.values())

    planned = {entry.path for entry in entries}
    foreign = sorted(path for path in staged_paths(root) if path not in planned)
    return Plan(sorted(entries, key=lambda item: item.path), refusals, foreign)


# -----------------------------------------------------------------------------
# Committing it
# -----------------------------------------------------------------------------
def write_list(directory: Path, name: str, paths: list[str]) -> Path:
    target = directory / name
    target.write_text("".join(f"{pathspec(path)}\n" for path in paths), encoding="utf-8", newline="\n")
    return target


def verify(repo: Path, env: dict[str, str], plan: Plan, scratch: Path) -> list[str]:
    """Hold the scratch index to what the plan said it would hold.

    Three questions, because each one has been the difference between the intended commit
    and a different one: does it carry exactly the named set, does each entry say what the
    plan said it would (`D` for a removal, content for the rest), and is every staged copy
    the file on disk rather than an older revision of it.
    """
    problems: list[str] = []
    staged = staged_entries(repo, env)
    held = {path: status for status, path in staged}
    wanted = set(plan.paths)

    extra = sorted(set(held) - wanted)
    missing = sorted(wanted - set(held))
    if extra:
        problems.append(f"the scratch index holds {len(extra)} path(s) that were not named: {extra}")
    if missing:
        problems.append(f"the scratch index has nothing for {len(missing)} named path(s): {missing}")

    for entry in plan.entries:
        path = entry.path
        status = held.get(path)
        if status is None:
            continue
        if entry.removal and status != "D":
            problems.append(f"{path} was planned as a removal and the index holds {status!r}")
        if not entry.removal and status == "D":
            problems.append(f"{path} was planned from the working tree and the index holds a deletion")

    if plan.from_worktree:
        # The paths go in a file rather than in argv. This question is asked about every path
        # taken from the working tree, and a real set runs to hundreds of them - long enough
        # that Windows' 32 767-character command line refuses the call outright (WinError 206)
        # before git is started, which is a set the tool could plan but never verify. `git diff`
        # reads the same `:(literal)` pathspecs `git add` is already handed this way above.
        drift = git(
            [
                "diff",
                "--name-only",
                "-z",
                f"--pathspec-from-file={write_list(scratch, 'drift', plan.from_worktree)}",
            ],
            repo,
            env,
        )
        drifted = nul_split(drift.stdout or b"")
        if drifted:
            problems.append(
                f"{len(drifted)} staged copy(ies) are not the file on disk: {sorted(drifted)}"
            )
    return problems


def report_plan(plan: Plan, listing: bool, stream=sys.stdout) -> None:
    counts = plan.counts()
    headline = (
        f"commit-paths: {len(plan.entries)} path(s) to commit "
        f"({counts[ADD]} added, {counts[UPDATE]} updated, {counts[REMOVE]} removed)"
    )
    if plan.paired:
        headline += f", {len(plan.paired)} of them paired from a staged rename"
    print(headline, file=stream)

    notable = [
        entry
        for entry in plan.entries
        if entry.removal or entry.ignored or "other half" in entry.reason or entry.action == ADD
    ]
    for entry in plan.entries if listing else notable:
        print(entry.describe(), file=stream)

    if not listing:
        unlisted = len(plan.entries) - len(notable)
        if unlisted:
            print(
                f"  ({unlisted} further path(s) taken from the working tree as they are, "
                "listed with --list)",
                file=stream,
            )
    if plan.foreign:
        print(
            f"commit-paths: {len(plan.foreign)} staged entr(y|ies) this commit leaves alone "
            "— they stay in the index, untouched",
            file=sys.stderr,
        )
    if plan.refusals:
        print(
            f"commit-paths: {len(plan.refusals)} named path(s) cannot be resolved — nothing "
            "was committed",
            file=sys.stderr,
        )
        for refusal in plan.refusals:
            print(f"  {refusal}", file=sys.stderr)


def commit(
    repo: Path,
    plan: Plan,
    message: bytes,
    dry_run: bool = False,
    listing: bool = False,
) -> int:
    """Build the commit in a scratch index, then make the real index match it."""
    report_plan(plan, listing)
    if plan.refusals:
        return 1
    if not plan.entries:
        print("commit-paths: nothing was named — nothing to commit", file=sys.stderr)
        return 1

    with tempfile.TemporaryDirectory(prefix="weighted-dbmanager-commit-paths-") as tmp:
        directory = Path(tmp)
        # Never inside the working tree: an index other than `.git/index` is git's own
        # mechanism for this, and a scratch file left in the tree would be a file to
        # commit rather than a tool to commit with.
        env = {"GIT_INDEX_FILE": str(directory / "index")}
        message_file = directory / "message"
        message_file.write_bytes(message)

        steps: list[tuple[str, list[str]]] = [
            (
                "read the tree this commit starts from",
                ["read-tree", "HEAD"] if head_exists(repo) else ["read-tree", "--empty"],
            ),
        ]
        if plan.removals:
            # `--force-remove` is the whole answer to "the file is still on disk": it takes
            # the entry out of the index whether or not the path exists, which is exactly
            # what `git commit -- <path>` cannot do.
            steps.append(
                ("stage the removals", ["update-index", "--force-remove", "--", *plan.removals])
            )
        if plan.from_worktree:
            steps.append(
                (
                    "stage the files on disk",
                    [
                        "add",
                        "--force",
                        f"--pathspec-from-file={write_list(directory, 'add', plan.from_worktree)}",
                    ],
                )
            )
        # After the add, because `update-index --chmod` needs the entry to be there: this is
        # the bit `git add` could not write, carried from the index that held it.
        for flag, paths in plan.chmods:
            steps.append(
                (
                    f"carry the mode the index holds (--chmod={flag})",
                    ["update-index", f"--chmod={flag}", "--", *paths],
                )
            )

        for label, args in steps:
            result = git(args, repo, env)
            if result.returncode != 0:
                print(f"commit-paths: could not {label} — nothing was committed", file=sys.stderr)
                if stderr(result):
                    print(f"  {stderr(result)}", file=sys.stderr)
                return 1

        problems = verify(repo, env, plan, directory)
        if problems:
            print("commit-paths: the commit as prepared is not the commit that was asked for", file=sys.stderr)
            for problem in problems:
                print(f"  {problem}", file=sys.stderr)
            print("commit-paths: nothing was committed", file=sys.stderr)
            return 1
        print(
            f"commit-paths: scratch index verified — exactly the {len(plan.entries)} path(s), "
            "each holding the file on disk"
        )

        if dry_run:
            print("commit-paths: --dry-run, so nothing was committed and the index is untouched")
            return 0

        # No `--no-gpg-sign` here: whether to sign is the repository's setting, and a tool
        # that silently stopped signing would break a project that requires it.
        result = git(["commit", "-F", str(message_file)], repo, env, live=True)
        if result.returncode != 0:
            print(
                "\ncommit-paths: the commit did not happen — a gate above refused it, and the "
                "index is as it was",
                file=sys.stderr,
            )
            return 1

        # The commit is written; the real index still holds the pre-commit blobs, which is
        # the state in which the next `git commit` publishes the old bytes back over the
        # new ones. Two operations set the index to the commit for these paths, and neither
        # can fail on them: `--force-remove` takes an entry out whether or not the path
        # exists, and `restore --staged` matches, because everything it is given was just
        # committed. A single `restore --staged` over the whole set cannot do it — a path
        # that is now in neither HEAD nor the index matches nothing and makes it refuse the
        # entire list, which is exactly the state a staged deletion leaves behind.
        repair: list[str] = []
        if plan.removals:
            if git(["update-index", "--force-remove", "--", *plan.removals], repo).returncode != 0:
                repair.append("update-index --force-remove")
        if plan.from_worktree:
            set_list = write_list(directory, "set", plan.from_worktree)
            if git(["restore", "--staged", f"--pathspec-from-file={set_list}"], repo).returncode != 0:
                repair.append("restore --staged")

        summary = text(git(["show", "--shortstat", "--format=%h %s", "--no-color", "HEAD"], repo))
        lines = [line for line in summary.splitlines() if line.strip()]
        if lines:
            print(f"commit-paths: committed {lines[0].strip()}")
            if len(lines) > 1:
                print(f"  {lines[-1].strip()}")

        # Setting the index is a claim, so it is checked rather than asserted: no path of
        # this commit may still be staged, and every other staged entry must be untouched.
        still = sorted(path for path in staged_paths(repo) if path in set(plan.paths))
        if repair or still:
            print(
                "commit-paths: THE COMMIT WAS MADE above, but the index was not set to it, so "
                "the next plain `git commit` would publish the old bytes back over it. Run:",
                file=sys.stderr,
            )
            print(f"  git restore --staged -- {' '.join(sorted(still or plan.paths))}", file=sys.stderr)
            return 2

        left = staged_paths(repo)
        note = f"; {len(left)} other staged path(s) still staged, as they were" if left else ""
        print(
            f"commit-paths: index set to the commit for those {len(plan.entries)} path(s), "
            f"and nothing of it is left staged{note}"
        )
    return 0


def read_message(args: argparse.Namespace) -> bytes | None:
    # `-F` used to win over `-m` silently. That is the second way a leftover file gets used: the
    # operator writes a subject for this commit, a wrapper or a habit adds `-F`, and the file's
    # subject is committed in place of the typed one with nothing said. Two messages are an
    # ambiguity, not a preference, so it is refused.
    if args.message_file and args.message:
        print(
            "commit-paths: both -m and -F were given — pass one. A file is not a better message "
            "than the one that was typed, and letting it win is how a leftover is committed.",
            file=sys.stderr,
        )
        return None
    if args.message_file:
        try:
            return Path(args.message_file).read_bytes()
        except OSError as exc:
            print(f"commit-paths: cannot read the message file — {exc}", file=sys.stderr)
            return None
    if args.message:
        return args.message.encode("utf-8") + b"\n"
    print(
        "commit-paths: a commit needs a message — pass -m \"subject\" or -F <file>. "
        "(A tool cannot open an editor, and a commit with an empty message is one nobody "
        "can read later.)",
        file=sys.stderr,
    )
    return None


# -----------------------------------------------------------------------------
# The message file, judged against this run
# -----------------------------------------------------------------------------
# `-F` names a file, and a path is not evidence of what a file was written for. That is how a
# commit here once came to carry a message written for another change: an old `COMMIT_MSG.txt`
# was handed to `-F`, and the commit described paths it did not touch, and a change nobody made
# that day. The message is the one part of
# a commit no gate reads, so the date on the file is what is judged instead: a message written
# before the change it describes, or before the last commit in the repository, was written for
# something else.
#
# It is refused rather than reported, and `--allow-older-message` takes it deliberately. A
# report is the wrong shape here: every other refusal this tool makes is visible in the plan to
# a reader who looks, where a stale message reads as perfectly ordinary text.
STALE_MESSAGE_TOLERANCE = 1.0  # one second: a message written in the same second as its change


def file_written(path: Path) -> float | None:
    """When a file was last written, or None when it cannot be read."""
    try:
        return path.stat().st_mtime
    except OSError:
        return None


def head_written(repo: Path) -> float | None:
    """When the last commit in this repository was made, as an epoch second."""
    result = git(["log", "-1", "--format=%ct"], repo)
    if result.returncode != 0:
        return None
    raw = text(result).strip()
    return float(raw) if raw.isdigit() else None


def span(written: float, reference: float) -> str:
    """How far out of date a file is, in words, so a refusal can say it."""
    seconds = max(0.0, reference - written)
    if seconds < 90:
        return f"{seconds:.0f} seconds"
    if seconds < 5400:
        return f"{seconds / 60:.0f} minutes"
    if seconds < 172800:
        return f"{seconds / 3600:.1f} hours"
    return f"{seconds / 86400:.1f} days"


def stale_message(repo: Path, plan: Plan, source: Path) -> list[str]:
    """The reasons this message file cannot have been written for this commit.

    Two clocks, because either one alone misses the case it is not looking at: a change whose
    paths were written long ago — a removal, where the file on disk may not have changed in
    weeks — is caught by the repository's last commit, and a change made after that commit is
    caught by the newest path in it.
    """
    written = file_written(source)
    if written is None:
        return []
    findings: list[str] = []
    committed = head_written(repo)
    if committed is not None and written < committed - STALE_MESSAGE_TOLERANCE:
        findings.append(
            f"{source} was written {span(written, committed)} before the last commit in this "
            f"repository, so it was written for something else"
        )
    stamps = [
        (path, when)
        for path in plan.paths
        if (when := file_written(repo / path)) is not None
    ]
    if stamps:
        newest_path, newest = max(stamps, key=lambda pair: pair[1])
        if written < newest - STALE_MESSAGE_TOLERANCE:
            findings.append(
                f"{source} was written {span(written, newest)} before {newest_path}, the newest "
                f"path in this commit, so it does not describe this change"
            )
    return findings


def report_message(source: Path, message: bytes) -> None:
    """Name the file, its date and its first line, so a wrong message is visible before it lands."""
    written = file_written(source)
    when = time.strftime("%Y-%m-%d %H:%M", time.localtime(written)) if written else "unreadable"
    subject = message.decode("utf-8", "replace").strip().splitlines()
    print(
        f"commit-paths: message from {source} ({len(message)} bytes, written {when}) — "
        f"{subject[0] if subject else '(empty message)'}"
    )


def read_named(args: argparse.Namespace) -> list[str]:
    named = list(args.paths or [])
    if args.paths_from_file:
        try:
            lines = Path(args.paths_from_file).read_text(encoding="utf-8").splitlines()
        except OSError as exc:
            print(f"commit-paths: cannot read the path list — {exc}", file=sys.stderr)
            return []
        named.extend(line.strip() for line in lines if line.strip() and not line.lstrip().startswith("#"))
    return named


# -----------------------------------------------------------------------------
# Self test
# -----------------------------------------------------------------------------
def _write(repo: Path, name: str, body: str) -> None:
    target = repo / name
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(body, encoding="utf-8", newline="\n")


def _commit_all(repo: Path, message: str) -> None:
    git(["add", "-A"], repo)
    git(["commit", "--quiet", "--no-gpg-sign", "-m", message], repo)


def _init(repo: Path) -> None:
    repo.mkdir(parents=True, exist_ok=True)
    git(["init", "--quiet", "--initial-branch=main"], repo)
    git(["config", "user.email", "fixture@example.invalid"], repo)
    git(["config", "user.name", "fixture"], repo)
    git(["config", "commit.gpgsign", "false"], repo)
    git(["config", "core.autocrlf", "false"], repo)


def selftest() -> int:
    """A throwaway repository, because every case here is a state of the index.

    The cases are the ones this command exists for: a file staged and then written again,
    a path staged for deletion whose file is still on disk, a staged rename named by one
    half, a named path an ignore rule matches, and another person's staged entries that
    must come out of this commit exactly as they went in.
    """
    # The fixture is its own repository, and it has to be the only one. A commit made by
    # this command runs this suite from the pre-commit hook, which inherits the scratch
    # index (`GIT_INDEX_FILE`) the commit is being built in — so every `git` the fixture
    # ran would read and write that index instead of its own, and the fixture would end up
    # with no `.git/index` at all. Measured: the suite passed by hand and failed inside the
    # hook, `FileNotFoundError: …/fixture/.git/index`, on the check that reads the index
    # back. Everything below runs in a clean shell; `commit()` sets its own index and is
    # unaffected, because an explicit environment is merged over this one.
    for ambient in ("GIT_INDEX_FILE", "GIT_DIR", "GIT_WORK_TREE"):
        os.environ.pop(ambient, None)

    failures = 0

    def _expect(label: str, got: object, want: object) -> None:
        nonlocal failures
        if got != want:
            print(f"selftest FAILED: {label} -> {got!r}, expected {want!r}")
            failures += 1

    with tempfile.TemporaryDirectory(prefix="weighted-dbmanager-commit-paths-") as tmp:
        repo = Path(tmp) / "fixture"
        _init(repo)

        _write(repo, "mod.txt", "one\n")
        _write(repo, "kept.txt", "one\n")
        _write(repo, "gone.txt", "staged deletion, file still on disk\n")
        _write(repo, "old.json", "{}\n")
        _write(repo, "sub/inner.txt", "inner\n")
        _commit_all(repo, "fixture")

        # The state this command exists for: a file staged and then written again. The
        # commit has to carry what is on disk, or the shorter copy is published.
        _write(repo, "late.txt", "")
        git(["add", "late.txt"], repo)
        _write(repo, "late.txt", "written after it was staged\n")

        # Modified on disk, nothing staged.
        _write(repo, "mod.txt", "two\n")

        # Deleted from the working tree, not staged.
        (repo / "kept.txt").unlink()

        # Staged for deletion while the file stays on disk — `git rm --cached`, which is how
        # the generated settings files leave this repository's index. Named, it must be
        # committed as a deletion; `git commit -- <path>` would re-add it.
        _write(repo, ".gitignore", "gone.txt\nfresh.txt\n")
        git(["rm", "--quiet", "--cached", "gone.txt"], repo)

        # A staged rename, and a new file an ignore rule already covers.
        git(["mv", "old.json", "new.json"], repo)
        _write(repo, "fresh.txt", "fresh\n")

        # Another person's staged work: one new path, one staged and then rewritten. This
        # commit must leave both exactly as they are.
        _write(repo, "foreign.txt", "one\n")
        git(["add", "foreign.txt"], repo)
        _write(repo, "foreign.txt", "two\n")
        _write(repo, "other-new.txt", "other\n")
        git(["add", "other-new.txt"], repo)

        # A path git would read as a pattern, to hold `:(literal)` to its job.
        _write(repo, "name[1].txt", "brackets\n")

        # A mode the working tree cannot always report. `git add` writes what HEAD had
        # wherever git cannot read an executable bit from the filesystem, so the bit has to
        # be staged by hand — and a named-set commit has to carry it rather than drop the
        # path, which is what a plain `git commit -- <path>` does with this state.
        _write(repo, "mode.sh", "#!/bin/sh\ntrue\n")
        (repo / "mode.sh").chmod(0o755)
        git(["add", "mode.sh"], repo)
        git(["update-index", "--chmod=+x", "mode.sh"], repo)

        # The hook runs against the index this command builds, and says which set it saw.
        hook = repo / ".git" / "hooks" / "pre-commit"
        hook.write_text(
            "#!/bin/sh\n"
            "git diff --cached --no-renames --name-only | sort > \"$(git rev-parse --show-toplevel)/.hook-saw\"\n"
            "echo \"fixture hook ran\"\n",
            encoding="utf-8",
            newline="\n",
        )
        hook.chmod(0o755)

        named = [
            "mod.txt",
            "gone.txt",
            "new.json",
            "late.txt",
            "fresh.txt",
            "kept.txt",
            "mode.sh",
            "name[1].txt",
        ]
        plan = resolve(repo, named)

        _expect("every named path resolves", plan.refusals, [])
        _expect(
            "the set is the named paths plus the other half of the staged rename",
            plan.paths,
            [
                "fresh.txt",
                "gone.txt",
                "kept.txt",
                "late.txt",
                "mod.txt",
                "mode.sh",
                "name[1].txt",
                "new.json",
                "old.json",
            ],
        )
        _expect(
            "a mode the filesystem cannot report is planned from the index",
            plan.entry("mode.sh").mode,
            "100755",
        )
        _expect(
            "a path staged for deletion while still on disk is planned as a removal",
            (plan.entry("gone.txt").action, plan.entry("gone.txt").removal),
            (REMOVE, True),
        )
        _expect(
            "a file gone from the working tree is planned as a removal",
            plan.entry("kept.txt").action,
            REMOVE,
        )
        _expect(
            "the other half of a staged rename is planned as a removal",
            (plan.entry("old.json").action, "other half" in plan.entry("old.json").reason),
            (REMOVE, True),
        )
        _expect(
            "a named path an ignore rule matches is added, and marked",
            (plan.entry("fresh.txt").action, plan.entry("fresh.txt").ignored is not None),
            (ADD, True),
        )
        _expect(
            "another person's staged paths are reported as left alone",
            plan.foreign,
            ["foreign.txt", "other-new.txt"],
        )

        unpaired = resolve(repo, ["new.json"], pair_renames=False)
        _expect(
            "--no-pair-renames leaves the other half alone",
            (unpaired.paths, unpaired.foreign),
            (
                ["new.json"],
                ["foreign.txt", "gone.txt", "late.txt", "mode.sh", "old.json", "other-new.txt"],
            ),
        )

        refused = resolve(repo, ["mod.txt", "nope.txt", "sub", "../outside.txt"])
        _expect(
            "a missing path, a directory and a path outside the repository are refused",
            len(refused.refusals),
            3,
        )

        # A refusal commits nothing at all, however good the other names are.
        before = git(["rev-parse", "HEAD"], repo)
        exit_code = commit(repo, refused, b"refused\n")
        _expect("a refusal exits 1", exit_code, 1)
        _expect("and writes no commit", git(["rev-parse", "HEAD"], repo).stdout, before.stdout)

        # A named path with nothing to commit is not a commit with one path fewer: it makes
        # an empty commit of that path, and the check on the prepared index is what says so.
        settled = resolve(repo, ["sub/inner.txt"])
        _expect("a path with nothing to commit exits 1", commit(repo, settled, b"no-op\n"), 1)
        _expect("and writes no commit either", git(["rev-parse", "HEAD"], repo).stdout, before.stdout)

        # --dry-run has to be read-only: same commit, same index, same files.
        index_before = (repo / ".git" / "index").read_bytes()
        exit_code = commit(repo, plan, b"dry run\n", dry_run=True)
        _expect("--dry-run exits 0", exit_code, 0)
        _expect("--dry-run writes no commit", git(["rev-parse", "HEAD"], repo).stdout, before.stdout)
        _expect("--dry-run leaves the index byte-identical", (repo / ".git" / "index").read_bytes(), index_before)

        exit_code = commit(repo, plan, b"the commit a named set makes\n")
        _expect("the commit exits 0", exit_code, 0)

        tree = git(["ls-tree", "-r", "-z", "--name-only", "HEAD"], repo).stdout
        held = set(nul_split(tree))
        _expect("the removal of a path still on disk reaches the commit", "gone.txt" in held, False)
        _expect("the file itself is not deleted", (repo / "gone.txt").exists(), True)
        _expect("the other half of the rename is gone from the commit", "old.json" in held, False)
        _expect("and its new name is in it", "new.json" in held, True)
        _expect("the path gone from the working tree is removed", "kept.txt" in held, False)
        _expect("the file staged and then rewritten commits the file", text(git(["show", "HEAD:late.txt"], repo)), "written after it was staged\n")
        _expect("the modified file commits what is on disk", text(git(["show", "HEAD:mod.txt"], repo)), "two\n")
        _expect("a named path an ignore rule matches is committed", "fresh.txt" in held, True)
        _expect("a name git would read as a pattern is committed as itself", "name[1].txt" in held, True)
        _expect(
            "a staged executable bit reaches the commit",
            text(git(["ls-tree", "HEAD", "mode.sh"], repo)).split()[0],
            "100755",
        )
        _expect("another person's staged path is not in the commit", "foreign.txt" in held, False)

        recorded = repo / ".hook-saw"
        saw = recorded.read_text(encoding="utf-8").split() if recorded.exists() else []
        _expect("the hook ran", bool(saw), True)
        _expect("and saw exactly the paths this commit was asked for", saw, sorted(plan.paths))

        # The repair: nothing of this commit is left staged, and no `MM` state can make the
        # next plain commit publish the old bytes back.
        _expect(
            "only the other person's entries are still staged",
            sorted(staged_paths(repo)),
            ["foreign.txt", "other-new.txt"],
        )
        _expect(
            "and theirs hold what they held",
            text(git(["show", ":foreign.txt"], repo)),
            "one\n",
        )
        _expect(
            "this commit's paths are clean",
            [status for status, path in staged_entries(repo) if path in set(plan.paths)],
            [],
        )
        _expect(
            "the commit is the one that was asked for",
            text(git(["log", "-1", "--format=%s"], repo)).strip(),
            "the commit a named set makes",
        )
        _expect(
            "the commit with a refusal never happened",
            text(git(["log", "--format=%s"], repo)).splitlines(),
            ["the commit a named set makes", "fixture"],
        )

        # A repository with no commits at all: an empty tree to start from, no parent.
        fresh = Path(tmp) / "fresh"
        _init(fresh)
        _write(fresh, "first.txt", "first\n")
        first_plan = resolve(fresh, ["first.txt"])
        _expect("a first commit plans its file", first_plan.paths, ["first.txt"])
        _expect("a first commit exits 0", commit(fresh, first_plan, b"the first commit\n"), 0)
        _expect(
            "and it is in the tree",
            "first.txt" in set(nul_split(git(["ls-tree", "-r", "-z", "--name-only", "HEAD"], fresh).stdout)),
            True,
        )

        # A message file is judged against this run. One written before the change it would
        # describe, or before the last commit, is a leftover from an earlier run — the state
        # that once placed a message for another change on a commit.
        late_plan = resolve(repo, ["mod.txt"])
        leftover = Path(tmp) / "leftover-msg.txt"
        leftover.write_text("a message from an earlier session\n", encoding="utf-8")
        aged = time.time() - 3 * 86400
        os.utime(leftover, (aged, aged))
        findings = stale_message(repo, late_plan, leftover)
        _expect("a message file older than the change is refused", bool(findings), True)
        joined = " | ".join(findings)
        _expect(
            "and the refusal names the last commit",
            "the last commit in this repository" in joined,
            True,
        )
        _expect(
            "and the newest path in the commit",
            "the newest path in this commit" in joined,
            True,
        )
        fresh_message = Path(tmp) / "fresh-msg.txt"
        fresh_message.write_text("written for this run\n", encoding="utf-8")
        _expect(
            "a message written for this run is accepted",
            stale_message(repo, late_plan, fresh_message),
            [],
        )
        _expect(
            "and a message file that cannot be read is left to the read that reports it",
            stale_message(repo, late_plan, Path(tmp) / "does-not-exist.txt"),
            [],
        )
        _expect(
            "a typed subject beside -F is refused, not silently replaced by the file",
            read_message(
                argparse.Namespace(message="typed for this commit", message_file=str(leftover))
            ),
            None,
        )
        _expect(
            "and -m on its own is the message that was typed",
            read_message(argparse.Namespace(message="typed for this commit", message_file=None)),
            b"typed for this commit\n",
        )

    if failures:
        return 1
    print("[narrowed] a removal that is still on disk is still carried, so the named set cannot be narrowed to what the working tree holds")
    print(
        "selftest: ok (a file staged and then rewritten commits the file, a path staged for "
        "deletion is removed even while it is on disk, a staged rename is committed whole, a "
        "named ignored path is added, a staged executable bit is carried, another person's "
        "staged entries come out as they went in, the index is left consistent, --dry-run "
        "writes nothing, a message file older than the change is refused while one written for "
        "the run is accepted, and a typed subject beside -F is refused rather than replaced)"
    )
    return 0


# -----------------------------------------------------------------------------
# Entry point
# -----------------------------------------------------------------------------
def main() -> int:
    parser = argparse.ArgumentParser(
        description="Commit exactly the paths you name, leaving every other staged entry alone."
    )
    parser.add_argument("paths", nargs="*", help="repo-relative paths to commit")
    parser.add_argument("--paths-from-file", metavar="FILE", help="one path per line, blank lines and # skipped")
    parser.add_argument("-m", "--message", help="the commit message")
    parser.add_argument("-F", "--message-file", metavar="FILE", help="read the commit message from a file")
    parser.add_argument("--repo", help="the repository to commit in (default: the one this file lives in)")
    parser.add_argument("--dry-run", action="store_true", help="build and check the commit, then stop before making it")
    parser.add_argument("--list", action="store_true", help="list every path in the plan, not only the notable ones")
    parser.add_argument(
        "--no-pair-renames",
        action="store_true",
        help="commit only the halves that were named, even when the index pairs them as a rename",
    )
    parser.add_argument(
        "--allow-older-message",
        action="store_true",
        help="accept a -F message file written before the change or before the last commit",
    )
    parser.add_argument("--selftest", action="store_true", help="run the built-in fixture repository")
    args = parser.parse_args()

    if args.selftest:
        return selftest()

    start = Path(args.repo).resolve() if args.repo else PROJECT_ROOT
    root = repo_root(start)
    if root is None:
        print(f"commit-paths: not a git repository: {start}", file=sys.stderr)
        return 1

    named = read_named(args)
    if not named:
        print(
            "commit-paths: name the paths to commit — as arguments, or with "
            "--paths-from-file. A commit of everything staged is what `git commit` is for.",
            file=sys.stderr,
        )
        return 1

    message = read_message(args)
    if message is None:
        return 1

    plan = resolve(root, named, pair_renames=not args.no_pair_renames)

    if args.message_file:
        source = Path(args.message_file)
        report_message(source, message)
        findings = stale_message(root, plan, source)
        if findings and not args.allow_older_message:
            print(
                "commit-paths: the message file was not written for this commit — nothing was "
                "committed",
                file=sys.stderr,
            )
            for finding in findings:
                print(f"  {finding}", file=sys.stderr)
            print(
                "  write the message again for this change, or pass --allow-older-message to "
                "take this file deliberately",
                file=sys.stderr,
            )
            return 1

    return commit(root, plan, message, dry_run=args.dry_run, listing=args.list)


if __name__ == "__main__":
    sys.exit(main())
