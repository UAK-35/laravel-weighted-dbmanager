# Agentic setup inventory and review

Created: 2026-10-30 (Asia/Karachi).

## Scope and method

This review covers the repository's agent instructions, local skills, OpenCode configuration,
configuration renderer, machine records and harness metadata. Supporting runbooks, tooling and
guards are listed separately below: they are dependencies of the setup rather than additional
agent definitions. Findings describe the checkout as reviewed; only the report naming rule and
the handoff records needed for this deliverable are changed. The defects below remain open.

The inventory includes tracked files and relevant ignored local files. Dependencies under
`vendor/`, copied application trees, IDE settings, Git internals and historical session transcripts
are excluded from agent setup. No files outside the package were searched. CodeGraph is absent;
the user chose to skip initialization. No Graphify output exists in this checkout.

The review used file inspection, reference resolution, comparison of the generated configuration
with its template and in-memory renderer probes. No OpenCode session, deployment, push, release
or network schema validation was performed. Runtime claims about OpenCode precedence are limited
to the semantics documented by the repository's own configuration comments.

## Agentic setup files

Paths below are relative to the repository root. Local-only file contents are not reproduced.

| File | Role | State |
| --- | --- | --- |
| `AGENTS.md` | Canonical scope, authority, load order, verification and report rules | Tracked |
| `BEHAVE.mdc` | Always-applied coding behavior guidelines | Tracked |
| `.agents/README.md` | Skill index and environment/bootstrap instructions | Tracked |
| `.agents/skills/wdm-commit/SKILL.md` | Commit workflow, notes, signing and validation | Tracked |
| `.agents/skills/wdm-push/SKILL.md` | Push workflow and remote tag verification | Tracked |
| `.agents/skills/wdm-deploy/SKILL.md` | Release and distribution workflow | Tracked |
| `.agents/skills/wdm-issue/SKILL.md` | Fault report activation and document workflow | Tracked |
| `.agents/skills/wdm-investigation/SKILL.md` | Investigation activation and document workflow | Tracked |
| `.agents/skills/wdm-plan/SKILL.md` | Planning activation and document workflow | Tracked |
| `.agents/manage-config.py` | Renders and checks the OpenCode configuration | Tracked |
| `.agents/sample.machine.local.json` | Bootstrap template for interpreter and machine facts | Tracked |
| `.agents/machine.local.json` | Actual interpreter, tools, paths and local facts | Ignored, present |
| `.agents/gate.json` | Recorded gate evidence consumed by the handoff renderer | Tracked |
| `opencode.jsonc.template` | Active harness configuration source, agents and permissions | Tracked |
| `opencode.v1-backup.jsonc.template` | Earlier configuration shape retained as a backup | Tracked |
| `opencode.jsonc` | Generated machine-specific harness configuration | Ignored, present |
| `HANDOFF.md` | Agent continuation state and rendered evidence | Tracked |
| `AGENTS.md.txt` | Older local copy of repository instructions | Ignored, present |
| `.freebuff/.gitignore` | Keeps harness project metadata and this local ignore file out of Git | Ignored, present |
| `.freebuff/project-id` | Harness project identity | Ignored, present; existence and ignore status reviewed |
| `.gitignore` | Excludes generated configuration, machine facts and harness/session state | Tracked |
| `.gitattributes` | Controls which developer instructions and tools ship in release archives | Tracked |

Referenced but absent: `.agents/agents/php-code-analyzer.md` and `.skills/README.md`.
These are missing dependencies, not files discovered in the inventory.

## Supporting files

