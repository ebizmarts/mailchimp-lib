<?php
/**
 * Tests the rule that decides what an area or an action may carry.
 *
 * Three of these cases are one byte away from a rule that looks identical and
 * is wrong, and none of the three fails loudly when it breaks -- the token
 * simply stops arriving, the beacon still answers 202, and nothing anywhere
 * records that anything was dropped. That is the failure this file exists to
 * make noisy.
 *
 * No PHPUnit, for the same reason the gate test next door has none: this
 * library declares php >=5.2.0 and the runner has to be able to run anywhere
 * the library does.
 *
 * Usage: php tools/tests/test-surface-token.php
 * Exit:  0 all passed, 1 failures.
 */

require dirname(dirname(__DIR__)) . '/src/Mailchimp.php';

$method = new ReflectionMethod('Mailchimp_Telemetry', 'surfaceToken');
$method->setAccessible(true);

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

/**
 * @param  mixed $value
 * @return mixed
 */
function token($value)
{
    global $method;

    return $method->invoke(null, $value);
}

echo "carried unchanged\n";
check('a routed frontend action',        token('mailchimp_campaign_check') === 'mailchimp_campaign_check');
check('a routed admin action',           token('mailchimp_orders_campaign') === 'mailchimp_orders_campaign');
check('an area',                         token('adminhtml') === 'adminhtml');
check('an area outside the receiver set', token('quokka') === 'quokka');
check('exactly the cap',                 token(str_repeat('a', 128)) === str_repeat('a', 128));
// Stock 2.4 ships mixed-case route ids, and Router\Base sets the controller
// and action from the raw path. Folding case here would blind us to those
// route families silently and permanently.
check('a stock mixed-case route',        token('sales_Order_View') === 'sales_Order_View');
// The route resolved and the controller and action did not. That is a true
// thing about the dispatch and it is worth reporting, so the rule is "carries
// something that is not a separator" rather than anything about underscores.
check('a route with the rest unrouted',  token('mailchimp__') === 'mailchimp__');

echo "\nrejected whole, never repaired\n";
check('over the cap by one byte',        token(str_repeat('a', 129)) === null);
// PHP's `$` also matches immediately before a final newline; the receiver's
// equivalent is JavaScript, where it does not. Ported literally this rule
// would accept exactly one byte the receiver refuses, and the token would
// vanish with nothing recorded at either end. If someone "normalises" \z back
// to $, this is the check that says so.
check('a trailing newline',              token("checkout_index\n") === null);
check('an interior NUL',                 token("checkout_index\x00_index") === null);
// json_encode() returns false on malformed UTF-8 and flush() drops a body that
// is not a string, so this one byte would have cost the whole report.
check('malformed UTF-8',                 token("checkout\xFFindex") === null);
check('path traversal',                  token('../../etc/passwd') === null);
check('CRLF injection',                  token("a\r\nX-Injected: 1") === null);
check('SQL',                             token("a' OR 1=1--") === null);
check('markup',                          token('<script>alert(1)</script>') === null);
check('JNDI',                            token('${jndi:ldap://x/a}') === null);
check('a Cyrillic homoglyph',            token("check\xD0\xBEut_index") === null);
check('a bidi override',                 token("check\xE2\x80\xAEout") === null);
check('a trailing space',                token('checkout_index ') === null);
check('a namespace separator',           token('Magento\\Checkout\\Index') === null);
check('the empty string',                token('') === null);
check('a non-string',                    token(array('a')) === null && token(12345) === null);

echo "\nnames nothing\n";
// An unrouted request composes the separators and nothing else.
check('the bare delimiters',             token('__') === null);
check('a single delimiter',              token('_') === null);
check('nothing but delimiters',          token('____') === null);

echo "\nwhy the pattern ends at \\z\n";
// Not a property of our code -- a property of PHP's `$` that the rule has to
// work around. Asserted so the reason survives even if the line is edited.
check('PHP $ accepts a trailing newline', preg_match('/^[A-Za-z0-9_]+$/', "checkout_index\n") === 1);
check('PHP \\z does not',                 preg_match('/^[A-Za-z0-9_]+\z/', "checkout_index\n") === 0);

echo "\nwhat reaches the envelope\n";
$telemetry = new Mailchimp_Telemetry();
$telemetry->setSurface('frontend', "checkout\xFFindex");
$telemetry->setSurface('adminhtml', 'sales_order_view');
$read = function ($name) use ($telemetry) {
    $property = new ReflectionProperty('Mailchimp_Telemetry', $name);
    $property->setAccessible(true);

    return $property->getValue($telemetry);
};
// Per token and independently: a rejected action does not cost the area, and a
// later valid action still completes what the first call left unset.
check('the first valid area wins',   $read('_area') === 'frontend');
check('a later action completes it', $read('_action') === 'sales_order_view');

if ($failures) {
    printf("\n%d check(s) failed\n", count($failures));
    exit(1);
}
echo "\nall checks passed\n";
exit(0);
