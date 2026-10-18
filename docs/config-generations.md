# The published config's generation

`config/db-manager.php` is copied into an application once and then edited there, and nothing writes
over it again unless somebody asks for `vendor:publish --tag=db-manager-config --force`. That is the
behaviour the package's setup depends on — the published copy *is* the application's configuration —
and it is also the reason a published copy ages in a way no other file in an installation does: a key
added to the sample afterwards is a key the application never receives, so the installation keeps
reading the documented default for it while the changelog describes a release that does something
else. Nothing is malformed, no row fails, and every reading of the file is of a key set that stopped
moving at the moment it was published.

The file therefore declares the **key set** it is, and one row compares it with the package's.

## One number, owned by the class that reads it

`config/db-manager.php` carries a top-level `generation`, and
`Uak35\WeightedDbManager\Support\ConfigSchema` owns the key name, the number this package ships, the
release each generation was introduced in and every sentence a copy behind it is reported with.

The number is raised when the sample's *keys* move — a key added, removed or moved. It is **not**
raised for a changed default, a reworded comment, or a new env var behind a key that already exists,
because none of those is something a published copy has to be republished to keep working. Nothing
breaks silently if it is not raised, which is exactly why it is a number a preflight compares rather
than something a reader is expected to notice.

A version cannot answer this question. `v0.2.0-alpha3` names a release and says nothing about what is
in a file somebody may have edited thirty times since publishing it from `v0.2.0-alpha1`; the one
thing worth asking is whether the file's *keys* are current, and the number that answers it is about
the key set rather than about the code.

| Generation | Introduced in | What moved |
|---|---|---|
| 1 | `v0.2.0-alpha5` | the generated key set the row reads: the generation itself, and the schedule watchdog's `schedule.stale_after_missed_runs` |
| 2 | `v0.2.0` | the repair rate's two settings, `pgcat.pool_repairs.window_seconds` and `pgcat.pool_repairs.max_repairs` — the reading [pool-repair-rate.md](pool-repair-rate.md) is about |

`ConfigSchema::RELEASED_IN` is the map, and `ConfigSchemaTest::test_every_generation_names_the_release_that_introduced_it`
holds an entry for every generation the class ships, so a generation added without a release named
for it fails rather than warning in a sentence that names the number twice. A generation this package
ships but no tag carries yet — the state between the commit that raises it and the release that
publishes it — is what the table above states as the version the notes weigh rather than as a tag a
consumer can install.

## Where it is read

`db:doctor`'s `published config` row asks it, in the same row that already reports whether an
application published the file at all and whether anything in it was reviewed. That row has four
answers now, and each of them is its own state rather than a shared silence:

| The file | The row | Why that verdict |
|---|---|---|
| no `config/db-manager.php` | `WARN` | the sample is in use; nothing has been chosen for this installation |
| byte-identical to the sample | `WARN` | it was published and no value was reviewed |
| at this package's generation | `PASS` | the keys are current, whatever the installer decided to put in them |
| behind it, or no `generation` at all | `WARN` | the keys added since are read from their defaults — a republish and a review, not an error |
| a `generation` that is not a whole number | `WARN` | a value to fix, which is a different repair from a copy to republish |
| ahead of it | `WARN` | the extra keys are ignored by this package, which a reader rolling a package back wants to see rather than infer |
| unreadable as a config file | `WARN` | the file's key set cannot be placed at all, and the reason is carried into the row |

The reading is of the **file**, not of the configuration repository. `config('db-manager.generation')`
is the merge of the sample and the published copy, with the copy winning key by key, so a published
file that predates the key would read as the package's own generation — the silence the reading exists
to break. `DbDoctor::publishedGeneration()` reads the array the file returns, inside a buffer and with
the buffers it opened closed again, because a config file may print and a file that throws has to be a
row rather than a doctor that died. `DbDoctorTest::test_the_published_config_row_reads_the_key_set_the_file_itself_declares`
pins the five states, and `ConfigSchemaTest` holds the sentences.

The sample's literal and the class's number are held to each other in both directions —
`ConfigSchemaTest::test_the_sample_config_declares_the_generation_this_class_ships` — because neither
drift is visible in a diff of either file: a number raised in the class and not in the sample would
make every freshly published copy read as stale the moment it landed, and a number raised in the
sample alone would make a copy behind it read as current.

## What a publisher must not do to the keys this package reads as switches

The generation answers *whether* the key set is current. It cannot see a value somebody changed in a
way that turns a setting into its opposite, and the published copy is edited by an installer, so the
shapes below are the ones worth knowing. Each is a line that works — it parses, it boots, and nothing
in the package refuses it — and each changes what the package does.

* **A cast in front of a switch.** `(bool) env('SWRR_PGCAT_ENABLED', true)` reads the spellings an
  operator actually writes — `'off'`, `'no'`, `'false'` — as **on**, because `(bool) 'off'` is true.
  The sample deliberately writes its switches uncast and reads them with `Support\SwitchValue`, and
  the boot audit and `db:doctor`'s `switch values` row refuse a value that is neither on nor off. A
  cast in the published copy removes both readings: the value never reaches the classifier, so the
  refusal never fires and the setting means its opposite. The sample's own comment on
  `allow_local_fallback` is the worked example.
* **A fallback chain ending at another driver's connection.** `swrr.connection` is the connection the
  pgcat surfaces judge and the flipper follows. A chain that ends at a literal name — `env('SWRR_CONNECTION', 'sqlite')`,
  or an application's own default — arms pgcat against a connection that is not PostgreSQL, which
  `db:doctor`'s `pgcat gate` row reports as a switch armed where it cannot act. Ending the chain at
  the application's real data path, or leaving it unset so the package follows `database.default`, is
  what keeps the two in step.
* **A key deleted because it "looks unused".** A key an application does not set is a key it inherits
  the default for, and the default is documented; a key deleted from the published copy is a key the
  package reads from *its own* sample, which is the same value on the day it is deleted and a
  different one the next time the package changes a default. Deleting a key is the one edit the
  generation cannot report, because the file's own number still says the key set is current.
* **A value moved between blocks.** `swrr.pgcat.schedule` and `swrr.pgcat.windows` are two mechanisms
  that can own one mode; a value moved from one to the other is a setting that is still written down
  and no longer read. The rows that own those keys print what they read, which is how a moved value is
  noticed — but nothing compares a key's *position* with the generation, so the row that dates it is
  the one that reports it.

None of these is a fault the generation can be raised for: the key set is exactly what it declares,
and the value is the installer's to choose. What the record is for is that the choice be a visible
one — the rows that read these keys print the connection, the switch value and the mode they found,
so a copy that has been edited differently from what the changelog describes says so.