| File | Relationship to agent setup |
| --- | --- |
| `README.md` | Required load-order document; development, interpreter and check instructions |
| `API.md` | Public API authority named by the agent rules |
| `RELEASING.md` | Release authority linked by the skills |
| `PUSHING.md` | Push authority linked by the skills |
| `STATIC-ANALYSIS.md` | Static-analysis authority named by the rules |
| `CHANGELOG.md` | Release notes required by the commit workflow |
| `composer.json` | Commands, dependencies and tool versions used by workflows |
| `phpunit.xml.dist` | Unit-suite configuration used by the gate |
| `phpstan.neon.dist` | Static-analysis scope and level |
| `pint.json` | Formatting policy |
| `.github/workflows/main.yml` | CI workflow used as release evidence |
| `bin/checks.php` | Agent completion gate and recorded run writer |
| `bin/interpreter.php` | Resolves and enforces the machine-record interpreter |
| `bin/tool.php` | Runs named developer tools |
| `bin/tools.php` | Tool entry-point/version manifest |
| `bin/handoff.php` | Renders continuation state and records description fingerprints |
| `bin/release.php` | Release procedure implementation |
| `bin/weighing.php` | Interprets commit subjects and release notes |
| `bin/counts.php` | Renders prose counts used by completion checks |
| `bin/inventory.php` | Produces release inventory records |
| `bin/surface.php` | Reads public surface for release decisions |
| `bin/api.php` | Produces the public API page |
| `bin/blame.php` | Reads provenance behind inventory changes |
| `bin/entry.php` | Relates commits, notes and releases |
| `bin/publish-config.php` | Checkout-side config publication helper |
| `bin/composer-ca.ps1` | Windows Composer wrapper required by the rules |
| `git/commit_paths.py` | Guarded commit helper named by the commit skill |
| `scripts/servers-startup/README.md` | Conditional database-test setup instructions |
| `scripts/servers-startup/servers.sample.cmd` | Portable server path sample |
| `docs/local-postgres-and-pgcat.md` | Database rig context |
| `docs/composer-tls-windows.md` | Composer wrapper rationale and use |
| `tests/Support/Handoff.php` | Continuation-state readers and pending-file declarations |
| `tests/Support/SkillDescriptions.php` | Skill description/index reader |
| `tests/Support/RepoEscapes.php` | Repository-boundary path reader |
| `tests/Support/MachinePaths.php` | Machine-specific path reader |
| `tests/Unit/Docs/SkillDescriptionTest.php` | Holds skill descriptions to known commands and index |
| `tests/Unit/Docs/DocCitationsTest.php` | Checks cited test names in its selected document corpus |
| `tests/Unit/Docs/HandoffTest.php` | Holds continuation state and fingerprints to the tree |
| `tests/Unit/Docs/HandoffEnvironmentTest.php` | Checks environment and maintenance claims |
| `tests/Unit/Support/RepoEscapesTest.php` | Checks repository boundaries |
| `tests/Unit/Support/MachinePathsTest.php` | Checks portable paths |
| `tests/Unit/Support/InterpreterResolverTest.php` | Checks interpreter resolution |
| `tests/Unit/Support/GateInterpreterTest.php` | Checks the gate's interpreter behavior |

Supporting files were consulted where needed to establish the findings. This is not a full audit
of release tooling, application code or every test fixture transitively used by these workflows.

## Findings

### F01 — High: the default agent's required definition is missing

Evidence: `opencode.jsonc.template:8` selects `php-code-analyzer`; line 22 tells it to read
`.agents/agents/php-code-analyzer.md` before acting. That file and its directory are absent.
The backup template has the same dependency, and the generated configuration matches the active
template, so regenerating does not repair it.

Impact: the default agent and `php-analyze` command cannot load their declared canonical behavior.
The registration itself exists; this finding does not claim the harness necessarily fails to start.
Restore the definition inside this repository or replace the registration with a complete available
agent. Check referenced prompt files when validating the configuration.

### F02 — High: renderer substitution does not escape JSON string contents

Evidence: `.agents/manage-config.py:101` substitutes a raw record value into a JSONC string.
An in-memory probe using a synthetic Windows path with backslashes produced a resource string
that Python's JSON parser rejected with `Invalid escape`. Quotes and control characters are
likewise not encoded. The current record uses forward slashes and renders successfully.

Impact: an otherwise legitimate machine record can generate an unreadable harness configuration.
Encode replacement string contents for their JSON context, or normalize and validate supported
path values before substitution. Validate the rendered JSONC before overwriting the current file.

### F03 — Medium: wrapped-command grants are shadowed by the later fallback

Evidence: `opencode.jsonc.template:56-69` allows wrapped Bash, PowerShell and PHP commands;
lines 96-99 then place a shell wildcard with effect `ask` after those specific rules. The file's
own comment says the last matching rule wins and broad rules must come first.

Impact under that documented rule: the wrapped invocations match the later wildcard and ask
instead of using the earlier grant. The backup template also places its shell wildcard ask
fallback after its explicit allows. Move fallbacks before the narrow grants. Separately,
the agent-level shell wildcard grant at lines 26-31 conflicts with the intent of a global ask
fallback; its effective interaction needs an installed-harness check. This review did not establish
agent/global merge precedence or validate the advertised V2 schema.

### F04 — Medium: skill links resolve to nonexistent runbooks

Evidence: `wdm-deploy/SKILL.md:15,83` and `wdm-push/SKILL.md:11,52,82,88` link to runbooks
using two parent segments. Resolved from each skill directory, their targets are inside `.agents/`,
where neither runbook exists. The actual runbooks live at the repository root.

