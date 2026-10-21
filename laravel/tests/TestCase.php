<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Pre-0.15 suites deliver regulated orders without a signature; Phase 15 tests set this true. */
    protected bool $requireSignature = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.require_signature' => $this->requireSignature]);
        \App\Audit\Audit::resetPending();
    }

    protected function tearDown(): void
    {
        \App\Audit\Audit::resetPending(); // the RefreshDatabase rollback must not re-write durable log entries
        parent::tearDown();
    }
}
