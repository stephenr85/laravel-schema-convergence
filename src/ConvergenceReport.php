<?php

namespace Rushing\SchemaConvergence;

/**
 * What {@see ConvergentTable} found and what it did about it — the value both terminals are built on:
 * `assert()` throws when {@see hasConflicts()}, `matches()` returns its negation.
 *
 * Deliberately also readable on its own, because the interesting migrations are the ones that did
 * NOTHING: a table that already matched is indistinguishable at the database from a table this run
 * created, and a host chasing an install is entitled to know which happened.
 *
 * `unverified` is the conservative posture: a declared column type this build has no driver mapping for
 * is REPORTED, never guessed at and never escalated to a conflict. A false conflict stops an install; a
 * missed one is the status quo. (Inherited from the under-report-rather-than-mislead posture beam's
 * migration-ordering audit takes with a dynamically-named table — named as prose, not linked, because
 * this package must not know its consumers.)
 *
 * ## Two tenses, one shape (beam-facade ticket 84)
 *
 * The same value describes what {@see ConvergentTable::assert()} DID and what
 * {@see ConvergentTable::report()} WOULD DO — `applied` is which. A prospective report is not a weaker
 * report: it is computed from the identical comparison and differs only in that the ALTERs were never
 * issued, which is the whole point of asking before a migrate rather than during one.
 *
 * `applied` is therefore never a hedge. `created`/`addedColumns`/`addedIndexes` mean exactly the same
 * columns either way; the flag governs the VERB, so a preflight cannot print "created `tags`" about a
 * table it has not created. Everything a conflict says is tense-free — a conflict is a fact about the
 * live table and about the declaration, and neither moves depending on who asked.
 */
class ConvergenceReport
{
    /**
     * @param  list<string>  $addedColumns
     * @param  list<string>  $addedIndexes
     * @param  list<SchemaConflict>  $conflicts
     * @param  list<string>  $unverified  columns whose declared type has no mapping for this driver
     * @param  bool  $applied  false ⇒ this is a prediction and NOTHING was written
     */
    public function __construct(
        public string $table,
        public bool $created = false,
        public array $addedColumns = [],
        public array $addedIndexes = [],
        public array $conflicts = [],
        public array $unverified = [],
        public bool $applied = true,
    ) {}

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }

    /** True when the live table now carries (or, on a prediction, would carry) every declared column. */
    public function converged(): bool
    {
        return ! $this->hasConflicts();
    }

    /** True when the table already matched, so convergence has nothing to do. */
    public function unchanged(): bool
    {
        return ! $this->created && $this->addedColumns === [] && $this->addedIndexes === [];
    }

    /** True when this run would change the schema — the preflight's "not clean" predicate. */
    public function changesSchema(): bool
    {
        return ! $this->unchanged();
    }

    public function summary(): string
    {
        if ($this->created) {
            return $this->applied ? "created `{$this->table}`" : "would create `{$this->table}`";
        }

        if ($this->hasConflicts()) {
            return "`{$this->table}` cannot converge (".count($this->conflicts).' conflict'
                .(count($this->conflicts) === 1 ? '' : 's').')';
        }

        if ($this->unchanged()) {
            return "`{$this->table}` already matches";
        }

        $parts = [];

        if ($this->addedColumns !== []) {
            $parts[] = ($this->applied ? 'added ' : 'add ').implode(', ', $this->addedColumns);
        }

        if ($this->addedIndexes !== []) {
            $parts[] = ($this->applied ? 'indexed ' : 'index ').implode(', ', $this->addedIndexes);
        }

        return ($this->applied ? 'converged ' : 'would converge ')."`{$this->table}`: ".implode('; ', $parts);
    }
}
