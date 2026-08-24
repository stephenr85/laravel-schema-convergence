<?php

namespace Rushing\SchemaConvergence;

use BadMethodCallException;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Fluent;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Support\Traits\Tappable;

/**
 * A CONVERGENT migration guard: "ensure this table has this shape", in place of "create this table".
 *
 * ## The failure it exists to end
 *
 * `spatie/laravel-package-tools` stamps a published migration with `now()`; vendor packages ship
 * fixed-date copies. Two migrations creating the SAME table therefore race on filename, and because
 * both sides guard with a bare `Schema::hasTable($t) → return`, **whichever sorts first wins and the
 * loser's guard reports success** — `migrate:fresh` exits green having produced a schema the app cannot
 * use. Sighted three times across the beam-facade effort: lunarphp's `activity_log` and the bigint-vs-uuid
 * `roles.id` break (ticket 13), two `create_media_table`s (16/17), and the submissions fork (20/23).
 *
 * Convergence removes the race rather than arbitrating it. Whoever runs first creates; whoever runs
 * second tops up what is missing; filename order stops mattering. See
 * `docs/agents/convergent-migration-guards.convention.md` (in THIS package) for the rule and the
 * estate's obligations under it, and `splicewire/laravel-beam`'s
 * `docs/agents/migration-publish-ordering.convention.md` for the OTHER half of the collision family
 * (cross-package ALTER-vs-CREATE plus the installer's filename-order lever), which this does not
 * address and which stays in beam because the installer does.
 *
 * ## The three tiers
 *
 * | state                                        | verdict                  |
 * | -------------------------------------------- | ------------------------ |
 * | table absent                                 | create it                |
 * | table present, declared columns absent       | **add them** — converge  |
 * | table present, column present, wrong type    | **throw** — cannot converge |
 *
 * Tier three is the load-bearing one. Convergence handles ABSENCE, never CONFLICT, and both sightings
 * with proven damage were type conflicts: Lunar's `activity_log` does not LACK the morph columns, it has
 * them as bigint `nullableMorphs` where beam needs strings, so `hasColumns()` returns true and a
 * top-up-what-is-missing guard would top up nothing. {@see SchemaConflict} carries the two further
 * shapes convergence cannot reach — a NOT NULL addition to a populated table, and NOT NULL residue the
 * declaration does not know about (beam-facade ticket 23).
 *
 * **No DDL is written when a conflict is found.** A conflicted run leaves the table exactly as it was,
 * so the two terminals differ only in how loud they are, never in what they did.
 *
 * ## Two terminals, both required
 *
 * `->assert()` throws {@see SchemaConvergenceConflict}; `->matches()` returns a bool and stays quiet.
 * One terminal would force every call site into the other's shape — a stale-fork collision wants the
 * throw, while the shared-test-DB harness and "Lunar wins harmlessly in a host with no central audit
 * trail" want the quiet return.
 *
 * ## …and one non-terminal: `->report()` (beam-facade ticket 84)
 *
 * Both terminals answer from INSIDE a migrate, one table at a time, after earlier tables in the same run
 * have already changed. An operator therefore meets the first conflict as a stack trace three tables in,
 * with no answer to how many more are queued or whether the host is now half-installed. `->report()` is
 * the same comparison with the writes removed, so a caller between publish and migrate can ask every
 * published stub at once while nothing has been written and an abort is free.
 *
 * It is a PREDICTION, and an exact one: `assert()` and `matches()` are both `report()` plus what they do
 * about it, so there is no second implementation to drift. {@see ConvergenceReport::$applied} is how a
 * caller tells the two tenses apart.
 *
 * {@see rehearse()} is the same idea one level up, for a caller holding a migration rather than a guard.
 *
 * ```php
 * // the name may be a literal or computed — this package neither knows nor cares whose prefix it is
 * ConvergentTable::named('beam_ownership_edges')
 *     ->define(function (Blueprint $t) {
 *         $t->uuid('id')->primary();
 *         $t->uuid('owner_id');
 *         $t->timestamps();
 *     })
 *     ->assert();
 * ```
 *
 * ## What it deliberately does not do
 *
 * **Per-column type conversion** (`->change()`). Ruled out at beam-facade ticket 22 with a trigger: build
 * it when a real host must upgrade THROUGH a type conflict. Every sighting so far needed detection plus
 * someone told, not conversion — and designing a conversion API against zero call sites is the shape this
 * estate has refused twice. The extension point is `Macroable` plus {@see ConvergenceReport}, which names
 * the conflicting column: a host that must convert can macro one on without touching this class.
 *
 * **Foreign keys and primary keys on an EXISTING table.** The create path applies whatever the definition
 * declares; the converge path adds columns and plain/unique/full-text indexes only. A primary key cannot
 * be added after the fact on sqlite, and a foreign key added to a populated table fails on the rows that
 * are already there — both are deliberate migrations, not convergence.
 *
 * Homed here rather than in `laravel-beam/src/Schema/` since beam-facade ticket 34: the promotion
 * trigger fired for five `rushing/*` packages that publish a `create_*` and carry no beam dependency
 * at all. The standing rule that came with the move — any family package that publishes a `create_*`
 * guards it convergently, whatever its vendor and whether or not it depends on beam.
 */
