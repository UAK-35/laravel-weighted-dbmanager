# What should the inventory record besides files and methods?

A design record for `surface.tsv`, the inventory's third file.

`composer release -- --weigh` decides the bump from four signals and keeps the loudest,
so no signal has to be complete — each is read on its own. Three of them answer the same
way: the notes say what the author thought changed, the commits say what was typed, and
the public surface of `src/` and `config/` at HEAD is diffed against the last tag. This
document is about the fourth, the inventory, and the question writing it down raises:
*what should it hold?*

Everything below is implemented in `bin/surface.php`, written by `bin/inventory.php` and
`bin/release.php`, and specified for a reader in the [inventory section of
RELEASING.md](../RELEASING.md#the-inventory).

---

## The answer in one line

**Every symbol a consumer can name that is not a file and not a method — config keys, env
vars, public constants, enum cases and public properties — in one file, one row per
symbol, with a `kind` column that is the word the report uses.**

---

## Why this needed deciding at all

### 1. The tag diff needs two tags

The public-API signal diffs the tree against `git describe --tags --abbrev=0`. On a tree
with no tag at all there is no base, the weighing says so in its own words ("the notes
decide, and the inventory can only witness what changed after it was written"), and the
first release is numbered from its notes. That is deliberate — the alternative is calling
every symbol in a new package *added* and shipping `0.1.0` no matter what it contains —
but it does mean the only signal left on such a tree is a written-down inventory.

### 2. Even with a tag, the diff is between tags

A key removed and restored between two releases is invisible to a tag diff, and a key
removed in an unreleased commit is invisible until the release that publishes it. The
inventory is the only surface that can be compared at HEAD, and it can only witness what
it recorded: a file and a public method. The rows a *consumer* sets rather than imports —
`swrr.…`, the env vars, the constants a class publishes — went unwitnessed by anything
except the tag diff, which is exactly the signal that is unavailable when they matter
most.

### 3. The reader already knew how to see them

`fileSurface()` has always returned `const:`, `case:` and `property:` keys beside the
methods, and `configSurface()` returns `config:` and `env:`; both are fed into
`describeSymbol()` for the report's spelling. The inventory simply never asked for them,
so the change is mostly about **which** rows to write down and what a missing row means.

---

## The candidates

| # | shape | what it costs | verdict |
|---|---|---|---|
| A | extend `methods.tsv` | a file named for methods holding constants, cases and properties; the release notes, the fixtures and the reader's own docs all describe it as methods | **rejected** — the name is load-bearing, and a rename is a breaking change to a shipped artifact |
| B | two files, by kind: one for config, one for members | one more file than C, and the kind decides the file — so a row's kind is stored twice, once in its name and once in its columns | **rejected** — the split adds a stamp and a write to keep in step and says nothing the kind column does not |
| C | one file with a `kind` column | the third file to write, commit and compare; one more thing that can be missing | **chosen** |
| D | record nothing; trust the tag diff | no new artifact and no new rule | **rejected** — it is the case in §1 that cannot be answered at all, and the one in §2 that is answered late |

### A — extend `methods.tsv`

The cheapest in bytes and the most expensive in meaning. Its columns are a method's
(`method`, `file`, `class`, `signature`), its header says so, `README.md` and
`RELEASING.md` both describe it as the public-methods file, and the release rail's own
notes name it. A file whose name is wrong is a file every later reader has to be told
about, and the fix for that is a rename — which is what D would have been.

### B — split by kind

`config.tsv` for the keys and env vars, `members.tsv` for the constants, cases and
properties. It reads well until the second row kind: the file a row belongs in is decided
by its kind, so the kind is stored twice and can disagree with itself. It also doubles the
write surface the whole-or-nothing rule below has to cover, for no gain.

### C — one file, one kind column (chosen)

Columns `kind`, `symbol`, `file`. The kind is the parser's own word, so the report's
spelling comes from `describeSymbol()` — the one place a symbol becomes a sentence — and
a removal reads as `removed public constant Fixture\Thing::VERSION` rather than as a line
that moved. Grouped by kind and alphabetical inside it, so a diff shows one removal in one
place.

`surface.tsv` is the name because "surface" is what this codebase already calls the set of
symbols a consumer can name (`bin/surface.php`, `surfaceDiff()`, `surfaceSignal()`), and
these rows are its non-method half. It is also the file name that stays true if a later
row kind is added.

### D — nothing

Attractive because the tag diff already covers these symbols, and it is the signal that
decides the bump. What it cannot do is answer on a tree with no tag, or a tree whose
removal happened after the tag it will be compared against — which is every removal that
has not been released yet.

---

## The rule the third file forced: whole, or not at all

A file that is not there cannot say whether the rows it should hold were never written or
were just removed. `diffInventory()` compared a missing `surface.tsv` as an empty one
would count every config key and constant in the tree as *added* since the last release —
a minor nobody asked for, from a file that was simply absent.

So the weighing now checks all three files before it weighs anything, and reports the
inventory as **incomplete** when one is missing: the plan says which file, the evidence
line says an inventory is weighed as a whole, and the bump is left to the notes, the
commits and the tag diff. This is not a corner case. It is the state of every tree whose
inventory was written before this file existed — including the repository this package is
developed in, until the next release writes all three.

`bin/inventory.php`'s rewrite warning takes the same line for the same reason: a rewrite
can only discard a record it read, so a half-present set is completed in silence rather
than described as discarded.

---

## What writing the rows exposed

`fileSurface()` reads the visibility of a property by walking back from the `$variable`.
A declared type stands between the two — `public string $label` — and the walk stopped at
the type name and returned `null`, so **every typed property was invisible**: to the tag
diff that has consumed this reader since it was written, as well as to the new rows.
Fourteen are visible in this package now.

The fix is to walk past a type name, and past the punctuation of a nullable, union or
intersection type, when the walk started at a variable. Only a variable is read that way,
so nothing else can stand between a visibility and the member it belongs to — which is
what makes the walk safe to extend rather than merely necessary.

---

## Tests that pin the rules

| rule | test | in |
|---|---|---|
| the third file holds the keys and members a tag diff cannot see, the typed property among them | `test_the_surface_rows_record_the_keys_and_members_a_tag_diff_cannot` | `InventoryTest` |
| a record built for a ref holds that ref's rows and that ref's stamp, and not the checkout's | `test_at_reads_the_tree_the_ref_holds_rather_than_the_checkout` | `InventoryTest` |
| a record of another release is refused rather than written over, with `--force` as the escape | `test_at_refuses_to_write_over_the_record_of_another_release` | `InventoryTest` |
| `--check` reports a constant the tree no longer declares | `test_check_reports_a_constant_the_tree_no_longer_declares` | `InventoryTest` |
| ...and a config key the config file no longer returns | `test_check_reports_a_config_key_the_file_no_longer_returns` | `InventoryTest` |
| a constant removed on a tree with no tag is witnessed by the inventory alone | `test_a_constant_removed_with_no_tag_at_all_is_witnessed_by_the_inventory_alone` | `BumpWeighingTest` |
| an inventory missing one of its files is incomplete and never moves the bump | `test_an_inventory_missing_one_of_its_files_is_incomplete_and_never_moves_the_bump` | `BumpWeighingTest` |
| a rewrite that read only part of the set says nothing about discarding | `test_a_pair_that_is_half_there_discards_nothing_it_did_not_read` | `InventoryTest` |

Mutations these were measured against: not reporting a removed row fails one test (the
no-tag witness), reading an incomplete inventory as an empty one fails one (the bump moves
to a minor the notes did not ask for), restoring the reader's behaviour of stopping at a
type name fails two, warning about a discarded record the rewrite did not read fails one,
and building the rows off the checkout instead of out of the ref fails one (the backfill
case, which is the only reason the second reader exists).

---

## Known limitations

1. **Presence, not value.** A row says a config key exists, not what it returns, so a
   changed default is not witnessed by the inventory — the tag diff sees the line change
   only because the file is read as text there. Recording values would make the file a
   copy of the config, and a config key's default is not a compatibility promise in the
   way a signature is.
2. **A move is a removal and an addition.** A constant renamed across classes, or a config
   key moved between files, is two rows and reads as such. The file paths beside a symbol
   are recorded, so a later rule could pair them; nothing does today.
3. **The inventory is a snapshot, not a history.** It describes the tree at the moment the
   release wrote it, so a change made and reverted between two releases leaves no trace —
   which is the same reason a tag diff cannot see it, and the property the stamp depends
   on. A past tree's rows can be *rebuilt* — `bin/inventory.php --at=v0.2.0` reads them out
   of git and stamps the release that tree is, which is what makes a tag with no written
   record recoverable — but rebuilding does not add a record: there is still one slot, and
   a ref whose tag is not the one the record describes is refused unless `--force`.

---

## What would change this decision

- **A second stored inventory.** If the rail ever kept the inventory of *every* release,
  values could be recorded and compared; today only one is written and only one is read —
  and `--at` rebuilds any of them, one at a time, into that one slot.
- **A consumer-facing API report.** If the package published a surface document for
  consumers, `surface.tsv` would be the artifact to derive it from rather than a signal
  doing double duty.
- **If `--check` were wired into CI.** Then a missing third file is a red build rather
  than a note in a plan, and the incomplete case would need to be a failure instead of a
  skip. RELEASING.md says why it is not: an inventory kept in step with every commit can
  never witness a change.

---

## Files

| file | role |
|---|---|
| `bin/surface.php` | the rows (`inventoryRecords()`), the comparison (`surfaceRowDiff()`, `diffInventory()`), and the reader they exposed (`fileSurface()`, `visibilityBefore()`) |
| `bin/inventory.php` | writes all three files, reports drift, warns about a discarded record, and builds them for any ref (`--at`) |
| `bin/release.php` | writes them in the release commit, weighs them as the fourth signal, reports an incomplete one |
| `tests/Unit/Release/InventoryTest.php` | the generator: the rows, the drift, the exit codes |
| `tests/Unit/Release/BumpWeighingTest.php` | the weighing: what a fresh, stale, missing or incomplete inventory may do |
| `tests/Support/ReleaseRepo.php` | the fixture the two suites drive the real scripts in |
| `RELEASING.md` | the reader-facing specification of all three files and the signal |
