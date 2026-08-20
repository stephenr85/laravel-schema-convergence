<?php

namespace Rushing\SchemaConvergence;

use RuntimeException;

/**
 * Thrown by {@see ConvergentTable::assert()} when the live table cannot be converged onto the shape a
 * migration declares — tier three of the verdict table, where "add the missing thing" is unavailable.
 *
 * This is the noise the whole convergent-guard convention exists to make. The failure it replaces is
 * silent: two migrations create the same table, both guard with `hasTable`, whichever sorts first wins
 * and the loser's guard reports SUCCESS — so `migrate:fresh` exits green having produced a schema the
 * app cannot use, and only a seeder or a production write notices (beam-facade tickets 13, 16/17, 20).
 *
 * A conflict is therefore an install-time stop by design. The escape is {@see ConvergentTable::matches()},
 * the quiet terminal, for the call sites that genuinely do not care which shape won.
 *
 * The named constructor is `from()`, and it must never be called `report()`: Laravel's exception
 * handler treats a `report` method on a throwable as the custom-reporting hook
 * (`Reflector::isCallable([$e, 'report'])` in `Foundation\Exceptions\Handler`, which then invokes it
 * THROUGH THE CONTAINER). A static named constructor of that name is callable by that test, so the
 * container tries to build its `ConvergenceReport` argument and dies with
 * `Unresolvable dependency resolving [Parameter #0 [ <required> string $table ]]` — replacing this
 * exception's entire diagnosis with a container error at the one moment an operator needs to read it.
 * Observed swallowing a live `permissions.id` uuid-vs-integer conflict during
 * `splicewire:beam:install` at `~/Herd/fable` (beam-facade ticket 38).
 */
class SchemaConvergenceConflict extends RuntimeException
{
    public ConvergenceReport $report;

    public static function from(ConvergenceReport $report): self
    {
        $lines = array_map(
            fn (SchemaConflict $conflict) => "  - [{$conflict->kind}] {$conflict->detail}",
            $report->conflicts,
        );

        $exception = new self(
            "Cannot converge `{$report->table}` onto the shape this migration declares:\n"
            .implode("\n", $lines)
            ."\nNothing was written — the table is exactly as it was."
        );

        $exception->report = $report;

        return $exception;
    }
}
