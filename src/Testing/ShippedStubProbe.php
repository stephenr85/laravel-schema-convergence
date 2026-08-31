<?php

namespace Rushing\SchemaConvergence\Testing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Run a package's SHIPPED migration stubs onto a throwaway connection and read the schema they
 * actually produce, so a hand-built test fixture can be compared against it.
 *
 * ## Why this cannot be done by reading the files
 *
 * The estate's conformance program tells every root to migrate from `Schema::create('literal')` to
 * `ConvergentTable::named(...)`, and in practice every call site spells it `named($this->target())` —
 * the table name is a runtime expression. So a static reader pairs the handful of tables still
 * spelled literally and silently misses the rest: measured in `splicewire/laravel-beam-accounts`,
 * static extraction pairs 4 tables where EXECUTION pairs 13. The most conformant repo is the least
 * visible one. Executing the stub is the only instrument that sees what a host will get.
 *
 * ## Why a package needs this at all
 *
 * A package suite hand-builds its schema in `setUp()` because booting a host is too expensive. That
 * fixture then drifts from the stubs the package ships, and NOTHING reports it: the suite is green
 * against a schema no host has. `splicewire/laravel-beam-accounts` carried nine such divergences,
 * six of which encoded a repair the shipped code has never performed.
 *
 * ⚠️ **A divergence is a question, not a defect.** The same measurement that found those nine also
 * retired all nine: they modelled a host population of zero while inverting the nine real ones. So a
 * consumer of this class should compare against a host it can NAME, not against an argument from a
 * stub's docblock — that is exactly how the nine got written.
 *
 * ## The limit sqlite imposes, recorded rather than papered over
 *
 * The probe runs on in-memory sqlite, which collapses `uuid`/`string`/`ulid` to `varchar` and
 * `integer`/`bigInteger` to `integer`. So a uuid-versus-string divergence CANNOT be expressed here and
 * will read as agreement. Divergences that cross type families — the `integer` versus `varchar` that a
 * bigint key against a uuid key produces — do survive, and those are the ones with proven damage in
 * this estate. Do not read a clean result as "the key types match."
 *
 * ## Reach
 *
 * Homed here because all 19 stub-shipping, fixture-building repos in this estate already require
 * `rushing/laravel-schema-convergence` — it owns the `ConvergentTable` vocabulary the stubs are
 * written in. Only one of the 19 requires the test-instrument package, so putting it there would
 * have reached a single repo and asked the other 18 for a new dependency.
 */
final class ShippedStubProbe
{
    /**
     * @param  string  $migrationsRoot  the package's `database/migrations` directory
     * @param  string  $connection  a connection name that must NOT already exist in the harness
     */
    public function __construct(
        private readonly string $migrationsRoot,
        private readonly string $connection = 'stub_probe',
    ) {}

    public function connection(): string
    {
        return $this->connection;
    }

    /**
     * Every stub stem on disk, nested directories included, sorted.
     *
     * A stem is the path under the migrations root with `.php.stub` removed — `shared/create_x_table`,
     * not a basename, because two estates can ship the same basename in different subdirectories.
     *
     * @return list<string>
     */
    public function stemsOnDisk(): array
    {
        $found = glob(rtrim($this->migrationsRoot, '/').'/{,*/}*.php.stub', GLOB_BRACE) ?: [];
        $root = rtrim($this->migrationsRoot, '/').'/';

        $stems = array_map(
            static fn (string $path): string => substr($path, strlen($root), -strlen('.php.stub')),
            $found,
        );
        sort($stems);

        return array_values($stems);
    }

    /**
     * Order the declared stems first, then anything else on disk.
     *
     * A package that declares its publish order (a gate map, a manifest) has stated the order a host
     * migrates in, and running out of order manufactures conflicts a host would never see. Stubs the
     * package ships but does not declare still run, after — omitting them would quietly shrink the
     * probe's reach, which is the failure this whole class exists to prevent.
     *
     * @param  list<string>  $declared
     * @return list<string>
     */
    public function orderedStems(array $declared = []): array
    {
        $onDisk = $this->stemsOnDisk();
        $known = array_values(array_intersect($declared, $onDisk));

        return array_merge($known, array_values(array_diff($onDisk, $declared)));
    }

    /**
     * Stems named as declared that are not on disk — a publish list pointing at a file that moved.
     *
     * @param  list<string>  $declared
     * @return list<string>
     */
    public function missingFromDisk(array $declared): array
    {
        return array_values(array_diff($declared, $this->stemsOnDisk()));
    }

