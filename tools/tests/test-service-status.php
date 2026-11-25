<?php
/**
 * Tests the signed service status: what is honoured, what is ignored, and
 * what a paused installation does.
 *
 * Every rule here fails quietly when it breaks. A status that stops verifying
 * leaves an installation syncing that was asked to pause; a check that stops
 * rejecting lets anyone who can write to /tmp, or answer for
 * apps.ebizmarts.com, pause a store. So each rule gets a case that would pass
 * under the wrong rule's neighbour.
 *
 * Signs with a key pair generated for the run, never the pinned production
 * keys. The status check is pointed at a php -S server started here.
 *
 * Needs sodium (PHP 7.2+): without it the service status is inert by design,
 * and the only thing left to test is that it stays inert.
 *
 * Usage: php tools/tests/test-service-status.php
 * Exit:  0 all passed, 1 failures.
 */

require dirname(dirname(__DIR__)) . '/src/Mailchimp.php';

$failures = array();

function check($label, $ok)
{
    global $failures;
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $label);
    if (!$ok) {
        $failures[] = $label;
    }
}

if (!Mailchimp_ServiceStatus::supported()) {
    echo "no sodium on this PHP\n";
    $status = new Mailchimp_ServiceStatus(array(), sys_get_temp_dir());
    check('nothing is ever held', $status->get(str_repeat('a', 32)) === null);
    check('nothing is ever applied', $status->apply(array('claim' => 'x', 'sig' => 'y', 'kid' => 'z'), str_repeat('a', 32)) === false);
    exit($failures ? 1 : 0);
}

function b64url($bytes)
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

$pair   = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$other  = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
$keys   = array('test-kid' => b64url(sodium_crypto_sign_publickey($pair)));

$NOW = 1800000000;
$IID = Mailchimp_Telemetry::installId('testkeyone-us1');
$OTHER_IID = Mailchimp_Telemetry::installId('testkeytwo-us2');

function claim($overrides = array())
{
    global $NOW, $IID;
    $claim = array(
        'v' => 1, 'kind' => 'sync_hold', 'install_id' => $IID, 'account_id' => 'acc123', 'dc' => 'us1',
        'code' => 'DEPRECATED', 'reason' => 'product_retired', 'ref' => 'lh7',
        'iat' => $NOW - 60, 'exp' => $NOW + 3600, 'nonce' => 'nonce-' . md5(serialize($overrides)),
    );
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($claim[$k]);
        } else {
            $claim[$k] = $v;
        }
    }
    return $claim;
}

function control($claim, $key = null, $kid = 'test-kid')
{
    global $secret;
    $bytes = is_string($claim) ? $claim : json_encode($claim);
    return array(
        'claim' => b64url($bytes),
        'sig'   => b64url(sodium_crypto_sign_detached($bytes, $key === null ? $secret : $key)),
        'kid'   => $kid,
    );
}

function freshDir()
{
    $dir = sys_get_temp_dir() . '/mc-status-test-' . md5(uniqid('', true));
    mkdir($dir, 0700);
    return $dir;
}

function freshStatus($url = null)
{
    global $keys;
    return new Mailchimp_ServiceStatus($keys, freshDir(), $url);
}

echo "a valid pause\n";
$status = freshStatus();
check('is applied', $status->apply(control(claim()), $IID, $NOW) === true);
$got = $status->get($IID, $NOW);
check('is reported', is_array($got) && $got['code'] === 'DEPRECATED' && $got['reason'] === 'product_retired' && $got['ref'] === 'lh7');
check('pauses calls', is_array($got) && $got['blocking'] === true);
check('does not reach another key', $status->get($OTHER_IID, $NOW) === null);
check('carries its account id back', $status->storedAccountId($IID, $NOW) === 'acc123');
check('expires on its own', $status->get($IID, $NOW + 3600) === null);

