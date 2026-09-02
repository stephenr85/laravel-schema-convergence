# Convergent migration guards — convention

**Status:** canon for every family package that publishes a `create_*` migration — **whatever its
vendor, and whether or not it depends on `splicewire/laravel-beam`**.
**Decided:** 2026-08-18, after the same collision was sighted three times in one sweep.
**Generalised:** 2026-08-19 (beam-facade tickets 34/35). The rule was written when the guard lived in
beam, so it was phrased as an obligation on packages publishing *into a beam host*. That was the
mechanism's address, not the rule's reach: five `rushing/*` packages carrying no beam dependency
publish `create_*` stubs into the same hosts, and a collision does not care which vendor lost. The
guard moved here so they can obey it.
**Mechanism:** `Rushing\SchemaConvergence\ConvergentTable` — the fluent guard this document governs
the use of, shipped by this package. This page states the rule; the class documents the machinery.

This is one half of a two-part collision family. The other half —
`splicewire/laravel-beam`'s [`migration-publish-ordering.convention.md`](https://github.com/stephenr85/laravel-beam/blob/main/docs/agents/migration-publish-ordering.convention.md)
— covers **cross-package ALTER-vs-CREATE**, where a package's ALTER is stamped ahead of the CREATE it
depends on, plus the installer's filename-order lever for the case where the competitor's guard is one
we do not own. Neither page is complete alone: that one is about *sequencing packages*, this one is
about *two CREATEs of the same table*, which no amount of sequencing fixes because both sides believe
they own it. It stays in beam because the installer that spends it does.

## The posture is AFFIRMED, and the fear it was dated against was mostly not real

**Ruled 2026-08-27, beam-facade ticket 115, superseding the 2026-08-24 "this posture is dated"
preamble that ticket 86 installed here.**

86 held this page open on the ground that *"a mechanism whose job is to silently top up a live table is
a different proposition"* once a deployed host carries real data. 115 checked what the mechanism
actually does and the premise does not survive it: **`ConvergentTable` has no destructive verb.**
`converge()` performs exactly three writes — `create` a table that is absent, `addColumn`, and
`convergeIndexes` — and every `->change()` in this package is a docblock or a remediation string
`SchemaConflict` prints for a human to run. Nothing executes it. There is no drop, no rename, no type
conversion, and there never has been.

So the invasive verbs the posture was feared for **were never built**, and column changes and removals
are gated by their own trigger (the `->change()` extension point, ruled out at ticket 22 and still
unfired) rather than by this posture. Additive convergence against a populated table does not diverge a
schema and does not lose data, and the three refusals already cover the additive cases that are unsafe
— a type conflict, a NOT-NULL-no-default column added to a table that holds rows, and NOT-NULL residue.

**What this means for a reader.** The two holds 86 placed are lifted. Adding a guard to a new `create_*`
stub is correct, as it always was; extending the regime on the strength of this page is no longer the
thing to hold back. The Scope section below stands unamended.

