<?php
/**
 * Tests the gate itself.
 *
 * A gate nobody tests stops working quietly, and the failure mode is the one
 * it exists to prevent: everything looks green because nothing is looked at.
 * The fixture is deliberately adversarial — every case in it is one this
 * repository either contains today or could plausibly grow.
 */

$scanner = dirname(__DIR__) . '/no-deprecated-calls.php';
$fixture = __DIR__ . '/fixtures/Cases.php';

exec(sprintf('%s %s %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($scanner), escapeshellarg($fixture)), $out, $code);
$output = implode("\n", $out);

$failures = array();

/**
 * @param string $label
 * @param bool   $ok
 */
function check($label, $ok)
{
    global $failures;
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $label);
    if (!$ok) {
        $failures[] = $label;
    }
}

$reported = function ($needle) use ($output) {
    return strpos($output, $needle) !== false;
};

echo "must be reported\n";
check('a call after the guard block has closed',      $reported('Cases.php:23'));
check('a bare utf8_encode',                           $reported('Cases.php:35'));
check('the deprecated FILTER_SANITIZE_STRING',        $reported('Cases.php:39'));
check('a call after an if with no braces',            $reported('Cases.php:44'));
check('a bare call following an interpolated string', $reported('Cases.php:61'));

echo "must NOT be reported\n";
check('the deliberate call inside a PHP_VERSION guard', !$reported('Cases.php:8'));
check('a call nested deeper inside that guard',         !$reported('Cases.php:14'));
check("'money_format' used as an array key",            !$reported('Cases.php:28'));
check('a method that shares the name of a function',    !$reported('Cases.php:32'));
// String interpolation opens with an array token and closes with a plain '}',
// so a naive depth counter drops the enclosing guard and reports correct code.
check('a guarded call after an interpolated string',    !$reported('Cases.php:54'));

echo "exit status\n";
check('non-zero when there are findings', $code === 1);
check('exactly five findings',            (bool)preg_match('/5 finding\(s\)/', $output));

if ($failures) {
    printf("\n%d check(s) failed\n", count($failures));
    exit(1);
}
echo "\nall checks passed\n";
exit(0);