echo "the claim shape\n";
foreach (array('BLOCK', 'NEW_TOKEN', 'UPGRADE', 'DEPRECATED') as $code) {
    check("code $code is accepted", freshStatus()->apply(control(claim(array('code' => $code))), $IID, $NOW));
}
foreach (array('fleet_incident', 'account_abuse', 'merchant_request', 'test', 'product_retired') as $reason) {
    check("reason $reason is accepted", freshStatus()->apply(control(claim(array('reason' => $reason))), $IID, $NOW));
}
check('an unknown code is refused',      !freshStatus()->apply(control(claim(array('code' => 'PAUSE'))), $IID, $NOW));
check('a lowercase code is refused',     !freshStatus()->apply(control(claim(array('code' => 'deprecated'))), $IID, $NOW));
check('an unknown reason is refused',    !freshStatus()->apply(control(claim(array('reason' => 'because'))), $IID, $NOW));
check('another version is refused',      !freshStatus()->apply(control(claim(array('v' => 2))), $IID, $NOW));
check('a string version is refused',     !freshStatus()->apply(control(claim(array('v' => '1'))), $IID, $NOW));
check('an unknown kind is refused',      !freshStatus()->apply(control(claim(array('kind' => 'sync_pause'))), $IID, $NOW));
check('a short nonce is refused',        !freshStatus()->apply(control(claim(array('nonce' => 'short'))), $IID, $NOW));
check('a nonce with a dot is refused',   !freshStatus()->apply(control(claim(array('nonce' => 'abcdefghijklmnop.'))), $IID, $NOW));
check('a ref outside [a-z0-9] is refused', !freshStatus()->apply(control(claim(array('ref' => 'LH-7'))), $IID, $NOW));
check('no ref is fine',                  freshStatus()->apply(control(claim(array('ref' => null))), $IID, $NOW));
check('no account id is fine',           freshStatus()->apply(control(claim(array('account_id' => null))), $IID, $NOW));
check('a string iat is refused',         !freshStatus()->apply(control(claim(array('iat' => (string)($NOW - 60)))), $IID, $NOW));
check('a claim that is not JSON is refused', !freshStatus()->apply(control('not json'), $IID, $NOW));

echo "the binding\n";
check('another install id is refused', !freshStatus()->apply(control(claim(array('install_id' => $OTHER_IID))), $IID, $NOW));
check('an uppercase install id is refused', !freshStatus()->apply(control(claim(array('install_id' => strtoupper($IID)))), $IID, $NOW));
check('the account id alone binds nothing', !freshStatus()->apply(control(claim(array('install_id' => $OTHER_IID))), $IID, $NOW));

echo "the signature\n";
check('another key is refused', !freshStatus()->apply(control(claim(), $other), $IID, $NOW));
check('an unpinned kid is refused', !freshStatus()->apply(control(claim(), null, 'ctl-other'), $IID, $NOW));
$tampered = control(claim());
$tampered['claim'] = b64url(json_encode(claim(array('code' => 'BLOCK'))));
check('a claim changed after signing is refused', !freshStatus()->apply($tampered, $IID, $NOW));
$padded = control(claim());
$padded['sig'] = strtr($padded['sig'], '-_', '+/');
check('standard base64 instead of base64url is refused', !freshStatus()->apply($padded, $IID, $NOW) || strpos($padded['sig'], '+') === false && strpos($padded['sig'], '/') === false);
check('missing fields are refused', !freshStatus()->apply(array('claim' => 'x'), $IID, $NOW));
check('a non-array control is refused', !freshStatus()->apply('control', $IID, $NOW));
check('the production keys do not accept the test key', !(new Mailchimp_ServiceStatus(null, freshDir()))->apply(control(claim(), null, 'ctl-b10b31dea7a2'), $IID, $NOW));

echo "time\n";
check('expired is refused',            !freshStatus()->apply(control(claim(array('exp' => $NOW))), $IID, $NOW));
check('exp before iat is refused',     !freshStatus()->apply(control(claim(array('iat' => $NOW + 10, 'exp' => $NOW + 5))), $IID, $NOW));
check('24 h exactly is accepted',      freshStatus()->apply(control(claim(array('iat' => $NOW - 10, 'exp' => $NOW - 10 + 86400))), $IID, $NOW));
check('over 24 h is refused',          !freshStatus()->apply(control(claim(array('iat' => $NOW - 10, 'exp' => $NOW - 9 + 86400))), $IID, $NOW));
check('iat 5 min ahead is accepted',   freshStatus()->apply(control(claim(array('iat' => $NOW + 300, 'exp' => $NOW + 3600))), $IID, $NOW));
check('iat further ahead is refused',  !freshStatus()->apply(control(claim(array('iat' => $NOW + 301, 'exp' => $NOW + 3600))), $IID, $NOW));

