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

## Two things that are NOT the fix

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
