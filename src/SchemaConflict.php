<?php

namespace Rushing\SchemaConvergence;

/**
 * One reason a table cannot be converged onto the shape a migration declares — the third tier of
 * {@see ConvergentTable}'s verdict table, where "add the missing thing" is not available.
 *
 * Three kinds, and the distinction is not cosmetic: each names a DIFFERENT repair, and the whole
 * point of throwing rather than skipping is that a human is told which one.
 *
 * - `type` — the column exists with an incompatible database type. The damage class this utility
 *   exists to end: `hasColumns()` returns TRUE for it, so a bare existence guard converges nothing
 *   and reports success against a table the app cannot use (lunarphp's bigint `nullableMorphs`
 *   where beam's `activity_log` needs string morphs; the bigint-vs-uuid `roles.id` break).
 * - `required-addition` — a declared column is NOT NULL with no default and the table already holds
 *   rows, so the ALTER itself would fail. Convergence handles ABSENCE, not a populated table.
 * - `required-residue` — a column the declaration does not know about is NOT NULL with no default,
 *   so every canonical insert fails AFTER a textbook-clean convergence. Found on the submissions
 *   fork (beam-facade ticket 23), which is neither a missing-column nor a type case: the fork's own
 *   `schema_record_id` survives convergence and then rejects every write.
 *
 * ## Diagnosis and repair are two fields, deliberately
 *
 * `detail` says what is wrong and why convergence cannot reach it. {@see $repair} says what to DO about
 * it, as a code sketch the operator can paste. They are separate because the diagnosis is complete on its
 * own and the repair is a suggestion: this class knows the declared type, the live type and the table, so
 * it can write the reconciling statement — but it cannot know whether the column holds data that survives
 * the cast, so every sketch is offered with its caveat rather than as an instruction (beam-facade ticket
 * 84, which found an operator told the two types disagree and left to work out the rest).
 *
 * A sketch is written in Laravel's schema vocabulary, never in driver SQL. `->change()` compiles per
 * driver and this class has no connection to ask which one is live; a `Schema::table()` sketch is exact
 * everywhere, and it is also the form the resulting migration has to take anyway.
 */
class SchemaConflict
{
    public const TYPE = 'type';

    public const REQUIRED_ADDITION = 'required-addition';

    public const REQUIRED_RESIDUE = 'required-residue';

    public function __construct(
        public string $kind,
        public string $table,
        public string $column,
        public string $detail,
        public string $repair = '',
    ) {}

    public static function type(string $table, string $column, string $declared, string $actual): self
    {
        return new self(
            self::TYPE,
            $table,
            $column,
            "`{$table}.{$column}` is `{$actual}` in the database but this migration declares `{$declared}`. "
            .'Convergence adds missing columns; it does not convert a column that already exists with the '
            .'wrong type. Reconcile the two declarations, or migrate the column deliberately.',
            "Convert it in a migration of its own, if `{$actual}` data casts to `{$declared}`:\n"
            ."    Schema::table('{$table}', function (Blueprint \$table) {\n"
            ."        \$table->{$declared}('{$column}')->change();\n"
            ."    });\n"
            ."Or amend whichever of the two declarations is wrong — a column that carries `{$actual}` data "
            ."today usually means the other package's shape is the one in use.",
        );
    }

    public static function requiredAddition(string $table, string $column): self
    {
        return new self(
            self::REQUIRED_ADDITION,
            $table,
            $column,
            "`{$table}.{$column}` is declared NOT NULL with no default and `{$table}` already holds rows, "
            .'so it cannot be added. Give the column a default, make it nullable, or backfill it in a '
            .'migration of its own.',
            "Add it nullable, backfill, then require it — three statements, one migration:\n"
            ."    Schema::table('{$table}', fn (Blueprint \$t) => \$t->…('{$column}')->nullable());\n"
            ."    DB::table('{$table}')->whereNull('{$column}')->update(['{$column}' => …]);\n"
            ."    Schema::table('{$table}', fn (Blueprint \$t) => \$t->…('{$column}')->nullable(false)->change());\n"
            .'Or give the declaration a default, which converges with no migration at all.',
        );
    }

    public static function requiredResidue(string $table, string $column): self
    {
        return new self(
            self::REQUIRED_RESIDUE,
            $table,
            $column,
            "`{$table}.{$column}` exists, is NOT NULL with no default, and this migration does not declare it — "
            .'so the table converges cleanly and then rejects every insert this package makes. Usually a fork '
            .'of the table under the same name. Reconcile it, or pass its name to `ignoringColumns()` if the '
            .'host is filling it deliberately.',
            "If the host fills `{$column}` deliberately, say so on the guard:\n"
            ."    ConvergentTable::named('{$table}')->ignoringColumns('{$column}')->…\n"
            .'Otherwise the live table is a fork: make the column nullable or give it a default, or drop it '
            .'if nothing reads it.',
        );
    }
}