echo "blocks_from\n";
$status = freshStatus();
$status->apply(control(claim(array('blocks_from' => $NOW + 600))), $IID, $NOW);
$got = $status->get($IID, $NOW);
check('before it, the pause is reported', is_array($got) && $got['blocks_from'] === $NOW + 600);
check('before it, nothing is paused', is_array($got) && $got['blocking'] === false);
$got = $status->get($IID, $NOW + 600);
check('from it, calls are paused', is_array($got) && $got['blocking'] === true);
check('a zero blocks_from is refused', !freshStatus()->apply(control(claim(array('blocks_from' => 0))), $IID, $NOW));

echo "nonces and resends\n";
$status = freshStatus();
$c = control(claim(array('nonce' => 'same-nonce-0123456789')));
check('first delivery applies', $status->apply($c, $IID, $NOW));
check('an identical resend applies', $status->apply($c, $IID, $NOW + 30));
check('a different claim reusing the nonce is refused', !$status->apply(control(claim(array('nonce' => 'same-nonce-0123456789', 'code' => 'BLOCK'))), $IID, $NOW + 60));
$got = $status->get($IID, $NOW + 60);
check('and the first pause stands', is_array($got) && $got['code'] === 'DEPRECATED');

echo "resume\n";
$status = freshStatus();
$status->apply(control(claim(array('code' => 'BLOCK', 'ref' => 'lh1'))), $IID, $NOW);
$resume = claim(array('kind' => 'sync_release', 'code' => 'UPGRADE', 'ref' => 'lh99', 'iat' => $NOW - 3000));
check('a resume for another pause, with an older iat, applies', $status->apply(control($resume), $IID, $NOW));
check('and clears the pause', $status->get($IID, $NOW) === null);
$status = freshStatus();
$status->apply(control(claim()), $IID, $NOW);
$status->apply(control(claim(array('kind' => 'sync_release', 'install_id' => $OTHER_IID))), $IID, $NOW);
check('the pause stands after another key\'s resume', $status->get($IID, $NOW) !== null);
check('a pause arriving after a resume applies', $status->apply(control(claim(array('code' => 'UPGRADE'))), $IID, $NOW));

echo "a resend after its resume\n";
$status = freshStatus();
$paused = control(claim(array('nonce' => 'resend-nonce-0123456789')));
$status->apply($paused, $IID, $NOW);
$status->apply(control(claim(array('kind' => 'sync_release', 'nonce' => 'resume-nonce-0123456789'))), $IID, $NOW + 10);
check('the exact pause, resent after its resume, is refused', $status->apply($paused, $IID, $NOW + 20) === false);
check('and nothing is paused', $status->get($IID, $NOW + 20) === null);
$dir = freshDir();
$x = new Mailchimp_ServiceStatus($keys, $dir);
$x->apply($paused, $IID, $NOW);
$x->apply(control(claim(array('kind' => 'sync_release', 'nonce' => 'resume-nonce-0123456789'))), $IID, $NOW + 10);
$y = new Mailchimp_ServiceStatus($keys, $dir);
check('another process refuses it too', $y->apply($paused, $IID, $NOW + 20) === false && $y->get($IID, $NOW + 20) === null);
$status = freshStatus();
$windowN  = control(claim(array('nonce' => 'window-n-0123456789ab')));
$windowN1 = control(claim(array('nonce' => 'window-n1-0123456789a')));
$status->apply($windowN, $IID, $NOW);
$status->apply($windowN1, $IID, $NOW + 60);
$status->apply(control(claim(array('kind' => 'sync_release', 'nonce' => 'resume-n1-0123456789'))), $IID, $NOW + 120);
check('after a resume, an earlier signing of the pause is refused too', $status->apply($windowN, $IID, $NOW + 180) === false);
check('and the later one', $status->apply($windowN1, $IID, $NOW + 180) === false && $status->get($IID, $NOW + 180) === null);
$status = freshStatus();
$status->apply(control(claim(array('kind' => 'sync_release', 'ref' => 'lh7', 'iat' => $NOW, 'nonce' => 'resume-empty-0123456789'))), $IID, $NOW);
check('with nothing stored, a pause signed before the resume for its ref is refused',
    $status->apply(control(claim(array('ref' => 'lh7', 'iat' => $NOW - 60, 'nonce' => 'stale-pause-0123456789'))), $IID, $NOW + 10) === false
    && $status->get($IID, $NOW + 10) === null);