Impact: following the authoritative links fails. Correct the Markdown targets to reach the root
and check link existence from each document's own directory, rather than interpreting links as
commands run at the package root.

### F05 — Medium: the sample machine record bootstraps stale facts

Evidence: `.agents/sample.machine.local.json:5-6` supplies PHP `8.4.26` and `onPath=false`,
whereas the current local record and handoff state `8.4.27` and `true`. Its skill list includes
only commit, deploy and push, while the index and filesystem include issue, investigation and
plan as well. Its `about` text instructs users to replace tokens but leave version rows alone.

Impact: following the bootstrap procedure can create a record that disagrees with the actual
interpreter and the skill index. Refresh repository-owned lists and require users to measure
machine-owned versions and availability; do not describe those as invariant defaults.

### F06 — Medium: the planning skill omits the required fingerprint step

Evidence: `.agents/skills/wdm-plan/SKILL.md:54-58` instructs adding a pending description and
running `bin/handoff.php --recorded`, without first recording its reading. The issue and
investigation skills explicitly include `--reaffirm=<path>`. `tests/Support/Handoff.php:1773-1796`
requires both a description and a matching fingerprint.

Impact: following the plan procedure literally does not establish the reading needed to render
the new pending row. Add the reaffirm step before rendering, matching the sibling procedures.

### F07 — Medium: push examples select main even for a dev prerelease

Evidence: the deploy skill explains that prereleases come from `dev`, but its publish command
at line 79 pushes `main`. The push skill's command and preflight range also hardcode `main`.

Impact: a reader following those commands after cutting a prerelease can push another branch;
`--follow-tags` only transports eligible tags reachable from the pushed history, so the intended
release is not guaranteed to travel. Derive or explicitly select the release branch and use it
consistently in preflight, push and verification. Nothing was pushed during this review.

### F08 — Low: missing-record exit code contradicts the renderer's contract

Evidence: `.agents/manage-config.py:36` documents exit 2 for a missing template or record.
`read_record()` raises `Refusal` for an absent record, and `main()` catches that at lines 153-156
and returns 1. An in-memory probe pointing `RECORD` at a nonexistent path returned 1.

Impact: scripts classifying setup failures by the documented exit codes receive the wrong class.
Either return the documented code or correct the contract and its callers together.

### F09 — Low: interpreter instructions contradict the current environment record

Evidence: `AGENTS.md:93` and `.agents/README.md:33` assert PHP is absent from `PATH`.
The machine record says `onPath=true`, and the handoff's PHP row says it is on `PATH`.

Impact: onboarding supplies incompatible explanations of the same environment. Keep the sound
instruction to use the recorded interpreter, but describe availability as a measured machine fact.

### F10 — Low: marker activation is internally ambiguous

Evidence: issue, investigation and plan skills describe direct `@wdm-...` activation in their
frontmatter, but their bodies enumerate only an opening slash marker and a keyword question.
Each body then says that a slash marker being present always activates it, broadening the earlier
requirement that the prompt open with that marker.

Impact: a literal reader can ask on an explicit mention, or activate a skill for a marker quoted
inside unrelated text. State the same precedence in frontmatter and body: explicit invocation,
opening slash command, then optional keyword confirmation, with quoted examples excluded.

### F11 — Low: document path guidance conflicts with repository-relative examples

Evidence: issue, investigation and plan skills require paths to be `absolute-Unix only` in their
completion sections while their own instructions and the canonical rules use repository-relative
paths. A Unix absolute path is not inherently confined to the repository either.

Impact: an agent can rewrite portable references into paths that have no defined root in this
Windows checkout. Require repository-relative references for package files, and reserve generic
Unix absolute paths for clearly identified deployment examples.

### F12 — Low: maintenance and index claims have not followed the setup

Evidence: the header of `AGENTS.md` broadly says there is no generator, while section 2 names
the configuration renderer. `.agents/README.md` says the other two files in that directory are
records, omitting the sample and renderer. Both OpenCode templates refer to `.skills/README.md`,
which is absent; the real index is `.agents/README.md`. The handoff's later maintenance explanation
also mentions only the commit helper as the Python exception, whereas its environment test already
allows the config renderer too.

Impact: a fresh session receives stale discovery and maintenance guidance. Qualify the no-generator
claim as applying to skills and rules, update the directory inventory and point to the real index.

### F13 — Low: an ignored rules copy preserves obsolete workflow names

Evidence: `AGENTS.md.txt` differs from `AGENTS.md`, lacks the newer load-order entries and names
`pkg-commit`, `pkg-push` and `pkg-deploy` instead of the available `wdm-...` skills.

