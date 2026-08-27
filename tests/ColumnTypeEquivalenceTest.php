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
        $this->assertFalse(ColumnTypeEquivalence::isMapped('enum'));
        $this->assertFalse(ColumnTypeEquivalence::isMapped('ipAddress'));
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