check('a later signing of that ref still applies',
    $status->apply(control(claim(array('ref' => 'lh7', 'iat' => $NOW + 60, 'nonce' => 'later-pause-0123456789'))), $IID, $NOW + 70) === true);
check('and a pause with another ref, older iat and all, applies',
    freshStatus()->apply(control(claim(array('ref' => 'lh8', 'iat' => $NOW - 60, 'nonce' => 'other-ref-0123456789a'))), $IID, $NOW + 10) === true);
$x = new Mailchimp_ServiceStatus($keys, $dir2 = freshDir());
$x->apply(control(claim(array('kind' => 'sync_release', 'ref' => 'lh7', 'iat' => $NOW, 'nonce' => 'resume-empty-bbbbbbbbbb'))), $IID, $NOW);
$y = new Mailchimp_ServiceStatus($keys, $dir2);
check('another process refuses the stale pause too',
    $y->apply(control(claim(array('ref' => 'lh7', 'iat' => $NOW - 60, 'nonce' => 'stale-pause-bbbbbbbbbb'))), $IID, $NOW + 10) === false);
$status = freshStatus();
$status->apply(control(claim(array('nonce' => 'resend-nonce-0123456789'))), $IID, $NOW);
check('a new pause, older iat and all, still applies', $status->apply(control(claim(array('nonce' => 'other-nonce-0123456789', 'iat' => $NOW - 600))), $IID, $NOW + 30));

echo "two processes\n";
$dir = freshDir();
$p1 = new Mailchimp_ServiceStatus($keys, $dir);
$p2 = new Mailchimp_ServiceStatus($keys, $dir);
check('the first reads nothing', $p1->get($IID, $NOW) === null);
$p2->apply(control(claim(array('nonce' => 'proc-two-0123456789ab'))), $IID, $NOW + 1);
$p1->apply(control(claim(array('kind' => 'sync_release', 'install_id' => $IID, 'nonce' => 'proc-one-0123456789ab', 'code' => 'x'))), $IID, $NOW + 2);
$p3 = new Mailchimp_ServiceStatus($keys, $dir);
check('a save from a stale copy does not resurrect what the other undid', $p3->get($IID, $NOW + 2) === null);
$dir = freshDir();
$p1 = new Mailchimp_ServiceStatus($keys, $dir);
$p2 = new Mailchimp_ServiceStatus($keys, $dir);
$p1->get($IID, $NOW);
$p2->apply(control(claim(array('nonce' => 'proc-two-bbbbbbbbbbbb'))), $IID, $NOW + 1);
$p1->apply(control(claim(array('install_id' => $OTHER_IID, 'nonce' => 'proc-one-bbbbbbbbbbbb'))), $OTHER_IID, $NOW + 2);
$p1->apply(control(claim(array('nonce' => 'proc-one-cccccccccccc', 'kind' => 'sync_release', 'code' => 'x'))), $IID, $NOW + 3);
$p4 = new Mailchimp_ServiceStatus($keys, $dir);
check('a resume saved by a process that cached nothing still sees the pause it lifts',
    $p4->get($IID, $NOW + 3) === null && $p4->apply(control(claim(array('nonce' => 'proc-two-bbbbbbbbbbbb'))), $IID, $NOW + 4) === false);

echo "open_basedir\n";
$jail   = freshDir();
$script = $jail . '/basedir.php';
file_put_contents($script, '<?php
// As Magento\\Framework\\App\\ErrorHandler does: a warning the code did not
// silence becomes an exception.
set_error_handler(function ($no, $str) {
    if (($no & error_reporting()) === 0) { return false; }
    throw new ErrorException($str, 0, $no);
});
require ' . var_export(dirname(dirname(__DIR__)) . '/src/Mailchimp.php', true) . ';
$keys = ' . var_export($keys, true) . ';
$c = ' . var_export(control(claim()), true) . ';
$s = new Mailchimp_ServiceStatus($keys, "/tmp");
$ok = $s->apply($c, ' . var_export($IID, true) . ', ' . $NOW . ');
$got = $s->get(' . var_export($IID, true) . ', ' . $NOW . ');
echo ($ok && is_array($got)) ? "applied" : "lost";
');
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' -d open_basedir=' . escapeshellarg($jail . PATH_SEPARATOR . dirname(dirname(__DIR__)))
    . ' -d display_errors=1 ' . escapeshellarg($script) . ' 2>&1');
