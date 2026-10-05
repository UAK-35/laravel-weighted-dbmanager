# `.agents/` — the skills for developing this package

These are for working **in this repository**
(`E:\_WORKS\lpr\work\packages\laravel-weighted-dbmanager`). They are hand-maintained: unlike
the application repository (`E:\_WORKS\lpr\work\LPR\site`), this package has no
`.skills/` sources, no `_sync.py` generator and no `verify.py` guard, so a file here is the
only copy and is edited in place. Every tool that reads `.agents/skills/<name>/SKILL.md` finds
them; the frontmatter `description` is what decides when one is invoked.

| Skill | What it does |
|---|---|
| `pkg-commit` | commit a change here: the message shape `bin/weighing.php` reads back, the `## Unreleased` entry that decides the bump, the signature, and the two files that are never committed |
| `pkg-push` | get commits and annotated tags onto GitHub from PowerShell 7, recognise the two refusals, and prove the tag landed in three layers |
| `pkg-deploy` | publish a version: weigh the bump, promote the CHANGELOG, tag it, push it, confirm Packagist knows it, and hand the consumer the pin to raise |

The written-down procedures they wrap are [`RELEASING.md`](../RELEASING.md) (what a release
is, and what `bin/release.php` does at each step) and [`PUSHING.md`](../PUSHING.md) (the
remote, what authenticates here, and why MSYS fails). Those two files are the authority; a
skill here names them rather than restating them, so the two cannot drift.
