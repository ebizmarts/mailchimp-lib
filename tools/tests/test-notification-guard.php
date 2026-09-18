<?php
/**
 * Tests that the library notifies only a host that can be notified.
 *
 * `saveNotification()` and `getGmtDate()` are the module's, not this
 * library's, and nothing in composer can hold the pair together: the module
 * requires a minimum library version, a library cannot require a minimum
 * module version, and the module's constraint is a floor with no ceiling. So
 * an installation that runs `composer update` pairs whatever module is on disk
 * with the newest library published, and an app/code install pairs the two
 * with no constraint at all.
 *
 * `saveNotification()` arrived in the module on 2024-12-03 (103.4.65) and the
 * calls to it landed the day after. Every module below that version has been a
 * fatal on both call sites since -- on the answer AND on the failure, which is
 * most of what the extension does. The guards are what allow an old host to
 * simply not be told.
 *
 * No PHPUnit, for the same reason the gates next door have none: this library
 * declares php >=5.2.0 and the runner has to be able to run anywhere the
 * library does.
 *
 * Usage: php tools/tests/test-notification-guard.php
 * Exit:  0 all passed, 1 failures.
 */

require dirname(dirname(__DIR__)) . '/src/Mailchimp.php';

$failures = array();
$notified = array();

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

/** A current module: both methods present. */
class NotifiedHost
{
    public function getGmtDate()
    {
        return '2026-09-18 00:00:00';
    }

    public function saveNotification($payload)
    {
        global $notified;
        $notified[] = $payload;
    }
}

/** A module older than 103.4.65: neither method exists. */
class DeafHost
{
}

/** The in-between shape: old enough to date a payload, too old to store one. */
class DatingHost
{
    public function getGmtDate()
    {
        return '2026-09-18 00:00:00';
    }
}

/**
 * @param  object|null $helper
 * @return array  the reported payload, or the throwable that escaped
 */
function report($helper, $class = 'Mailchimp_Error')
{
    $e = new $class(
        'https://us1.api.mailchimp.com/3.0/lists/abc',
        'GET',
        array(),
        'Forbidden',
        'The API key is not valid',
        null,
        null,
        $helper,
        'https://store.example/'
    );

    try {
        return array('payload' => $e->getFriendlyMessage(), 'threw' => null);
    } catch (Exception $ex) {
        return array('payload' => null, 'threw' => $ex);
    } catch (Throwable $t) {
        // The one this file exists for: on PHP 7+ calling a method the host
        // does not have is an Error, which is not an Exception.
        return array('payload' => null, 'threw' => $t);
    }
}

echo "a host that can listen\n";
$notified = array();
$r = report(new NotifiedHost());
check('the message is built',            $r['threw'] === null && is_array($r['payload']));
check('the host is notified once',       count($notified) === 1);
check('the payload carries the failure', isset($r['payload']['error']['detail']));
check('the payload is dated',            isset($r['payload']['time']));

echo "a host from before 103.4.65\n";
$notified = array();
$r = report(new DeafHost());
check('nothing is raised',               $r['threw'] === null);
check('the message is still built',      is_array($r['payload']));
check('the failure still reaches the caller', isset($r['payload']['error']['detail']));
check('the host is not notified',        count($notified) === 0);
check('and the payload is undated',      !isset($r['payload']['time']));

echo "a host that can be asked the time but not told anything\n";
$notified = array();
$r = report(new DatingHost());
check('nothing is raised',               $r['threw'] === null);
check('the payload is dated',            isset($r['payload']['time']));
check('the host is not notified',        count($notified) === 0);

echo "the http subclass inherits the guard\n";
$notified = array();
$r = report(new DeafHost(), 'Mailchimp_HttpError');
check('nothing is raised',               $r['threw'] === null);
check('the host is not notified',        count($notified) === 0);

echo "no host at all\n";
$notified = array();
$r = report(null);
check('nothing is raised',               $r['threw'] === null);
check('the host is not notified',        count($notified) === 0);

// The two call sites above are reachable from a test. The third and fourth
// live inside Mailchimp::call(), behind a curl round trip to Mailchimp, and a
// test that needed the network to prove a guard would be skipped exactly when
// it mattered. So the rule is asserted over the source instead: every call
// this library makes on the host's helper has to be guarded on the method it
// names, in every file, including ones added later.
echo "every helper call in src is guarded on its own method\n";
$unguarded = array();
foreach (array('src/Mailchimp.php', 'src/Mailchimp/Error.php', 'src/Mailchimp/Telemetry.php') as $file) {
    $source = file_get_contents(dirname(dirname(__DIR__)) . '/' . $file);
    if (!preg_match_all('/\$this->_?helper->([A-Za-z0-9_]+)\(/', $source, $matches)) {
        continue;
    }
    foreach (array_unique($matches[1]) as $method) {
        if (strpos($source, "method_exists(\$this->helper, '" . $method . "')") === false
            && strpos($source, "method_exists(\$this->_helper, '" . $method . "')") === false
        ) {
            $unguarded[] = $file . ' -> ' . $method . '()';
        }
    }
}
check('none found' . ($unguarded ? ': ' . implode(', ', $unguarded) : ''), $unguarded === array());

echo "\n";
if ($failures) {
    printf("%d failed\n", count($failures));
    exit(1);
}
echo "all passed\n";
exit(0);