check('with the temp directory outside open_basedir, a pause still applies to the process that received it', trim((string)$out) === 'applied');

echo "the report\n";
check('nothing stored reports none', Mailchimp_ServiceStatus::report(null) === array('svc_state' => 'none', 'svc_ref' => null, 'svc_code' => null, 'svc_since' => null));
$status = freshStatus();
$status->apply(control(claim(array('blocks_from' => $NOW + 600))), $IID, $NOW);
$r = Mailchimp_ServiceStatus::report($status->get($IID, $NOW));
check('before blocks_from it reports a warning', $r === array('svc_state' => 'warning', 'svc_ref' => 'lh7', 'svc_code' => 'DEPRECATED', 'svc_since' => $NOW));
$r = Mailchimp_ServiceStatus::report($status->get($IID, $NOW + 600));
check('from it, held', $r['svc_state'] === 'held');
$status->apply(control(claim(array('blocks_from' => $NOW + 600))), $IID, $NOW + 900);
check('an identical resend keeps the time it was first stored', $status->get($IID, $NOW + 900)['since'] === $NOW);

echo "dates and domains\n";
check('a Mailchimp date becomes UTC Z', Mailchimp_ServiceStatus::isoUtc('2015-08-12T16:14:42+00:00') === '2015-08-12T16:14:42Z');
check('an offset is converted to UTC', Mailchimp_ServiceStatus::isoUtc('2015-08-12T16:14:42-03:00') === '2015-08-12T19:14:42Z');
check('already in the form, kept', Mailchimp_ServiceStatus::isoUtc('2010-01-01T23:59:59Z') === '2010-01-01T23:59:59Z');
check('an empty first_payment is null', Mailchimp_ServiceStatus::isoUtc('') === null);
check('a date without an offset is null, not the server time zone', Mailchimp_ServiceStatus::isoUtc('2015-08-12T16:14:42') === null
    && Mailchimp_ServiceStatus::isoUtc('2015-08-12 16:14:42') === null && Mailchimp_ServiceStatus::isoUtc('2015-08-12') === null);
check('an offset without a colon is read', Mailchimp_ServiceStatus::isoUtc('2015-08-12T16:14:42+0000') === '2015-08-12T16:14:42Z');
check('fractional seconds are dropped', Mailchimp_ServiceStatus::isoUtc('2015-08-12T16:14:42.123+00:00') === '2015-08-12T16:14:42Z');
check('garbage is null', Mailchimp_ServiceStatus::isoUtc('not a date') === null && Mailchimp_ServiceStatus::isoUtc(12) === null);
foreach (array(
    'https://www.Shop.example.com/'          => 'shop.example.com',
    'https://shop.example.com/EN/?a=1#x'     => 'shop.example.com/en',
    'http://shop.example.com:8080/store/'    => 'shop.example.com:8080/store',
    'https://shop.example.com:443'           => 'shop.example.com',
    'shop.example.com/es'                    => 'shop.example.com/es',
    ''                                       => '',
) as $in => $want) {
    check("domain key of '$in'", Mailchimp_Telemetry::domainKey($in) === $want);
}
check('no URL, no domain key', Mailchimp_Telemetry::domainKey(null) === '');

echo "what telemetry adds to the check\n";
putenv('MC_TELEMETRY');
$on = new Mailchimp_Telemetry();
$on->switchKey('testkeyone-us1', 'us1');
$on->setStoreUrl('https://www.shop.example.com/');
$on->observeRoot(array('account_id' => 'acc9', 'first_payment' => '2015-08-12T16:14:42+00:00', 'member_since' => '2014-01-02T03:04:05+00:00'));
$id = $on->identity($IID);
check('with reports on: the domain', $id['domain'] === 'shop.example.com');
check('with reports on: the account dates', $id['first_payment'] === '2015-08-12T16:14:42Z' && $id['member_since'] === '2014-01-02T03:04:05Z');
putenv('MC_TELEMETRY=0');
$off = new Mailchimp_Telemetry();
$off->switchKey('testkeyone-us1', 'us1');
$off->setStoreUrl('https://www.shop.example.com/');
$off->observeRoot(array('account_id' => 'acc9', 'first_payment' => '2015-08-12T16:14:42+00:00'));
$id = $off->identity($IID);
check('with MC_TELEMETRY=0: no domain, no dates, not even as null',
    !array_key_exists('domain', $id) && !array_key_exists('first_payment', $id) && !array_key_exists('member_since', $id));
