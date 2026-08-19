<?php

namespace Rushing\SchemaConvergence\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Deliberately empty of package wiring.
 *
 * This package ships no service provider, no config, and no facade — it is a plain library over
 * `Illuminate\Database\Schema\Builder`. So the base case registers NOTHING, and that is the
 * extraction's own proof: if this file ever needs a provider to make the guard work, the utility has
 * grown a host dependency it did not have in `splicewire/laravel-beam`, where it booted under beam's
 * full provider stack purely by accident of where it lived.
 *
 * The connection is testbench's default in-memory sqlite. Note the limit that buys, recorded rather
 * than papered over (beam-facade ticket 27): sqlite collapses `uuid`/`string`/`ulid` to `varchar` and
 * `integer`/`bigInteger` to `integer`, so a uuid-vs-string conflict CANNOT be expressed here. The two
 * sightings with proven damage survive it because they cross type families.
 */
abstract class TestCase extends Orchestra
{
    //
}
