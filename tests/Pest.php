<?php

declare(strict_types=1);

// Pest configuration.
//
// Every test here needs the WordPress stand-ins: Brain Monkey's lifecycle,
// Mockery integration and wp-mocks' WpState reset, all of which come from
// wp-mocks' TestCase. Almost all of them also need what Reach\Tests\ReachTestCase
// adds on top — hook capture, the wp_salt() alias, the session helpers — so
// every spec runs on ReachTestCase except UserAgentTest, which extended
// wp-mocks' TestCase directly as a class and still does.
//
// So this binding is load-bearing. A spec that ends up on Pest's default,
// plain PHPUnit TestCase finds none of Brain Monkey's functions defined, and
// any Mockery expectation in it is never verified — every test in it passes
// whatever the code does.
//
// The list is built from the directory rather than written out so that a new
// spec is covered without anyone remembering to add it. Admin/ is a
// directory; the rest are the top-level *Test.php files.
//
// One file stays a PHPUnit class rather than a Pest closure file:
// SecureTransportConstantTest, which defines REACH_ALLOW_INSECURE_TRANSPORT.
// A defined constant cannot be undone, so that test runs in a separate
// process — and Pest refuses process isolation outright. Pest runs the class
// as it is; the bindings below only apply to closure-based files.

use BleedingDeacons\WpMocks\TestCase;
use Reach\Tests\ReachTestCase;

$onWpMocksTestCase = ['UserAgentTest.php'];

$onReachTestCase = array_values(array_diff(
    array_map('basename', glob(__DIR__ . '/*Test.php') ?: []),
    $onWpMocksTestCase,
));

pest()->extend(ReachTestCase::class)->in('Admin', ...$onReachTestCase);
pest()->extend(TestCase::class)->in(...$onWpMocksTestCase);