check('with MC_TELEMETRY=0: still the versions', isset($id['lib_version']) && array_key_exists('module_version', $id));
putenv('MC_TELEMETRY');

echo "storage\n";
$dir = freshDir();
$a = new Mailchimp_ServiceStatus($keys, $dir);
$a->apply(control(claim()), $IID, $NOW);
$b = new Mailchimp_ServiceStatus($keys, $dir);
check('another process sees it', is_array($b->get($IID, $NOW)));
$files = glob($dir . '/mc4magento-status-*-' . $IID . '.json');
check('in one file named for the key', count($files) === 1);
check('readable only by its owner', count($files) === 1 && (fileperms($files[0]) & 0077) === 0);
check('no temporary file left behind', count(glob($dir . '/*.tmp')) === 0);
$state = json_decode(file_get_contents($files[0]), true);
$state['control'] = control(claim(), $other);
file_put_contents($files[0], json_encode($state));
$c2 = new Mailchimp_ServiceStatus($keys, $dir);
check('a file written with another key is ignored', $c2->get($IID, $NOW) === null);
$state['control'] = control(claim(array('install_id' => $OTHER_IID)));
file_put_contents($files[0], json_encode($state));
$c3 = new Mailchimp_ServiceStatus($keys, $dir);
check('a file with another key\'s claim is ignored', $c3->get($IID, $NOW) === null);
file_put_contents($files[0], 'garbage');
$c4 = new Mailchimp_ServiceStatus($keys, $dir);
check('a corrupt file is ignored', $c4->get($IID, $NOW) === null);
$nowhere = new Mailchimp_ServiceStatus($keys, '/nonexistent/' . md5(uniqid('', true)));
check('with nowhere to write, the pause still applies to this process', $nowhere->apply(control(claim()), $IID, $NOW) && is_array($nowhere->get($IID, $NOW)));

echo "the message\n";
$msg = Mailchimp_ServiceStatus::message(array('code' => 'BLOCK', 'ref' => 'lh7'));
check('the exact text', $msg === 'Mailchimp sync is paused by the service. Your data and settings are kept. Email mailchimp@help.ebizmarts.com. Reference: lh7.');
$msg = Mailchimp_ServiceStatus::message(array('code' => 'DEPRECATED', 'ref' => 'lh7'));
check('DEPRECATED replaces the kept sentence', $msg === 'Mailchimp sync is paused by the service. This version is no longer supported. Email mailchimp@help.ebizmarts.com. Reference: lh7.');
$msg = Mailchimp_ServiceStatus::message(array('code' => 'UPGRADE'));
check('UPGRADE asks for an update, no reference without a ref', $msg === 'Mailchimp sync is paused by the service. This version needs to be updated. Email mailchimp@help.ebizmarts.com.');
$msg = Mailchimp_ServiceStatus::message(array('code' => 'NEW_TOKEN', 'ref' => 'lh7'));
check('NEW_TOKEN asks for a new key', strpos($msg, 'needs a new API key.') !== false && strpos($msg, 'kept') === false);
foreach (array('BLOCK', 'DEPRECATED', 'UPGRADE', 'NEW_TOKEN') as $code) {
    $msg = Mailchimp_ServiceStatus::message(array('code' => $code, 'ref' => 'lh7'));
    check("$code: paused by the service, the code not shown", strpos($msg, 'paused by the service.') !== false && strpos($msg, $code) === false);
}

