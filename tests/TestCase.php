<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Page tests assert server behavior, not compiled assets. `public/build` is not
        // versioned, so tests must pass on a clean checkout before any frontend build.
        $this->withoutVite();
    }
}
