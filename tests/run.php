<?php
/**
 * Dependency-free test runner: `php tests/run.php`.
 *
 * Loads every tests/test-*.php file. Each test file registers cases with
 * it( 'description', function () { ... } ) and asserts by returning true/false
 * or throwing. Exits non-zero on any failure, so it can gate CI or packaging.
 */

define( 'ABSPATH', __DIR__ . '/' ); // Satisfy the plugin files' direct-access guard.

$failures = 0;
$cases    = 0;

function it( $description, $test ) {
	global $failures, $cases;
	$cases++;
	try {
		$ok = $test();
	} catch ( Throwable $e ) {
		$ok = false;
		$description .= ' — threw ' . get_class( $e ) . ': ' . $e->getMessage();
	}
	echo ( false !== $ok ? 'PASS' : 'FAIL' ) . " — {$description}\n";
	if ( false === $ok ) {
		$failures++;
	}
}

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	echo '# ' . basename( $file ) . "\n";
	require $file;
}

echo "\n{$cases} tests, {$failures} failures\n";
exit( $failures ? 1 : 0 );