Impact: manually importing that local copy sends an agent to unavailable skills. It is ignored
and is not loaded by the active configuration, so this is a local maintenance hazard rather than
a fresh-clone defect. Remove or clearly label the obsolete copy; keep `AGENTS.md` authoritative.

### F14 — Medium: document coverage claims exceed the guards' actual corpus

Evidence: `AGENTS.md` section 3 says every record under `docs/` is read by the documentation
guards. However, `tests/Unit/Docs/DocCitationsTest.php:161` selects only Markdown files directly
inside `docs/`, and `bin/checks.php:1837` uses the same nonrecursive selection for sentence checks.
Files in the issue, investigation, planning and new reports subfolders do not match that selection.

Impact: a green gate does not establish the citation or sentence guarantees implied for those
nested records. The report's own direct path inventory and focused checks supply specific evidence,
but they do not extend the guard corpus. Make the relevant readers recursive with intentional
exclusions, or narrow the completion rule to state exactly which records each check reads.
Repository-boundary checks and pending-file registration are separate and do cover this report.

### F15 — Medium: the handoff's remote snapshot no longer describes the remote

Evidence: the full gate and a separate focused rerun of the remote-origin check both report that
`HANDOFF.md` names `origin/dev` as `a99c22b`, while the remote answers `d9e8db5`. The latter is
neither that recorded tip nor an allowed checkout/parent exception. This was observed during
validation on 2026-10-30; it is a time-specific finding rather than an assertion about future refs.

Impact: a continuing agent can trust an obsolete account of which commits remain unpushed, and
the handoff guard fails. Re-take the remote-dependent handoff state after reconciling the intended
local and remote history. This review updated only the new report's pending entry; it did not
rewrite the older snapshot or alter branch history.

## Report numbering and changes made

There were no existing reports and no `docs/reports/` directory at the start. This report therefore
starts at `0001`; no files were renamed. The underscore before `rpt-` follows the requested pattern.
`AGENTS.md` section 5 now owns the naming and chronological normalization rule, including provenance
fallbacks, deterministic ties, reference updates and preservation of existing report dates/content.
The separate issue, investigation and planning naming conventions are unchanged.

The pre-existing untracked investigation and planning documents were left intact. Pending-file
declarations and the rendered handoff are updated only as required to register this report and rule.

## Verification

- The existing handoff renderer check passed before this change: all its blocks were in step.
- Generated `opencode.jsonc` matched the current template after substitution.
- In-memory probes reproduced F02 and F08 without rewriting configuration or machine records.
- Every file listed in the inventory tables exists; ignored local setup files were confirmed ignored.
- The path and skill checks passed: 72 tests, 114 assertions, covering `RepoEscapesTest`,
  `MachinePathsTest` and `SkillDescriptionTest`.
- Focused pending-file and renderer checks in `HandoffTest` passed: 4 tests, 6 assertions.
- After the full run, all five relevant pending-file/rendering checks passed together: 5 tests,
  11 assertions, including the generated-block comparison.
- `bin/handoff.php --check --recorded`, Pint on `tests/Support/Handoff.php` and
  `git diff --check` passed after registering the report.
- Post-edit `bin/checks.php --only=counts,sentences` passed both checks. F14 explains why the
  sentence check's success does not establish coverage of this nested report.
- Full `bin/checks.php`: exit 1; 12 checks passed, the unit check failed, no gate checks skipped,
  elapsed 1110.8 seconds. The unit run reported 1683 tests, 11330 assertions, one error,
  four failures and nine skipped tests.
- Three handoff problems in that full run came from starting its PHP process before this
  report's declaration was added: the long-lived process retained the earlier class definition.
  They were the pending-row reading, declaration-block comparison and generated-block rendering.
  All passed in the post-edit five-test rerun above. The initial full run is not claimed green.
- Two failures remain independently reproduced: the stale remote snapshot in F15, and
  `DatabaseHealthControllerTest::test_every_key_the_window_block_publishes_is_documented`.
  The latter finds `last_refusal`, `last_refusal_at` and `last_refusal_mode` in the published
  window key set but not its expected README key set. It reproduced alone (1 test, 2 assertions,
  1 failure). It is outside the agent-setup review; neither its implementation nor README table
  was changed. The remote-origin test likewise reproduced alone (1 test, 2 assertions, 1 failure).

No new tests are added for this documentation deliverable. Passing PHP checks cannot establish
OpenCode schema compatibility or effective harness permission behavior; those remain unverified.
