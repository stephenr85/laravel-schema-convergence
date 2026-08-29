<?php

namespace Rushing\SchemaConvergence;

/**
 * Answers the one question tier three of {@see ConvergentTable} turns on: does the column already in the
 * database hold the type this migration declares?
 *
 * The comparison is between two different vocabularies — a Blueprint's LARAVEL type (`string`, `uuid`,
 * `bigInteger`) and the DATABASE's own type name as `Schema::getColumns()` reports it (`varchar`, `uuid`,
 * `int8`) — so it needs a map, and the map is per driver because the same Laravel type compiles
 * differently: `uuid()` is a real `uuid` on Postgres, `char(36)` on MySQL, and plain `varchar` on sqlite.
 *
 * **Three answers, not two.** An unmapped Laravel type returns `null` — UNVERIFIED, never a conflict.
 * This is the same conservative posture beam's migration-ordering audit takes with a dynamically-named
 * table — named as prose, not linked, because this package must not know its consumers: a false conflict
 * stops an install, a missed one leaves the status quo. The map
 * is therefore allowed to be incomplete, and is meant to grow when a real column type goes unverified.
 *
 * **What the map cannot do, by construction.** sqlite compiles `uuid`, `string`, and `ulid` all to
 * `varchar`, and `integer`/`bigInteger` both to `integer`, so those pairs are indistinguishable there —
 * accepted rather than papered over, because sqlite is the test driver and Postgres is the production one.
 * The damage cases this exists to catch survive it: `string` vs `bigint` and `uuid` vs `bigint` are
 * different families on every driver, and those are the two sightings with proven damage.
 *
 * **A fourth behaviour, for COMPILED types — {@see self::SOFT}.** A Laravel type like `enum()` has no
 * database type of its own: it compiles to a *character* type plus a check constraint everywhere except
 * MySQL. So the map can positively recognise that compilation and cannot honestly judge anything else —
 * a native Postgres enum type, a domain, a `set` — because a non-match there does not distinguish
 * *"wrong type"* from *"a compilation this map does not model"*. For a soft type a non-match therefore
 * returns `null` (UNVERIFIED) rather than `false`. **The consequence is the point: adding a soft entry can
 * only ever turn UNVERIFIED into TRUE, never into a conflict**, so it cannot arm an install-stopping
 * refusal that was previously dying quietly on a `null`.
 */
class ColumnTypeEquivalence
{
    /**
     * Laravel Blueprint type => driver => acceptable `type_name` values from `Schema::getColumns()`.
     *
     * `*` is the fallback; a named driver REPLACES it rather than extending it, so a narrow driver
     * (MySQL's `char(36)` uuid) does not inherit the broad one.
     *
     * @var array<string, array<string, list<string>>>
     */
    protected const ACCEPTS = [
        // Text
        'string' => ['*' => ['varchar', 'bpchar', 'char', 'nvarchar', 'character varying']],
        'char' => ['*' => ['char', 'bpchar', 'varchar', 'character']],
        'text' => ['*' => ['text', 'clob'], 'mysql' => ['text', 'tinytext', 'mediumtext', 'longtext'], 'mariadb' => ['text', 'tinytext', 'mediumtext', 'longtext']],
        'tinyText' => ['*' => ['text', 'clob'], 'mysql' => ['tinytext', 'text'], 'mariadb' => ['tinytext', 'text']],
        'mediumText' => ['*' => ['text', 'clob'], 'mysql' => ['mediumtext', 'longtext', 'text'], 'mariadb' => ['mediumtext', 'longtext', 'text']],
        'longText' => ['*' => ['text', 'clob'], 'mysql' => ['longtext', 'mediumtext', 'text'], 'mariadb' => ['longtext', 'mediumtext', 'text']],

        // Identifiers
        'uuid' => ['*' => ['uuid'], 'mysql' => ['char', 'varchar'], 'mariadb' => ['char', 'varchar', 'uuid'], 'sqlite' => ['varchar', 'char']],
        'ulid' => ['*' => ['char', 'varchar', 'bpchar']],

        // Numbers
        'integer' => ['*' => ['integer', 'int', 'int4'], 'mysql' => ['int'], 'mariadb' => ['int']],
        'tinyInteger' => ['*' => ['tinyint', 'int2', 'smallint', 'integer']],
        'smallInteger' => ['*' => ['smallint', 'int2', 'integer']],
        'mediumInteger' => ['*' => ['mediumint', 'integer', 'int', 'int4']],
        'bigInteger' => ['*' => ['bigint', 'int8'], 'sqlite' => ['integer', 'bigint']],
        'decimal' => ['*' => ['decimal', 'numeric']],
        'float' => ['*' => ['float', 'float4', 'real', 'double']],
        'double' => ['*' => ['double', 'double precision', 'float8', 'float', 'real']],

        // Booleans — sqlite compiles `boolean()` to `tinyint(1)`, MySQL to `tinyint`, Postgres to `bool`.
        'boolean' => ['*' => ['boolean', 'bool', 'tinyint', 'integer']],

        // Structured
        'json' => ['*' => ['json', 'jsonb'], 'sqlite' => ['text'], 'mysql' => ['json', 'longtext'], 'mariadb' => ['json', 'longtext']],
        'jsonb' => ['*' => ['jsonb', 'json'], 'sqlite' => ['text'], 'mysql' => ['json', 'longtext'], 'mariadb' => ['json', 'longtext']],

        // Time
        'date' => ['*' => ['date']],
        'time' => ['*' => ['time', 'time without time zone']],
        'timeTz' => ['*' => ['timetz', 'time', 'time with time zone']],
        'dateTime' => ['*' => ['timestamp', 'datetime', 'timestamp without time zone']],
        'dateTimeTz' => ['*' => ['timestamptz', 'timestamp', 'datetime', 'timestamp with time zone']],
        'timestamp' => ['*' => ['timestamp', 'datetime', 'timestamp without time zone']],
        'timestampTz' => ['*' => ['timestamptz', 'timestamp', 'datetime', 'timestamp with time zone']],
        'year' => ['*' => ['year', 'integer', 'int', 'int4']],

        // Bytes
        'binary' => ['*' => ['blob', 'bytea', 'varbinary', 'longblob', 'binary']],

        // Compiled — SOFT, see below. Laravel compiles `enum()` to `varchar(255) check (…)` on Postgres,
        // `varchar check (…)` on sqlite, `nvarchar(255) check (…)` on SQL Server, and a real `enum` only
        // on MySQL/MariaDB, so the accepted set is the character family everywhere but there.
        'enum' => [
            '*' => ['varchar', 'character varying', 'nvarchar', 'bpchar', 'char', 'character'],
            'mysql' => ['enum'],
            'mariadb' => ['enum'],
        ],
    ];

