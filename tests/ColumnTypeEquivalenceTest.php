<?php

namespace Rushing\SchemaConvergence\Tests;

use Rushing\SchemaConvergence\ColumnTypeEquivalence;

/**
 * `isMapped()` / `mappedTypes()` — beam-facade ticket 183a. The map's own coverage, asked as a question
 * an instrument with no database can answer.
 *
 * `matches()` folds *"there is no mapping for this type"* and *"mapped, and the column disagrees"* into
 * `null` and `false`, but only once you hold a live column to pass as `$actual`. An audit that wants to
 * know which declared types in an estate's convergent stubs would go UNVERIFIED holds no column at all,
 * so without these two it would have to reflect on a `protected const` — which would make the map's
 * internal shape into API by accident.
 */
class ColumnTypeEquivalenceTest extends TestCase
{
    public function test_a_type_the_map_knows_is_mapped(): void
    {
        $this->assertTrue(ColumnTypeEquivalence::isMapped('uuid'));
        $this->assertTrue(ColumnTypeEquivalence::isMapped('bigInteger'));
    }

    public function test_a_type_the_map_does_not_know_is_not_mapped(): void
    {
        // Real Blueprint methods the map has no entry for — this is the population 183a exists to find,
        // not a hypothetical.
        $this->assertFalse(ColumnTypeEquivalence::isMapped('geometry'));
        $this->assertFalse(ColumnTypeEquivalence::isMapped('ipAddress'));
        $this->assertFalse(ColumnTypeEquivalence::isMapped('macAddress'));
    }

    /**
     * beam-facade 184 — `enum` was the estate's ONE unmapped declared type, measured three times across
     * 14–15 `~/Herd` roots (183a by stub text, 187 by rehearsal, and a `psql` read in between). It is
     * mapped now, and this is the assertion that says which compilation the entry claims.
     */
    public function test_enum_is_mapped_to_the_character_family_it_actually_compiles_to(): void
    {
        $this->assertTrue(ColumnTypeEquivalence::isMapped('enum'));

        // `varchar(255) check (…)` on Postgres, `varchar check (…)` on sqlite, `nvarchar(255) check (…)`
        // on SQL Server — the pairing the flagship's `federation_grants.pin_mode` actually holds.
        $this->assertTrue(ColumnTypeEquivalence::matches('pgsql', 'enum', 'varchar'));
        $this->assertTrue(ColumnTypeEquivalence::matches('sqlite', 'enum', 'varchar'));
        $this->assertTrue(ColumnTypeEquivalence::matches('sqlsrv', 'enum', 'nvarchar'));

        // …and a real `enum` only on MySQL/MariaDB, where the `*` fallback would have been wrong.
        $this->assertTrue(ColumnTypeEquivalence::matches('mysql', 'enum', 'enum'));
        $this->assertTrue(ColumnTypeEquivalence::matches('mariadb', 'enum', 'enum'));
    }

    /**
     * The precision half of that widening, and the reason it is safe to land ahead of
     * [184](escalate-unverified-on-a-populated-table): a soft entry can turn UNVERIFIED into TRUE and
     * **can never turn it into a conflict**. Without this, a host holding a native Postgres enum type,
     * or a domain, would go from a quiet advisory to an install-stopping refusal — 317 of the estate's
     * 329 convergent declarations call the throwing terminal.
     */
    public function test_a_soft_types_non_match_degrades_to_unverified_rather_than_arming_a_conflict(): void
    {
        $this->assertTrue(ColumnTypeEquivalence::isSoft('enum'));

        // A native Postgres enum type reports its own type name; a `set` reports `set`. Neither is the
        // compilation this map models, and neither is evidence the schema is wrong.
        $this->assertNull(ColumnTypeEquivalence::matches('pgsql', 'enum', 'pin_mode_enum'));
        $this->assertNull(ColumnTypeEquivalence::matches('mysql', 'enum', 'set'));

        // The contrast: a HARD type still conflicts, which is the whole point of the map.
        $this->assertFalse(ColumnTypeEquivalence::isSoft('uuid'));
        $this->assertFalse(ColumnTypeEquivalence::matches('pgsql', 'uuid', 'bigint'));
    }

    /**
     * The whole reason this is not `matches($driver, $declared, '') !== null`: an unmapped type and a
     * mapped-but-disagreeing column are different findings, and only the first one is 183a's.
     */
    public function test_is_mapped_is_indifferent_to_whether_a_column_would_agree(): void
    {
        $this->assertTrue(ColumnTypeEquivalence::isMapped('uuid'));
        $this->assertFalse(ColumnTypeEquivalence::matches('pgsql', 'uuid', 'bigint'));
        $this->assertTrue(ColumnTypeEquivalence::matches('pgsql', 'uuid', 'uuid'));
    }

    /**
     * A named driver REPLACES the `*` fallback rather than extending it, so "mapped" has to be asked per
     * driver when a driver is known. `uuid` is mapped on every driver; the asymmetry the map documents is
     * in WHICH values are accepted, not in whether an entry exists — so a driver-scoped miss needs a type
     * the map covers only under `*`.
     */
    public function test_a_driver_may_be_named_and_falls_back_to_the_star_entry(): void
    {
        $this->assertTrue(ColumnTypeEquivalence::isMapped('uuid', 'mysql'));
        $this->assertTrue(ColumnTypeEquivalence::isMapped('decimal', 'pgsql'));
        $this->assertFalse(ColumnTypeEquivalence::isMapped('geometry', 'pgsql'));
    }

    public function test_mapped_types_reports_the_map_sorted_and_is_consistent_with_is_mapped(): void
    {
        $types = ColumnTypeEquivalence::mappedTypes();

        $this->assertNotEmpty($types);
        $sorted = $types;
        sort($sorted);
        $this->assertSame($sorted, $types);

        foreach ($types as $type) {
            $this->assertTrue(ColumnTypeEquivalence::isMapped($type), "mappedTypes() reported {$type}, isMapped() disagrees");
        }
    }
}