echo "a held Mailchimp\n";
class PausedMailchimp extends Mailchimp
{
    public function useServiceStatus($status)
    {
        $this->_serviceStatus = $status;
        $this->_telemetry->setServiceStatus($status);
    }
}
class CountingHelper
{
    public $saved = 0;
    public function saveNotification($data) { $this->saved++; }
    public function getGmtDate() { return '2026-01-01 00:00:00'; }
}
$dir    = freshDir();
$helper = new CountingHelper();
$mc = new PausedMailchimp();
$mc->useServiceStatus(new Mailchimp_ServiceStatus($keys, $dir, 'http://127.0.0.1:1/never'));
$mc->setHelper($helper);
$mc->setApiKey('testkeyone-us1');
check('no pause, nothing reported', $mc->getServiceStatus() === null);
$live = claim(array('iat' => time() - 60, 'exp' => time() + 3600));
check('applyControl takes the key in use', $mc->applyControl(control($live)) === true);
check('getServiceStatus reports it', is_array($mc->getServiceStatus()) && $mc->getServiceStatus()['code'] === 'DEPRECATED');
$thrown = null;
$started = microtime(true);
try {
    $mc->root->info();
} catch (Mailchimp_Error $e) {
    $thrown = $e;
}
check('an API call raises Mailchimp_Error', $thrown !== null && get_class($thrown) === 'Mailchimp_Error');
check('before going out', microtime(true) - $started < 0.05);
check('with the merchant-facing message', $thrown !== null && strpos($thrown->getMessage(), 'paused by the service. This version is no longer supported.') !== false);
if ($thrown) {
    $thrown->getFriendlyMessage();
}
check('and nothing for the module to store', $helper->saved === 0);
$mc->setApiKey('testkeytwo-us2');
check('another key in the same process is not held', $mc->getServiceStatus() === null);
$mc->setApiKey('testkeyone-us1');
check('switching back finds the pause', is_array($mc->getServiceStatus()));
$mc->applyControl(control(claim(array('kind' => 'sync_release', 'iat' => time() - 30, 'exp' => time() + 3600))));
check('a resume lifts it', $mc->getServiceStatus() === null);
unset($mc);