class ConvergentTable
{
    use Conditionable, Macroable, Tappable;

    /**
     * The reports collected by the {@see rehearse()} currently in progress, or null when none is.
     *
     * Static because the caller does not hold the guards — it holds a migration, which constructs its own.
     *
     * @var list<ConvergenceReport>|null
     */
    protected static ?array $rehearsal = null;

    protected ?Closure $definition = null;

    protected ?Closure $exists = null;

    protected ?string $connection = null;

    /** @var list<string> */
    protected array $ignored = [];

    public function __construct(public string $table) {}

    public static function named(string $table): static
    {
        return new static($table);
    }

    /** Run against a named connection instead of the default. */
    public function on(?string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * The shape this table must have — an ordinary Blueprint closure, identical to the one a
     * `Schema::create()` would take. It is run twice: once for real when the table is absent, and once
     * against a throwaway Blueprint (which emits no SQL) to read the declaration when it is present.
     */
    public function define(Closure $definition): static
    {
        $this->definition = $definition;

        return $this;
    }

    /**
     * Override how "does this table exist" is answered — the escape hatch for a substrate where
     * `Schema::hasTable()` is the wrong question.
     *
     * The case that earned it: under stancl schema-per-tenant on Postgres, the search_path is
     * `("tenant_x", public)`, so a plain `hasTable` INSIDE a tenant sees the central copy and skips
     * creating the tenant-local one. The predicate receives the resolved table name.
     */
    public function existsUsing(Closure $predicate): static
    {
        $this->exists = $predicate;

        return $this;
    }

    /**
     * Exempt existing columns from the NOT-NULL-residue check — for a host filling a required column of
     * its own deliberately. Nothing else about them is inspected either way.
     */
    public function ignoringColumns(string ...$columns): static
    {
        $this->ignored = array_values(array_unique([...$this->ignored, ...array_map('strtolower', $columns)]));

        return $this;
    }

    /**
     * Converge, and throw {@see SchemaConvergenceConflict} if the table cannot be converged.
     * The loud terminal — the default for anything whose absence would corrupt data.
     */
    public function assert(): ConvergenceReport
    {
        if (static::$rehearsal !== null) {
            return static::$rehearsal[] = $this->report();
        }

        $report = $this->converge(apply: true);

        if ($report->hasConflicts()) {
            throw SchemaConvergenceConflict::from($report);
        }

        return $report;
    }

    /**
     * Converge, and report whether the table now matches — never throwing. The quiet terminal, for the
     * call sites that genuinely do not care which shape won.
     */
    public function matches(): bool
    {
        if (static::$rehearsal !== null) {
            return (static::$rehearsal[] = $this->report())->converged();
        }

        return $this->converge(apply: true)->converged();
    }

    /**
     * The verdict against the live schema, with NOTHING written — the non-destructive read both terminals
     * are built on, exposed so a caller can ask before a migrate instead of during one.
     *
     * Every ALTER is predicted rather than issued, including the create: a report on an absent table says
     * `created` because that is what running it would do. The one asymmetry worth knowing is deliberate —
     * an index declared fluently on a MISSING column is not listed, because when the column is added it
     * carries that index along to the same ALTER, so listing it would predict a statement that never runs.
     */
    public function report(): ConvergenceReport
    {
        return $this->converge(apply: false);
    }

    /**
     * Run `$body` with every terminal in {@see report()} mode: nothing is written, nothing throws, and
     * every table a convergent guard touched comes back as its report.
     *
     * For the caller that holds a MIGRATION rather than a guard — a preflight cannot call `report()` on
     * guards it never constructs, because the guard is built inside the migration's own `up()`. So the
     * mode is set here rather than passed, and this is the only reason any state on this class is static.
     *
     * TWO THINGS THIS DOES NOT DO, and a caller must handle both. It does not neutralise anything else in
     * `$body` — a `DB::statement()` beside the guard still runs for real, so the caller is responsible for
     * only rehearsing bodies it has established are convergent declarations. And it does not de-duplicate:
     * rehearsing the same migration twice reports it twice.
     *
     * Not re-entrant, and loudly so: a nested rehearsal would silently fold its reports into the outer
     * one's list.
     *
     * @return list<ConvergenceReport>
     */
    public static function rehearse(Closure $body): array
    {
        if (static::$rehearsal !== null) {
            throw new BadMethodCallException('ConvergentTable::rehearse() is already in progress — it does not nest.');
        }

        static::$rehearsal = [];

        try {
            $body();

            return static::$rehearsal;
        } finally {
            static::$rehearsal = null;
        }
    }

    /** True while a {@see rehearse()} is in progress, so a terminal writes nothing. */
    public static function rehearsing(): bool
    {
        return static::$rehearsal !== null;
    }

    protected function converge(bool $apply): ConvergenceReport
    {
        if ($this->definition === null) {
            throw new BadMethodCallException(
                "ConvergentTable for `{$this->table}` has no definition — call `define()` before a terminal."
            );
        }

        $schema = Schema::connection($this->connection);

        if (! $this->tableExists($schema)) {
            if ($apply) {
                $schema->create($this->table, $this->definition);
            }

            return new ConvergenceReport($this->table, created: true, applied: $apply);
        }

        $shape = new DeclaredShape($schema->getConnection(), $this->table, $this->definition);
        $driver = $schema->getConnection()->getDriverName();

        $existing = [];
        foreach ($schema->getColumns($this->table) as $column) {
            $existing[strtolower($column['name'])] = $column;
        }

        $missing = [];
        $conflicts = [];
        $unverified = [];
        $declared = [];

        foreach ($shape->getColumns() as $definition) {
            $name = strtolower($definition->name);
            $declared[] = $name;

            if (! isset($existing[$name])) {
                $missing[] = $definition;

                continue;
            }

            $verdict = ColumnTypeEquivalence::matches($driver, (string) $definition->type, (string) $existing[$name]['type_name']);

            if ($verdict === null) {
                $unverified[] = $definition->name;
            } elseif ($verdict === false) {
                $conflicts[] = SchemaConflict::type(
                    $this->table,
                    $definition->name,
                    (string) $definition->type,
                    (string) $existing[$name]['type_name'],
                );
            }
        }

        // Tier two has a limit convergence cannot reach: a NOT NULL column with no default cannot be
        // added to a table that already holds rows. Only worth asking once, and only if something is
        // actually missing.
        $populated = null;
        foreach ($missing as $definition) {
            if (! $this->isRequired($definition)) {
                continue;
            }

            $populated ??= $schema->getConnection()->table($this->table)->exists();

            if ($populated) {
                $conflicts[] = SchemaConflict::requiredAddition($this->table, $definition->name);
            }
        }

        // The other direction, which a top-up-only guard converges cleanly and then breaks on: a column
        // this declaration does not know about, NOT NULL with no default, so every insert we make fails.
        foreach ($existing as $name => $column) {
            if (in_array($name, $declared, true) || in_array($name, $this->ignored, true)) {
                continue;
            }

            if ($column['nullable'] || $column['default'] !== null || $column['auto_increment']) {
                continue;
            }

            $conflicts[] = SchemaConflict::requiredResidue($this->table, $column['name']);
        }

        if ($conflicts !== []) {
            // No DDL on this path either way, so `applied` is the only thing the two tenses differ by —
            // a conflicted `assert()` has always left the table exactly as it was.
            return new ConvergenceReport($this->table, conflicts: $conflicts, unverified: $unverified, applied: $apply);
        }

        if ($apply && $missing !== []) {
            $schema->table($this->table, function (Blueprint $table) use ($missing) {
                foreach ($missing as $definition) {
                    $attributes = $definition->getAttributes();
                    unset($attributes['type'], $attributes['name']);

                    $table->addColumn((string) $definition->type, (string) $definition->name, $attributes);
                }
            });
        }

        return new ConvergenceReport(
            $this->table,
            addedColumns: array_map(fn ($definition) => (string) $definition->name, $missing),
            // AFTER the column add, deliberately: `declaredIndexes()` consumes the fluent index
            // attributes, and a re-added column carries its own along to the ALTER.
            addedIndexes: $this->convergeIndexes($schema, $shape, $apply, $missing),
            unverified: $unverified,
            applied: $apply,
        );
    }

    /**
     * Indexes the definition declares that the live table does not have — checked by NAME and by COLUMN
     * SET, because a competitor's copy of the table will have named its indexes its own way and creating
     * a duplicate unique index is a hard failure rather than a no-op.
     *
     * Predicting is not quite "the same, minus the write". When applying, a missing column has already
     * been added WITH its fluent index, so `hasIndex()` answers true and the index is correctly skipped;
     * when predicting, that column is not there and `hasIndex()` cannot know. So the prediction excludes
     * any index over a missing column explicitly — the alternative is a preflight that promises indexes
     * the applied run never records.
     *
     * @param  list<Fluent>  $missing  the columns this run would add
     * @return list<string>
     */
    protected function convergeIndexes(Builder $schema, DeclaredShape $shape, bool $apply, array $missing): array
    {
        $added = [];
        $pending = array_map(static fn (Fluent $definition) => strtolower((string) $definition->name), $missing);

        foreach ($shape->declaredIndexes() as $command) {
            if (! in_array($command->name, ['index', 'unique', 'fullText'], true)) {
                continue;
            }

            $columns = (array) $command->columns;
            $index = (string) $command->index;

            if ($schema->hasIndex($this->table, $index) || $schema->hasIndex($this->table, $columns)) {
                continue;
            }

            if (! $apply && array_intersect(array_map('strtolower', $columns), $pending) !== []) {
                continue;
            }

            if ($apply) {
                $method = (string) $command->name;

                $schema->table($this->table, fn (Blueprint $table) => $table->{$method}($columns, $index));
            }

            $added[] = $index;
        }

        return $added;
    }

    protected function tableExists(Builder $schema): bool
    {
        return $this->exists !== null
            ? (bool) ($this->exists)($this->table)
            : $schema->hasTable($this->table);
    }

    /** NOT NULL, no default, and not generated — the only shape that cannot be added to a populated table. */
    protected function isRequired(Fluent $definition): bool
    {
        return ! $definition->nullable
            && $definition->default === null
            && ! $definition->autoIncrement
            && $definition->virtualAs === null
            && $definition->storedAs === null;
    }
}