**One behaviour was going to change, and it is now ruled OUT (2026-09-02, beam-facade 184).**
`ColumnTypeEquivalence` returns an *unverified* verdict for a type pairing it has no mapping for, and that
is advisory — calibrated on *"a false conflict stops an install; a missed one leaves the status quo."*
115's exit proposed that `unverified` escalate to a `SchemaConflict` when, and only when, the table is
populated. **It does not, and will not**, on the estate's standing rule
(`~/Workspaces/splicewire-ecosystem/AGENTS.md`, *"A check whose answer depends on the host must not
throw"*): whether a table holds rows *here* is a fact about the host, and a host fact licenses an advisory
finding, never a fatal — 317 of the estate's 329 convergent declarations call the throwing terminal, so the
escalation would have turned a package-level map gap into an install-stopping refusal keyed on a host's
rows. The defect it was for is covered **advisorily** end to end: 183a below for the map gap with no
database, and 187 (`beam.schema.unverified-populated`, in `splicewire/laravel-beam`) for the rows half at
any beam host. Its population read **zero** on the day it was ruled — 240 convergent stubs / 17 declared
types all mapped against a 29-type map at `~/Herd/splicewire-app`; 151 of 185 declarations rehearsed
there, none `unverified` on a table holding rows. `SchemaConflict::requiredAddition` (the `$populated` read
at `ConvergentTable.php:329`) is unchanged: it refuses an addition that *cannot be made correctly* under
any host — the declaration's own omission of a nullable/default — which is why it is not the precedent
the escalation was argued from.

**The file-only half of the measurement is built and run** (183a):
`Rushing\Surgeon\Conformance\UnmappedConvergentTypeAudit`, on surgeon's always-on channel, reporting which
declared types across a root's convergent stubs have no entry in `ColumnTypeEquivalence` and would
therefore go unverified.

**Measured 2026-08-27 across every `~/Herd/*` root that installs surgeon — 14 roots, 698 convergent stubs:
exactly one unmapped type, `enum`, in one stub** (`create_federation_grants_table.php.stub`, reaching two
roots because tower is vendored into the flagship). Nothing else in the estate declares a type the map
cannot judge, and **zero** Blueprint methods went unclassified.

~~The `enum` mapping is deliberately **not** added in the same change.~~ **SHIPPED 2026-08-29
(beam-facade 184's precondition half).** The reasoning that held it back was right and is what shaped the
entry: Laravel compiles `enum()` to `varchar(255)` plus a check constraint on Postgres, `varchar` plus a
check on sqlite, `nvarchar(255)` plus a check on SQL Server, and a real `enum` only on MySQL/MariaDB — so
the entry is per-driver, and getting it wrong converts an UNVERIFIED (safe, advisory) into a **false
conflict that stops an install**, the exact asymmetry this page's calibration warns about.

**So the entry carries its precision half in the same change: `enum` is SOFT.** A soft type is recognised
positively and never judged negatively — `matches()` returns `null`, not `false`, for a live column
outside the modelled compilation (a native Postgres enum type, a domain, a `set`). **The property that
buys is one-directional: a soft entry can turn UNVERIFIED into TRUE and can never arm a conflict that was
previously dying quietly on a `null`.** That is what makes the mapping safe to land ahead of the
escalation rather than only alongside it, against a population where 317 of 329 convergent declarations
call the throwing terminal.

⚠️ **And it introduces one blind spot, named here rather than left to be discovered.** The comparison is
between type *names*; a soft type's real content is its allowed-value set, which lives in a check
constraint or in the type's own parameters and is not what `Schema::getColumns()` reports as `type_name`.
So `enum(['a','b'])` against a column constrained to `['a','b','c']` verifies **clean**. Convergence has
no verb that could repair that anyway — but TRUE here means *"the compiled storage type agrees"*, not
*"the constraint agrees"*.

The other half of the measurement — *which of those sit on a table that holds rows* — is beam-tier, because
learning a stub's declared shape by executing it needs `MigrationRehearsal`, which lives in
`splicewire/laravel-beam` and cannot be reached from surgeon without inverting the tier. **Consequence
worth stating: the five beamless `rushing/*` packages carrying convergent guards get the type census and
not the rows census.**

**That half is now built and run** (beam-facade 187):
`Splicewire\Beam\Doctor\UnverifiedOnPopulatedTableAudit`, registered in `BeamDoctorManifest` and
advisory permanently, because whether a table holds rows *here* is a fact about the host. It rehearses
two populations — the published copies a host's next `migrate` will run, and the package templates a
`vendor:publish` would stamp in — and reports every `unverified` column whose table exists and is
non-empty, deduplicated by `table.column` so a stub and the copy stamped from it are one pairing.

**Measured 2026-08-29 across all 15 `~/Herd/*` roots that vendor `laravel-beam`** (realpath-deduped, so
`beam`/`satellite`/`tower` count once as their starters). 14 ran; `beam-pilot-gcp-cloud-run` could not,
because it vendors a *copied* `laravel-beam` directory dated 08-25 rather than a symlink, so no audit
added since then exists there at all.

**Exactly one pairing in the estate, and it is at the flagship only:**

```
~/Herd/splicewire-app   public.federation_grants.pin_mode      (table holds rows)
declared  $table->enum('pin_mode', ['head','pinned'])->nullable()
          splicewire/tower  create_federation_grants_table.php.stub
live      varchar + CHECK (pin_mode = ANY (ARRAY['head','pinned']))
```

Two findings sharpen the ordering constraint this page already states, and both cut the same way:

- **The pairing reaches the flagship through the package TEMPLATE only.** The flagship's own published
  `create_federation_grants_table` is a squashed, host-owned plain `Schema::create` carrying **no
  convergent guard at all**, so no migration this host will run can escalate. The refusal would require
  republishing tower's stub over the host copy.
- **`laravel-tower-starter`, the one other root that stub reaches, has no `federation_grants` table.**

So the escalation's only real-world firing needs a deliberate republish, and what it would refuse there
is a **correct** column — which is exactly why the `enum` mapping must ride in front of, or in the same
change as, 184.

✅ **It rode in front, on 2026-08-29, and the consequence is worth stating plainly: the estate's
unverified population is now ZERO.** That pairing verifies (`enum` → `varchar`), and it was the only one
any of the three instruments could find. **`unverified` is a real mechanism with no live instances**, so
every remaining question about escalating it — including 184's — is now reasoned against a zero-instance
dataset and must be manufactured to be answered at all. The measurements above are kept as they were
taken; they are the record of a population that has since been closed rather than a reading of today.

The audit reports what it could not read, on every outcome including a clean one: declarations skipped
unrehearsed, declarations that rehearsed and yielded no convergence report, and tables whose row read
could not be taken. Across the 14 roots the latter two are **zero**; skips run 2–32 per root (the
flagship's 32 of 182) and are `RehearsalSafety` refusals, which must stay refusals.

⚠️ **The one hazard that does survive, and it is additive.** Convergence adds a column and fills it with
NULL or the declared default. **Nothing backfills it.** A package that adds a column mid-life gets a
correct schema and meaningless data on every pre-existing row, where a hand-written migration would have
carried a backfill. This is invisible on greenfield — there are no pre-existing rows — and it is a
package-authoring problem rather than a guard behaviour, so it is not fixed here. It is owned by its own
ticket on the beam-facade map.

## The rule

> **A published `create_*` migration declares the shape it needs, not the table it creates.**
> Guard it with `ConvergentTable`, never a bare `Schema::hasTable($t) → return`.

```php
use Rushing\SchemaConvergence\ConvergentTable;

// The name may be a literal or computed — a beam package will reach for `Beam::table('…')`, a
// beamless one for its own prefix. The guard neither knows nor cares whose prefix it is.
ConvergentTable::named(Beam::table('ownership_edges'))
    ->define(function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('owner_id');
        $table->timestamps();
    })
    ->assert();
```

The definition closure is an ordinary Blueprint closure — the same one a `Schema::create()` would take.
Converting an existing migration is an indent plus a terminal.

## Why a bare existence guard is the defect

`spatie/laravel-package-tools` stamps a published migration with `now()`; vendor packages ship
fixed-date copies. So two migrations creating the same table race on filename — and because both sides
guard with `hasTable`, **whichever sorts first wins and the loser's guard reports SUCCESS.**
`migrate:fresh` exits green having produced a schema the app cannot use.

That is not a hypothetical. It was sighted three times during the beam-facade sweep:

1. **`lunarphp/core`'s `activity_log`**, whose `nullableMorphs` are bigint where beam needs string
   morph ids, so a tenant slug written into it throws `invalid input syntax for type bigint`.
2. **Two `create_media_table`s** in one host, which two separate tickets each had to prove
   pre-existing before they could read their own gate.
3. **A stale `create_beam_submissions_table` fork** sorting ahead of the canonical stub in three
   starters, handing hosts the old schema while `migrate` reported success.

In every case the *rule* was known and the *guard* was the thing nobody wrote.

## The three tiers

| state | verdict |
| --- | --- |
| table absent | create it |
| table present, declared columns absent | **add them** — converge |
| table present, column present with the wrong type | **throw** — cannot converge |

Tier three is the load-bearing one, and it is why "top up whatever is missing" is not enough on its own.
Lunar's `activity_log` does not *lack* the morph columns — it has them as the wrong type, so
`hasColumns()` returns true and a top-up guard tops up nothing and yields the broken table.

**A conflicted run writes nothing at all.** The table is left exactly as it was, so the two terminals
below differ only in how loud they are, never in what they did.

## Two terminals: pick the loud one unless you can say why

- **`->assert()`** throws `SchemaConvergenceConflict`. **This is the default.** A conflict is an
  install-time stop by design: the failure it replaces is silent, and an install that fails loudly is
  strictly better than an app that runs on a schema it cannot write to.
- **`->matches()`** returns a bool and stays quiet. For the call sites that genuinely do not care which
  shape won — a harness that migrates the central and the tenant pass into one schema, or a table whose
  competitor is harmless in a host that never reads it.

One terminal would force every call site into the other's shape, which is why both ship. But reaching
for the quiet one is a claim about the table, and it belongs in a comment beside the call.

## …and `->report()`, which is not a terminal at all

Both terminals answer from **inside** a `migrate`, one table at a time, after earlier tables in the same
run have already changed. That is correct and it is late. What an operator at `~/Herd/audiostud` got
(beam-facade ticket 84) was three tables migrated, a stack trace on the fourth, and no answer to *how
many more are queued*, *is the host half-installed*, *is re-running safe* — with ~30 pending, they
learned about one conflict and stayed blind to any others behind it.

- **`->report()`** returns the same `ConvergenceReport` with the writes removed. `assert()` and
  `matches()` are both `report()` plus what they do about it, so there is no second comparison to drift.
  `ConvergenceReport::$applied` distinguishes the two tenses, and it governs the *verb* only — a
  prediction names exactly the columns and indexes the run then adds.
- **`ConvergentTable::rehearse($body)`** is the same idea for a caller holding a *migration* rather than
  a guard, because a guard is constructed inside the migration's own `up()` and there is nothing to call
  `report()` on. It puts every terminal into report mode and returns the reports.

**Rehearsal neutralises convergent guards and nothing else.** The body runs for real, so a
`DB::statement()` sitting beside a guard still executes — and by this page's own count nine files in the
estate carry exactly that. A caller must therefore establish that a body is a pure convergent
declaration before rehearsing it, and must **say so** when it declines: a preflight that silently
skipped its one risky migration would read as "everything is clean", which is the false-green shape this
whole convention exists to end. `Splicewire\Beam\Install\ConvergencePreflight` is the worked example —
it scans the source outside `down()`, skips anything that writes, and prints the skip.

Two mechanics that are not obvious and cost a debugging session each:

- **`migrate --pretend` is not an alternative to this.** Pretending makes `select()` return `[]`, so
  every `getColumns()` the comparison depends on answers "no columns" and the guard reports a clean
  convergence against a table it cannot see.
- **A rehearsing caller must not `require` the real migration file.** PHP returns `true` rather than the
  migration object on a second include of the same path, so a preflight running in the same process as
  the `migrate` it precedes would hand the migrator a boolean. Copy to a temp path first; migrations are
  anonymous classes with no `__DIR__` dependency.

**Every conflict also carries a `repair`** — a code sketch in Laravel's schema vocabulary, never driver
SQL, because this package has no connection to ask which driver is live. Diagnosis and repair are
separate fields on purpose: the diagnosis is a fact, the repair is a suggestion offered with its caveat,
since nothing here can know whether the column's data survives the cast.

## What convergence cannot do

Convergence handles **absence**, never **conflict**. Three shapes it refuses rather than papers over,
each naming a different repair (`Rushing\SchemaConvergence\SchemaConflict`):

- **`type`** — the column exists with an incompatible database type.
- **`required-addition`** — a declared column is NOT NULL with no default and the table already holds
  rows, so the ALTER itself would fail.
- **`required-residue`** — a column the declaration does not know about is NOT NULL with no default, so
  the table converges cleanly and then rejects every insert this package makes. This is the shape the
  submissions fork turned out to have: not a missing column and not a type conflict, but **a different
  table wearing the same name**, disjoint in both directions. `->ignoringColumns()` exempts a column a
  host is deliberately filling itself.

**A migration that has already run is out of reach entirely** — the fourth thing, and the one most
often mistaken for convergence's job (beam-facade ticket 86). Editing a `create_*` stub changes what a
*fresh* database gets; the guard only converges when its migration **runs**, and on an already-migrated
host it does not. `migrate` says `Nothing to migrate.` and the live table keeps the old shape.

Forcing it to run makes this **worse, not better**. Re-stamp the published copy with 29's ownership
lever and the guard executes against a table that already has the old column: it ADDS the new one
beside a NOT-NULL old one that nothing writes — which is `required-residue` above, the one shape this
package records as unrepairable. The regime sharpens this hazard rather than softening it.

The repair is not here. See "A published copy is a snapshot, not the source of truth" in
`splicewire/laravel-beam`'s
[`migration-publish-ordering.convention.md`](https://github.com/stephenr85/laravel-beam/blob/main/docs/agents/migration-publish-ordering.convention.md).

**Type comparison is deliberately three-valued.** A declared type with no mapping for the current driver
is reported *unverified*, never escalated to a conflict — the same conservative posture
`MigrationOrderingAudit` takes with a dynamically-named table. A false conflict stops an install; a
missed one leaves the status quo. `ColumnTypeEquivalence` is meant to grow when a real column type goes
unverified.

**Foreign keys and primary keys are not converged** onto an existing table. The create path applies
whatever the definition declares; the converge path adds columns and plain / unique / full-text indexes
only. A primary key cannot be added after the fact on sqlite, and a foreign key added to a populated
table fails on the rows already there — both are deliberate migrations, not convergence.

**Per-column type conversion (`->change()`) is not built.** Ruled out with a trigger: build it when a
real host must upgrade *through* a type conflict. Every sighting so far needed detection plus someone
told, not conversion. The extension point is already there — `ConvergentTable` is `Macroable`, and the
report names the conflicting column — so a host that must convert can macro one on without touching the
class.

> The trigger was **tested and did not fire**, 2026-08-27 (beam-facade ticket 108). Nine live type
> conflicts across three tables at `~/Herd/audiostud` were re-measured against the trigger's own
> condition and **not one host must upgrade**: five were an orphaned legacy shape whose declaring
> package is no longer installed (0 rows, dropped under the greenfield ruling), and four are deliberate
> host overrides that never adopt the package shape at all — see the next section. Widening
> `->ignoringColumns()` from residue to type conflicts was proposed in the same session and refused:
> it is a change to the guard's **contract**, offered on the same evidence that just failed to fire the
> narrower trigger. Read this as *unfired*, not *unexamined*.

## Three things that are NOT the fix

**A publish-time filename band.** Publishing beam's creates into a `0001_01_01_*` band so they always
sort first was considered and rejected on merit, not cost (`generateMigrationName` is `protected`; the
band was ~10 lines). It is invisible magic, and it would also outrank a host's own deliberate migration
— an install nobody chose is worse than an install that fails. What a band was reaching for, the
**installer** owns explicitly instead, and now does (beam-facade ticket 29): `splicewire:beam:install`
asks who owns each colliding table between publish and migrate, defaults to beam, takes `--own-tables`
to script it, and re-dates the published copy **one tick** below the competitor rather than into a
band. `Splicewire\Beam\Install\TableOwnershipResolver`; the full rule is in
[`migration-publish-ordering.convention.md`](migration-publish-ordering.convention.md) under
"Re-dating IS the fix once the install causes it".

**That lever is for third parties only.** Inside the family a convergent guard already dissolves the
collision, so nothing here needs ordering; the installer's answer exists because
`lunarphp/core`'s bare `hasTable → return` is a guard we cannot edit.

**Hand-editing a published migration's timestamp.**
[`migration-publish-ordering.convention.md`](migration-publish-ordering.convention.md) lists this as a
non-fix and gives a reason that is **false**, corrected here: *"the next `vendor:publish` undoes it"* —
it does not. package-tools' `generateMigrationName` globs `database_path('migrations/<dir>/*.php')` and
matches an existing file by **basename**, ignoring the timestamp prefix, so a hand re-dated file is
re-found and overwritten **in place, keeping its date**. What actually regresses is **greenfield**: a
fresh host has nothing to match and gets a `now()` stamp. So the defect in a hand re-date is that *the
fix is not in source* — one host is patched and the next clone is not — which is a better reason to
refuse it than the one that was written down.

## Scope

Every `create_*` **any family package** publishes — `splicewire/*`, `rushing/*`, `schemastud/*` alike —
not only tables with a known competitor, and not only packages that depend on beam. You do not know a
competitor exists until it collides, and a maintained list of hostile vendors fails silently the first
time someone forgets an entry. A convergent guard on a table nobody else touches costs one `hasTable`
against a table that does not exist.

The vendor-neutral reach is why the guard is homed here rather than in beam, and it is a **standing
rule** rather than a dated finding for one reason (beam-facade ticket 34, on 25's own test): *does this
package publish a `create_*` stub* is mechanically decidable by grep, the way facade **placement** is
and facade **merit** is not. A rule may bind retroactively exactly as far as its subject is greppable.

The lowest-risk case does not get an exemption, and the argument for one is recorded rather than
hidden: all ten stubs in the five beamless packages create **vendor-prefixed** tables (`commerce_*`,
`request_logs`, `vault_secrets`, `marquee_state`, `notification_statuses`), where every collision
proven on this map was an unprefixed common noun — `users`, `roles`, `media`, `tags`, `activity_log`.
Their real collision risk is the lowest in the estate. The sentence above still answers it: you do not
know a competitor exists until it collides.

Out of scope: **two migrations a HOST wrote itself**. A package has no standing to arbitrate between
two of an app's own migrations, and the two `create_media_table`s above stay the host's bug. Note this
carve-out is free rather than remembered — the licensing asymmetry is *publish-vs-source*, so a
mechanism that only ever rewrites published copies cannot reach a host's own file by construction.

## Enforcement

The sixth doctor audit is **built** (beam-facade ticket 30): `Splicewire\Beam\Doctor\UnguardedCreateAudit`,
check key `beam.schema.unguarded-create`, advisory, registered in `BeamDoctorManifest`. It shipped after
both halves of the sweep — ticket 28's 118 stubs in 22 `splicewire/*` packages and ticket 36's ten in the
five beamless `rushing/*` packages this extraction unblocked — with **no beamless carve-out**, because 34
measured that audit-reach and import-reach are independent (the audit admits a `vendor/` package when it
is a symlink, and all five are symlinked at the host). It lives in beam's doctor regime, which is why the
audit and the rule sit in different repos: the rule binds every publisher, the instrument runs where the
hosts are.

Three things about what it actually checks, because two of them narrow the claim:

- **The predicate is presence-of-`Schema::create`, not absence-of-guard.** A converted stub does not
  wrap the create, it replaces it, so the two are the same set. It reads the file's own import map and
  reports per *call* rather than per file — which is what catches 28's harder defect, a guard whose
  scope is wrong (`create_permission_tables` guarded on `permissions` and returned for all five of its
  creates, and every presence check in the estate scored it as conformant).
- **The population is every migration template, never a `create_*` filename.** Ticket 22 specified this
  check against `create_*` stubs and 28 profiled its sweep the same way; that is exactly how the
  estate's one surviving unguarded create got past both, in
  `splicewire/tower/database/migrations/tenant/add_directory_acl_grants_and_visibility.php.stub`, which
  creates `grants` beside an ALTER and is honestly named for it. Converted at 30.
- **It checks the necessary half only.** Importing the guard is not sufficient — 28 found six converted
  stubs carrying raw `DB::statement` DDL beside the create and three adding a self-referencing FK in a
  post-create `Schema::table()`, none of it covered by convergence and each carrying its own
  hand-written idempotency guard. The audit reads all nine as conformant and says so in its own Pass
  line. It also cannot reach a **published host copy** (19's exclude-generated-output rule), and a swept
  package beside an unswept host is the estate's normal state; that gap is repaired by re-publishing,
  not by an edit.

This page remains the statement of the rule, and beam's own six shared stubs are the worked example:

```
laravel-beam/database/migrations/shared/*.php.stub
```

`Splicewire\Beam\Tests\Schema\SharedMigrationStubsConvergeTest` runs all six twice and asserts the
second pass moves nothing — the acceptance any converted stub should be able to meet. That test stays
in **beam**, deliberately: it is an assertion about beam's own stubs, not about the guard. The guard's
own acceptance is `Rushing\SchemaConvergence\Tests\ConvergentTableTest`, which travelled here with it.

**Importing the guard is necessary, not sufficient** (ticket 28, quantified). A converted stub can
still carry raw `DB::statement` DDL beside the create, or add a self-referencing foreign key in a
post-create `Schema::table()` — nine files in the estate do, and a file-shape predicate reads every one
of them as conformant. The guard covers what it is given; it cannot see the statement next to it.

**A shape-ownership door on the installer.** Ticket 29 gave the installer a door for *filenames* — who
owns this stem, default beam, `--own-tables` to script it, re-date the published copy. The obvious
symmetry is a door for **shapes**: when two declarations disagree about a column's type, let the
operator say which one wins. It was considered across three sessions (beam-facade 84, 108) and is
**refused**, on two grounds.

The first is the one 84 gave: **the lever is not free the way 29's is.** Re-dating a file costs nothing
and the migration still runs. "Accepting" a shape conflict costs everything, because the losing stub
still executes at migrate time and still throws — so an `--accept-shapes` flag buys the operator the
same stack trace a few tables later. 84 shipped **abort** instead (plus `--skip-preflight`) rather than
mint a flag that pretends to resolve something.

The second is stronger and is why this is a rule rather than a deferral: **the estate already has an
answer, one file away from the conflict.** A host that disagrees with a package's declared shape
**keeps its own published copy**, and the package stub defers to it. That is not a workaround — it is
documented in the losing declaration's own docblock. `splicewire/laravel-beam-accounts`'
`create_impersonation_events_table.php.stub` says it verbatim: *"audiostud declared `actor_id` as
`foreignUuid`, numero as `foreignId` … A host that wants referential integrity keeps its own typed table
and points [at it]."* Two hosts disagree with the stub, both are correct, both are served, and no flag
exists or is needed. A door would be a second way to say what the estate already says.

**The trigger that reverses this:** the day a **third-party** package and a family package disagree
about a shape at a host that must keep both. Every instance measured so far is family-internal — both
sides ours — where the honest answer *is* an edit to one of the two declarations, because we own both.
Nobody has yet produced an instance where we own neither side of the argument.

⚠️ **The cost of this rule, which is real and is separately instrumented.** A host override makes its
own conflict **invisible**: the install-time preflight rehearses the *published copy*, finds it matches
the live database, and passes — while the package stub it overrides sits one republish away from a
tier-three throw. At `~/Herd/audiostud` that is a **154-row** `activity_log` behind a `uuid` override
against a `string`-declaring stub. Neither of beam's two stub audits can see it, deliberately:
`UnrehearsableStubAudit` reads published copies, `UnguardedCreateAudit` reads `.php.stub` templates, and
the conflict falls between them. Beam ships a third audit that rehearses a **package stub against the
live database** (beam-facade ticket 182) — advisory permanently, since whether a host's live shape
conflicts with a package's declaration is a fact about the **host**. If you are relying on the override
mechanism this section blesses, that audit is the thing that tells you what it is hiding.

**And you can now ASK, rather than waiting to be told.** `splicewire:beam:convergence-preflight`
(beam-facade ticket 146) is the read-only entry point: it rehearses the pending set *and* the
unpublished package stubs and prints the same three outcomes the install phase does, writing nothing.
Until it existed, the question *"what would collide if I published?"* could be asked only by an operator
willing to publish — the standing audit reports tier-three conflicts on its own schedule, and the
would-change half had no instrument at all.

⚠️ It reports; it does not gate. Whether a host's live shape conflicts with a package's declaration is a
fact about the **host**, so the default exit code is 0 even when it finds conflicts, and
`--fail-on-conflict` is opt-in for a caller who has deliberately chosen this host as the subject.