echo "the hourly check\n";
$docroot = freshDir();
$log     = $docroot . '/requests.log';
$reply   = $docroot . '/reply.json';
file_put_contents($docroot . '/pause.php', '<?php
file_put_contents(' . var_export($log, true) . ', file_get_contents("php://input") . "\n", FILE_APPEND);
$r = json_decode(file_get_contents(' . var_export($reply, true) . '), true);
http_response_code($r["status"]);
if (isset($r["body"])) { header("Content-Type: application/json"); echo $r["body"]; }
');
$port = 18000 + mt_rand(0, 999);
$server = proc_open(
    escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($docroot),
    array(array('pipe', 'r'), array('file', '/dev/null', 'w'), array('file', '/dev/null', 'w')),
    $pipes
);
usleep(300000);
$url = 'http://127.0.0.1:' . $port . '/pause.php';
function requests()
{
    global $log;
    return is_file($log) ? array_values(array_filter(explode("\n", file_get_contents($log)))) : array();
}
function answer($status, $body = null)
{
    global $reply;
    file_put_contents($reply, json_encode(array('status' => $status, 'body' => $body)));
}

$t = time();
$identity = array('install_id' => $IID, 'account_id' => null, 'dc' => 'us1', 'module_version' => '103.4.88', 'lib_version' => '3.0.54');
$status = new Mailchimp_ServiceStatus($keys, freshDir(), $url);
answer(200, json_encode(array('control' => control(claim(array('iat' => $t - 60, 'exp' => $t + 7200))))));
$status->check($identity, true, $t);
$sent = requests();
check('asks once', count($sent) === 1);
$body = isset($sent[0]) ? json_decode($sent[0], true) : array();
check('sends the identity and the report, nothing else', $body === array(
    'install_id' => $IID, 'dc' => 'us1', 'module_version' => '103.4.88', 'lib_version' => '3.0.54',
    'svc_state' => 'none', 'svc_ref' => null, 'svc_code' => null, 'svc_since' => null,
));
check('applies the control in the reply', is_array($status->get($IID, $t)));
$status->check($identity, true, $t + 1800);
check('not again within the hour', count(requests()) === 1);
answer(204);
$status->check($identity, true, $t + 3600);
$sent = requests();
check('again after the hour', count($sent) === 2);
$body = isset($sent[1]) ? json_decode($sent[1], true) : array();
check('with the account id from the stored claim', isset($body['account_id']) && $body['account_id'] === 'acc123');
check('and reports the pause it pauses', isset($body['svc_state']) && $body['svc_state'] === 'held'
    && $body['svc_ref'] === 'lh7' && $body['svc_code'] === 'DEPRECATED' && $body['svc_since'] === $t);
check('a 204 keeps the stored pause', is_array($status->get($IID, $t + 3600)));
answer(503);
$status->check($identity, true, $t + 7200);
check('a 503 changes nothing', count(requests()) === 3);
answer(200, 'not json');
$h2 = new Mailchimp_ServiceStatus($keys, freshDir(), $url);
$h2->check($identity, true, $t);
check('a reply that is not JSON is ignored', $h2->get($IID, $t) === null);
answer(200, json_encode(array('control' => control(claim(array('iat' => $t - 60, 'exp' => $t + 3600)), $other))));
$h3 = new Mailchimp_ServiceStatus($keys, freshDir(), $url);
$h3->check($identity, true, $t);
check('a reply signed with another key is ignored', $h3->get($IID, $t) === null);
$before = count(requests());
$h4 = new Mailchimp_ServiceStatus($keys, '/nonexistent/' . md5(uniqid('', true)), $url);
$h4->check($identity, true, $t);
check('with nowhere to record the hour, it does not ask', count(requests()) === $before);
$h5 = new Mailchimp_ServiceStatus($keys, freshDir(), 'http://127.0.0.1:1/closed');
$started = microtime(true);
$h5->check($identity, false, $t);
check('an unreachable service costs a web request little', microtime(true) - $started < 0.5);

answer(204);
$dir = freshDir();
$h6 = new Mailchimp_ServiceStatus($keys, $dir, $url);
$before = count(requests());
$h6->check($identity, true, $t);
$h6->check(array_merge($identity, array('module_version' => '103.4.89')), true, $t + 60);
check('a new extension version does not check within 5 minutes', count(requests()) === $before + 1);
$h6->check(array_merge($identity, array('module_version' => '103.4.89')), true, $t + 300);
check('it checks again after 5 minutes', count(requests()) === $before + 2);
$h6->check(array_merge($identity, array('module_version' => '103.4.89')), true, $t + 400);
check('and then not until the hour', count(requests()) === $before + 2);
$h6->check(array_merge($identity, array('module_version' => '103.4.89', 'lib_version' => '3.0.55')), true, $t + 600);
check('a new library version too', count(requests()) === $before + 3);
$before = count(requests());
$shared = freshDir();
$stagingIdentity = array_merge($identity, array('module_version' => '103.4.90'));
for ($i = 0; $i < 10; $i++) {
    $box = new Mailchimp_ServiceStatus($keys, $shared, $url);
    $box->check(($i % 2) ? $stagingIdentity : $identity, true, $t + 30 * $i);
}
check('two versions sharing a key and a temp directory ask once in 5 minutes, not on every request', count(requests()) === $before + 1);
$files = glob($dir . '/mc4magento-status-*.json');
$state = json_decode(file_get_contents($files[0]), true);
$state['checked_at'] = $t + 10 * 365 * 86400;
file_put_contents($files[0], json_encode($state));
$before = count(requests());
$h7 = new Mailchimp_ServiceStatus($keys, $dir, $url);
$h7->check(array_merge($identity, array('module_version' => '103.4.89', 'lib_version' => '3.0.55')), true, $t + 900);
check('a checked_at far ahead of the clock does not stop the check', count(requests()) === $before + 1);

$before = count(requests());
$h8 = new Mailchimp_ServiceStatus($keys, freshDir(), $url);
$h8->check(array_merge($identity, array('domain' => 'shop.example.com', 'first_payment' => '2015-08-12T16:14:42+00:00', 'member_since' => null)), true, $t);
$sent = requests();
$body = isset($sent[$before]) ? json_decode($sent[$before], true) : array();
check('with reports on, sends the domain and the dates', isset($body['domain']) && $body['domain'] === 'shop.example.com'
    && $body['first_payment'] === '2015-08-12T16:14:42Z' && array_key_exists('member_since', $body) && $body['member_since'] === null);
check('and stays under the 1 KB the service accepts', isset($sent[$before]) && strlen($sent[$before]) < 1024);

proc_terminate($server);

echo "\n";
if ($failures) {
    printf("%d failed\n", count($failures));
    exit(1);
}
echo "all passed\n";
exit(0);
