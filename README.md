# rushing/laravel-schema-convergence

**Convergent migration guards.** A published `create_*` migration declares the *shape it needs*, not
the table it creates.

```php
use Rushing\SchemaConvergence\ConvergentTable;

ConvergentTable::named('beam_ownership_edges')
    ->define(function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('owner_id');
        $table->timestamps();
    })
    ->assert();
```

## The failure it exists to end

`spatie/laravel-package-tools` stamps a published migration with `now()`; vendor packages ship
fixed-date copies. Two migrations creating the **same table** therefore race on filename — and because
both sides guard with a bare `Schema::hasTable($t) → return`, **whichever sorts first wins and the
loser's guard reports success.** `migrate:fresh` exits green having produced a schema the app cannot
use, and nothing notices until something tries to write.

That is not hypothetical. It was sighted three times in a single sweep: `lunarphp/core`'s `activity_log`
holding bigint morph ids where a string was needed, two `create_media_table`s in one host, and a stale
fork of a submissions table outranking the canonical stub in three starters.

Convergence removes the race rather than arbitrating it. Whoever runs first creates; whoever runs second
tops up what is missing; filename order stops mattering.

## Three tiers

| state | verdict |
| --- | --- |
| table absent | create it |
| table present, declared columns absent | **add them** — converge |
| table present, column present with the wrong type | **throw** — cannot converge |

Tier three is the load-bearing one, and it is why "top up whatever is missing" is not enough on its own.
A table whose column exists with the wrong type does not *lack* that column, so `hasColumns()` returns
true and a top-up guard tops up nothing.

**A conflicted run writes nothing at all.** The table is left exactly as it was, so the two terminals —
`->assert()` (throws, the default) and `->matches()` (returns a bool, quiet) — differ only in how loud
they are, never in what they did.

## Install

```
composer require rushing/laravel-schema-convergence
```

No service provider, no config, no facade. It is a plain library over
`Illuminate\Database\Schema\Builder`, and its test suite boots with no provider registered — which is
the point: nothing here needs a host framework's wiring.

## Documentation

- [`docs/agents/convergent-migration-guards.convention.md`](docs/agents/convergent-migration-guards.convention.md)
  — the rule, the tiers, both terminals, and the three shapes convergence deliberately refuses.
- `ConvergentTable`'s own class docblock documents the machinery.

The **other half** of the collision family — cross-package ALTER-vs-CREATE, and the installer lever for
a competitor whose guard you do not own — lives in `splicewire/laravel-beam`'s
`docs/agents/migration-publish-ordering.convention.md`. Neither page is complete alone.

## History

Extracted from `splicewire/laravel-beam`'s `src/Schema/` in August 2026, where it shipped first and
proved out across 118 migration stubs in 22 packages. It moved because the rule is wider than its
original home: packages publishing `create_*` stubs into the same hosts, carrying no dependency on beam,
could not import a guard homed there. A collision does not care which vendor lost.

## License

MIT.