    /**
     * Declared types whose non-match is UNVERIFIED rather than a conflict.
     *
     * ⚠️ **What this cannot see, stated because the entry above is what introduces the blind spot.** The
     * comparison is between type NAMES. A soft type's real content is its allowed-value set, and that is
     * carried in a check constraint (Postgres, sqlite, SQL Server) or in the type's own parameters
     * (MySQL) — neither of which `Schema::getColumns()` reports as `type_name`. So `enum(['a','b'])`
     * against a live column constrained to `['a','b','c']` verifies **clean**. Convergence has no verb
     * that could repair such a divergence anyway, but a reader must not mistake TRUE here for
     * *"the constraint agrees"*; it means *"the compiled storage type agrees"*.
     *
     * @var list<string>
     */
    protected const SOFT = ['enum'];

    /**
     * Whether the map has an entry for a declared Laravel type at all — i.e. whether
     * {@see self::matches()} can return anything but `null` for it.
     *
     * **This is the question an audit asks, and it is NOT answerable through `matches()`.** That method
     * folds "no mapping for this type" and "mapped, and the column disagrees" into two different returns
     * (`null` vs `false`) only once you already have a live column to compare against. An instrument that
     * wants to know *"which declared types in this estate's convergent stubs would go UNVERIFIED"* has no
     * database, no column, and nothing to pass as `$actual` — so without this it would have to reflect on
     * a `protected const`, which makes the map's shape accidental API.
     *
     * Added 2026-08-27 for beam-facade ticket 183a
     * ({@see \Rushing\Surgeon\Conformance\UnmappedConvergentTypeAudit} — named as prose, not linked,
     * because this package must not know its consumers). Pass a driver to ask the narrower question,
     * since a named driver REPLACES the `*` fallback rather than extending it.
     *
     * ⚠️ A SOFT type answers TRUE here and can still report `unverified` at a live column, because its
     * non-match degrades to `null` — so this is *"the map has an entry"*, not *"a verdict is guaranteed"*.
     * {@see self::isSoft()}.
     */
    public static function isMapped(string $declared, ?string $driver = null): bool
    {
        if ($driver === null) {
            return isset(static::ACCEPTS[$declared]);
        }

        return (static::ACCEPTS[$declared][$driver] ?? static::ACCEPTS[$declared]['*'] ?? null) !== null;
    }

    /**
     * Every declared Laravel type the map knows, sorted. The companion to {@see self::isMapped()}: an
     * audit reporting an unmapped type is more useful when it can also say what the map does cover, and
     * the remedy for most findings is *add the pairing here*.
     *
     * @return list<string>
     */
    public static function mappedTypes(): array
    {
        $types = array_keys(static::ACCEPTS);
        sort($types);

        return $types;
    }

    /**
     * Whether a declared type is SOFT — recognised positively, never judged negatively.
     * {@see self::SOFT} for why, and for the blind spot the entry carries.
     */
    public static function isSoft(string $declared): bool
    {
        return in_array($declared, static::SOFT, true);
    }

    /**
     * TRUE / FALSE / null-for-unverified. `$actual` is the raw `type_name` from `Schema::getColumns()`.
     */
    public static function matches(string $driver, string $declared, string $actual): ?bool
    {
        $accepted = static::ACCEPTS[$declared][$driver] ?? static::ACCEPTS[$declared]['*'] ?? null;

        if ($accepted === null) {
            return null;
        }

        $matched = in_array(strtolower(trim($actual)), $accepted, true);

        // A soft type's non-match is a gap in the MAP, not a disagreement in the schema, so it degrades
        // to UNVERIFIED rather than manufacturing a conflict this class cannot defend.
        if (! $matched && static::isSoft($declared)) {
            return null;
        }

        return $matched;
    }
}