    /**
     * Create the throwaway connection and run each stub's `up()` against it.
     *
     * ⚠️ The default connection is SWAPPED for the duration, not just passed as an argument. Stubs
     * reach the schema builder through facades and helpers that resolve the default connection, so a
     * probe that only passed a connection name would silently write half its tables into the fixture
     * it is trying to measure. The swap is restored in a `finally`.
     *
     * @param  list<string>  $stems  in the order a host migrates them
     * @param  array<string, mixed>  $config  config to set for the probe run only (e.g. a package gate
     *                                        the fixture models as ON); restored afterwards
     */
    public function run(array $stems, array $config = []): void
    {
        if ($this->alreadyRun()) {
            return;
        }

        config()->set('database.connections.'.$this->connection, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $restore = [];
        foreach ($config as $key => $value) {
            $restore[$key] = config($key);
            config()->set($key, $value);
        }

        $previous = config('database.default');
        config()->set('database.default', $this->connection);
        DB::setDefaultConnection($this->connection);

        try {
            foreach ($stems as $stem) {
                $migration = require rtrim($this->migrationsRoot, '/')."/{$stem}.php.stub";
                $migration->up();
            }
        } finally {
            config()->set('database.default', $previous);
            DB::setDefaultConnection($previous);

            foreach ($restore as $key => $value) {
                config()->set($key, $value);
            }
        }
    }

    private function alreadyRun(): bool
    {
        return array_key_exists($this->connection, config('database.connections', []))
            && $this->tables() !== [];
    }

    /**
     * Table names on a connection; the probe's own when `$connection` is omitted.
     *
     * @return list<string>
     */
    public function tables(?string $connection = null): array
    {
        $names = array_map(
            static fn (array $table): string => $table['name'],
            Schema::connection($connection ?? $this->connection)->getTables(),
        );
        sort($names);

        return array_values($names);
    }

    /**
     * Tables BOTH the probe and the fixture build — the only ones a comparison can speak about.
     *
     * @param  string|null  $fixtureConnection  null = the harness default
     * @return list<string>
     */
    public function sharedTables(?string $fixtureConnection = null): array
    {
        $shared = array_values(array_intersect($this->tables(), $this->tables($fixtureConnection ?? config('database.default'))));
        sort($shared);

        return array_values($shared);
    }

    /**
     * A table's shape as `column => "type nullability"`.
     *
     * Deliberately coarse. The question is whether a host's column can hold what the fixture's can,
     * and driver-reported length/precision differ across drivers for columns that are in fact the
     * same — a finer comparison reports differences that are about sqlite versus Postgres rather than
     * about the package.
     *
     * @return array<string, string>
     */
    public function shape(string $table, ?string $connection = null): array
    {
        $shape = [];

        foreach (Schema::connection($connection ?? $this->connection)->getColumns($table) as $column) {
            $shape[$column['name']] = strtolower((string) $column['type_name'])
                .($column['nullable'] ? ' nullable' : ' not-null');
        }

        ksort($shape);

        return $shape;
    }

    /**
     * Every column the two sides SHARE and disagree about, as
     * `table => [column => ['stub' => …, 'fixture' => …]]`.
     *
     * Columns present on only one side are not reported: a fixture is allowed to build less than a
     * host gets, and a stub is allowed to add a column the fixture has no use for. What must not
     * differ is the shape of a column both claim to have.
     *
     * @param  list<string>  $tables
     * @return array<string, array<string, array{stub: string, fixture: string}>>
     */
    public function divergences(array $tables, ?string $fixtureConnection = null): array
    {
        $fixture = $fixtureConnection ?? config('database.default');
        $found = [];

        foreach ($tables as $table) {
            $stub = $this->shape($table);
            $mine = $this->shape($table, $fixture);

            foreach (array_intersect_key($stub, $mine) as $column => $shape) {
                if ($shape !== $mine[$column]) {
                    $found[$table][$column] = ['stub' => $shape, 'fixture' => $mine[$column]];
                }
            }
        }

        return $found;
    }

    /**
     * How many columns a comparison actually looked at.
     *
     * ⚠️ Assert a FLOOR on this. Every other check here passes vacuously when the probe builds
     * nothing — an empty divergence list reads identically whether the schemas agree or the stubs
     * never ran. This is the number that tells those apart.
     *
     * @param  list<string>  $tables
     */
    public function comparedColumnCount(array $tables, ?string $fixtureConnection = null): int
    {
        $fixture = $fixtureConnection ?? config('database.default');
        $total = 0;

        foreach ($tables as $table) {
            $total += count(array_intersect_key($this->shape($table), $this->shape($table, $fixture)));
        }

        return $total;
    }
}
