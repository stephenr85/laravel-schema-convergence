<?php

namespace Rushing\SchemaConvergence\Tests;

use BadMethodCallException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;
use Rushing\SchemaConvergence\SchemaConflict;

/**
 * The non-destructive read (`report()`) and the migration-level rehearsal built on it — beam-facade
 * ticket 84, where a guard that speaks from inside `migrate` told an operator about one conflict three
 * tables in and left ~30 more unasked.
 *
 * The property under test throughout is that a prediction is EXACT, not merely indicative: every case
 * here asserts the prediction and then applies the same declaration and asserts the two agree. A
 * preflight whose answer differs from the run is worse than no preflight, because an operator acts on it.
 */
class ConvergenceReportTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('widgets');

        parent::tearDown();
    }

    public function test_a_report_on_an_absent_table_predicts_the_create_and_writes_nothing(): void
    {
        $report = $this->declaration()->report();

        $this->assertTrue($report->created);
        $this->assertFalse($report->applied);
        $this->assertSame('would create `widgets`', $report->summary());
        $this->assertFalse(Schema::hasTable('widgets'));
    }

    public function test_a_report_predicts_the_columns_the_run_then_adds(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
        });

        $predicted = $this->declaration()->report();

        $this->assertSame(['name', 'payload'], $predicted->addedColumns);
        $this->assertFalse($predicted->applied);
        $this->assertTrue($predicted->changesSchema());
        $this->assertSame('would converge `widgets`: add name, payload', $predicted->summary());
        $this->assertFalse(Schema::hasColumn('widgets', 'name'));

        $applied = $this->declaration()->assert();

        $this->assertSame($predicted->addedColumns, $applied->addedColumns);
        $this->assertSame($predicted->addedIndexes, $applied->addedIndexes);
        $this->assertTrue($applied->applied);
    }

    public function test_a_report_predicts_an_index_the_live_table_lacks(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('payload')->nullable();
        });

        $definition = fn () => ConvergentTable::named('widgets')->define(function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('payload')->nullable();
            $table->unique(['name'], 'widgets_name_unique');
        });

        $predicted = $definition()->report();

        $this->assertSame(['widgets_name_unique'], $predicted->addedIndexes);
        $this->assertFalse(Schema::hasIndex('widgets', 'widgets_name_unique'));

        $this->assertSame($predicted->addedIndexes, $definition()->assert()->addedIndexes);
    }

    public function test_a_fluent_index_on_a_missing_column_is_not_predicted_because_it_rides_the_alter(): void
    {
        // The one place predicting is not "the same, minus the write": when applying, the column is added
        // WITH its fluent index and `hasIndex()` then skips it, so it never reaches `addedIndexes`. A
        // prediction that listed it would promise a statement the run never records.
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
        });

        $definition = fn () => ConvergentTable::named('widgets')->define(function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->index();
        });

        $predicted = $definition()->report();

        $this->assertSame(['name'], $predicted->addedColumns);
        $this->assertSame([], $predicted->addedIndexes);

        $applied = $definition()->assert();

        $this->assertSame([], $applied->addedIndexes);
        $this->assertTrue(Schema::hasIndex('widgets', ['name']));
    }

    public function test_a_report_names_the_conflict_without_throwing_and_without_writing(): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->bigInteger('name')->nullable();
        });

        $report = $this->declaration()->report();

        $this->assertTrue($report->hasConflicts());
        $this->assertFalse($report->converged());
        $this->assertSame(SchemaConflict::TYPE, $report->conflicts[0]->kind);
        $this->assertSame('widgets', $report->conflicts[0]->table);
        $this->assertFalse(Schema::hasColumn('widgets', 'payload'));
    }

    public function test_a_conflict_carries_a_repair_sketch_in_laravels_vocabulary(): void
    {
        // Diagnosis alone left the operator told that two types disagree and nothing else (ticket 84).
        $conflict = SchemaConflict::type('tags', 'id', 'uuid', 'int8');

        $this->assertStringContainsString("Schema::table('tags'", $conflict->repair);
        $this->assertStringContainsString("\$table->uuid('id')->change();", $conflict->repair);
        // Laravel vocabulary, never driver SQL: this class has no connection to ask which driver is live.
        $this->assertStringNotContainsString('ALTER TABLE', $conflict->repair);

        $this->assertStringContainsString('ignoringColumns', SchemaConflict::requiredResidue('t', 'c')->repair);
        $this->assertStringContainsString('nullable', SchemaConflict::requiredAddition('t', 'c')->repair);
    }

    public function test_a_table_that_already_matches_reports_unchanged_in_either_tense(): void
    {
        $this->declaration()->assert();

        $report = $this->declaration()->report();

        $this->assertTrue($report->unchanged());
        $this->assertFalse($report->changesSchema());
        $this->assertSame('`widgets` already matches', $report->summary());
    }

    // The migration-level rehearsal.

    public function test_rehearse_collects_every_guard_in_the_body_and_writes_nothing(): void
    {
        $reports = ConvergentTable::rehearse(function () {
            $this->declaration()->assert();
            ConvergentTable::named('gadgets')->define(fn (Blueprint $t) => $t->uuid('id')->primary())->assert();
        });

        $this->assertCount(2, $reports);
        $this->assertSame(['widgets', 'gadgets'], array_map(fn ($r) => $r->table, $reports));
        $this->assertFalse($reports[0]->applied);
        $this->assertFalse(Schema::hasTable('widgets'));
        $this->assertFalse(Schema::hasTable('gadgets'));
    }

    public function test_a_conflicting_guard_does_not_throw_inside_a_rehearsal(): void
    {
        // The whole point: a rehearsal reports ALL conflicts, so the first one cannot end the run the way
        // it does inside `migrate`.
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->bigInteger('name')->nullable();
        });

        $reports = ConvergentTable::rehearse(function () {
            $this->declaration()->assert();
            ConvergentTable::named('gadgets')->define(fn (Blueprint $t) => $t->uuid('id')->primary())->assert();
        });

        $this->assertTrue($reports[0]->hasConflicts());
        $this->assertTrue($reports[1]->created);
    }

    public function test_the_quiet_terminal_is_rehearsed_too_and_still_answers_its_bool(): void
    {
        $answers = [];

        $reports = ConvergentTable::rehearse(function () use (&$answers) {
            $answers[] = $this->declaration()->matches();
        });

        $this->assertSame([true], $answers);
        $this->assertCount(1, $reports);
        $this->assertFalse(Schema::hasTable('widgets'));
    }

    public function test_the_mode_is_cleared_even_when_the_body_throws(): void
    {
        try {
            ConvergentTable::rehearse(function () {
                throw new \RuntimeException('the migration blew up');
            });
        } catch (\RuntimeException) {
            // expected — a rehearsal does not swallow the body's own failures.
        }

        $this->assertFalse(ConvergentTable::rehearsing());
        $this->assertTrue($this->declaration()->assert()->created);
    }

    public function test_a_rehearsal_does_not_nest(): void
    {
        $this->expectException(BadMethodCallException::class);

        ConvergentTable::rehearse(fn () => ConvergentTable::rehearse(fn () => null));
    }

    /** The declaration under test: two columns beyond a bare `id`, one required and one not. */
    protected function declaration(): ConvergentTable
    {
        return ConvergentTable::named('widgets')->define(function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('payload')->nullable();
        });
    }
}
