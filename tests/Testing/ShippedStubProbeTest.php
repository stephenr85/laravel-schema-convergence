<?php

namespace Rushing\SchemaConvergence\Tests\Testing;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\Testing\ShippedStubProbe;
use Rushing\SchemaConvergence\Tests\TestCase;

/**
 * The probe that lets a package suite ask whether its hand-built fixture still matches the migration
 * stubs it SHIPS.
 *
 * Every case below is written against a divergence that is deliberately CREATED, because the failure
 * this class exists to prevent is a comparison that reports agreement by never looking. A test that
 * only asserted "no divergences" over a correct fixture would pass identically if the stubs never ran.
 */
class ShippedStubProbeTest extends TestCase
{
    private function probe(): ShippedStubProbe
    {
        return new ShippedStubProbe(__DIR__.'/../fixtures/migrations');
    }

    public function test_it_finds_stub_stems_in_nested_directories_not_just_basenames(): void
    {
        $this->assertSame(
            ['create_probe_widgets_table', 'shared/create_probe_parts_table'],
            $this->probe()->stemsOnDisk(),
        );
    }

    public function test_declared_stems_run_first_and_undeclared_ones_still_run(): void
    {
        $ordered = $this->probe()->orderedStems(['shared/create_probe_parts_table']);

        $this->assertSame(['shared/create_probe_parts_table', 'create_probe_widgets_table'], $ordered);
    }

    public function test_it_names_a_declared_stem_that_is_not_on_disk(): void
    {
        $this->assertSame(
            ['gone/create_vanished_table'],
            $this->probe()->missingFromDisk(['gone/create_vanished_table', 'create_probe_widgets_table']),
        );
    }

    public function test_it_executes_the_stubs_including_the_convergent_one_a_file_reader_cannot_pair(): void
    {
        $probe = $this->probe();
        $probe->run($probe->orderedStems());

        $this->assertContains('probe_widgets', $probe->tables());
        $this->assertContains('probe_parts', $probe->tables());
    }

    public function test_it_reports_only_tables_both_sides_build(): void
    {
        $probe = $this->probe();
        $probe->run($probe->orderedStems());

        Schema::create('probe_widgets', fn (Blueprint $t) => $t->uuid('id')->primary());
        Schema::create('fixture_only', fn (Blueprint $t) => $t->id());

        $shared = $probe->sharedTables();

        $this->assertSame(['probe_widgets'], $shared);
        $this->assertNotContains('probe_parts', $shared);
    }

    public function test_it_catches_a_column_the_fixture_declares_differently(): void
    {
        $probe = $this->probe();
        $probe->run($probe->orderedStems());

        Schema::create('probe_widgets', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('label');
            $t->string('note');
        });

        $divergences = $probe->divergences($probe->sharedTables());

        $this->assertArrayHasKey('probe_widgets', $divergences);
        $this->assertSame(
            ['stub' => 'varchar nullable', 'fixture' => 'varchar not-null'],
            $divergences['probe_widgets']['note'],
        );
        $this->assertArrayNotHasKey('label', $divergences['probe_widgets']);
    }

    public function test_a_column_only_one_side_builds_is_not_a_divergence(): void
    {
        $probe = $this->probe();
        $probe->run($probe->orderedStems());

        Schema::create('probe_widgets', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('label');
            $t->string('note')->nullable();
            $t->string('fixture_extra');
        });

        $this->assertSame([], $probe->divergences($probe->sharedTables()));
    }

    public function test_the_compared_count_is_what_separates_agreement_from_never_looking(): void
    {
        $probe = $this->probe();
        $probe->run($probe->orderedStems());

        Schema::create('probe_widgets', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('label');
            $t->string('note')->nullable();
        });

        $shared = $probe->sharedTables();

        $this->assertSame([], $probe->divergences($shared));
        $this->assertSame(3, $probe->comparedColumnCount($shared));
    }

    public function test_the_probe_does_not_write_into_the_fixture_connection(): void
    {
        $probe = $this->probe();
        $probe->run($probe->orderedStems());

        $this->assertNotContains('probe_widgets', $probe->tables(config('database.default')));
    }

    public function test_it_runs_a_legacy_named_class_stub_that_returns_nothing(): void
    {
        // `require` on this form yields int 1. A probe assuming the modern anonymous-class spelling
        // dies on "Call to a member function up() on int", which reads as a broken stub and is not
        // one — splicewire/laravel-beam-tenancy ships two of these and Laravel's migrator runs them.
        $probe = new ShippedStubProbe(__DIR__.'/../fixtures/legacy', 'legacy_probe');
        $probe->run($probe->orderedStems());

        $this->assertContains('probe_legacy', $probe->tables());
        $this->assertSame(['id' => 'integer not-null', 'tag' => 'varchar not-null'], $probe->shape('probe_legacy'));
    }
}
