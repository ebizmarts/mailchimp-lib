<?php
/**
 * mailchimp-lib Magento Component
 *
 * @category Ebizmarts
 * @package mailchimp-lib
 * @author Ebizmarts Team <info@ebizmarts.com>
 * @copyright Ebizmarts (http://ebizmarts.com)
 * @license http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * The service status returned by the QoS service, and honoured here.
 *
 * WHAT IT IS. The library reports quality of service (Mailchimp_Telemetry).
 * This is the other half: the QoS service can answer with a service status
 * for this installation, and the library honours it. A status is a claim
 * signed with Ed25519 and checked here against the keys pinned below. While a
 * status pauses this installation, every call fails before it leaves, with a
 * Mailchimp_Error that says why and who to contact. Nothing is deleted: not
 * data, not the key, not the configuration. A signed resume, or the claim
 * expiring, lifts it.
 *
 * HOW IT ARRIVES. Two ways, both answers from the QoS service at
 * apps.ebizmarts.com:
 *
 *   - the reply to a telemetry report (Mailchimp_Telemetry::post()), and
 *   - an hourly status check from this class (see check() for what it sends).
 *
 * The hourly check exists because a paused installation makes no API calls,
 * so it sends no reports, and a claim lives at most 24 hours. It runs whether
 * or not telemetry is switched off: MC_TELEMETRY=0 stops the reports and the
 * check's fields that only describe the store, not the check itself. It is
 * also what reaches a cron process, which reports only when its sample window
 * comes up.
 *
 * WHAT IT IS BOUND TO. The installation id -- the hash of the API key the
 * telemetry already uses. A claim for any other id is ignored, so one key's
 * status never stops another key in the same process.
 *
 * WHERE IT IS KEPT. One small file per key and per system user in the system
 * temp directory: this library has no database. When the file cannot be
 * written the status still applies for the rest of the process, and the
 * hourly check is skipped rather than run on every request. When the file
 * vanishes, the next reply restores it. What is read back is verified again,
 * and a file owned by another user is not read at all.
 *
 * EVERY FAILURE HERE IS SILENT, except the one it exists for. A missing sodium
 * extension, an unreadable temp directory, no network, a reply that is not
 * JSON, a claim that does not verify: all of them leave the installation
 * running as if no status had been sent. The only error this class raises is
 * the one for an active pause, from assertOpen().
 */
class Mailchimp_ServiceStatus
{
    /** The path is fixed by the QoS service. */
    const CHECK_URL      = 'https://apps.ebizmarts.com/mc4magento/v1/lib/hold';
    const CHECK_INTERVAL = 3600;

    /** The claim version this library understands. */
    const CLAIM_VERSION = 1;

    /** Longest allowed claim lifetime, exp - iat. */
    const MAX_LIFETIME = 86400;

    /** Allowed clock skew for a claim issued, or a check stored, "in the future". */
    const MAX_FUTURE_IAT = 300;

    /** How long a process trusts what it read from the file before reading again. */
    const REREAD_SECONDS = 60;

    /** Nonces remembered per installation, so a different claim cannot reuse one. */
    const MAX_NONCES = 32;

    /** Keys checked in one process: one Magento installation has few accounts. */
    const MAX_CHECKS = 4;

    /** Largest status-check reply read. A control is well under 1 KB. */
    const MAX_REPLY_BYTES = 4096;

    /** Longest store domain sent on the check. */
    const MAX_DOMAIN = 253;

    const CONNECT_WEB_MS = 150;
    const TOTAL_WEB_MS   = 250;
    const CONNECT_CLI_MS = 2000;
    const TOTAL_CLI_MS   = 2500;

    /** The claim kinds, as the QoS service sends them. */
    const KIND_PAUSE  = 'sync_hold';
    const KIND_RESUME = 'sync_release';

    /** The svc_state values of the service status report. */
    const STATE_PAUSED  = 'held';
    const STATE_WARNING = 'warning';
    const STATE_NONE    = 'none';

    const CONTACT = 'mailchimp@help.ebizmarts.com';

    /**
     * Pinned Ed25519 public keys, kid => base64url. The current key and its
     * standby. Rotation ships the next key here first, then the service
     * switches kid, then a later release drops the old one.
     *
     * @var array
     */
    private static $PINNED_KEYS = array(
        'ctl-b10b31dea7a2' => 'QCXwX8dHLlF7MNGEC2Bqjz5NCccwZGq7mx2vbeUtYt8',
        'ctl-72669de38076' => 'jErTgaKeNsnPAJ5n4CWZD_qKsWDpVBx6MZZwnvjM-ho',
    );

    /** @var array the service status codes, as the QoS service sends them */
    private static $CODES = array('BLOCK', 'NEW_TOKEN', 'UPGRADE', 'DEPRECATED');

    /** @var array the closed set of reason codes */
    private static $REASONS = array(
        'fleet_incident', 'account_abuse', 'merchant_request', 'test', 'product_retired',
    );

    /** @var array kid => base64url key */
    private $_keys;

    /** @var string|null directory the state files live in, null for none */
    private $_dir;

    /** @var array install_id => array('at' => int, 'state' => array) */
    private $_cache = array();

    /** @var array sha256 of a control => its claim, or false: signatures already checked */
    private $_verified = array();

    /** @var string */
    private $_checkUrl;

    /**
     * The arguments exist for the tests; the library passes none.
     *
     * @param array|null  $keys     kid => base64url public key; null for the pinned keys
     * @param string|null $dir      state directory; null for the system temp directory
     * @param string|null $checkUrl status-check URL; null for CHECK_URL
     */
    public function __construct($keys = null, $dir = null, $checkUrl = null)
    {
        $this->_keys     = is_array($keys) ? $keys : self::$PINNED_KEYS;
        $this->_checkUrl = is_string($checkUrl) && $checkUrl !== '' ? $checkUrl : self::CHECK_URL;

        if ($dir === null && function_exists('sys_get_temp_dir')) {
            $dir = @sys_get_temp_dir();
        }
        $this->_dir = (is_string($dir) && $dir !== '') ? rtrim($dir, '/\\') : null;
    }

    /**
     * Whether this PHP can verify a claim at all: the sodium extension (PHP
     * 7.2+) or the sodium_compat polyfill. Without either, every control is
     * ignored and nothing is checked.
     *
     * @return bool
     */
    public static function supported()
    {
        return function_exists('sodium_crypto_sign_verify_detached');
    }

    /**
     * Raise when the service status pauses this installation's calls.
     *
     * The error carries no request parameters and no helper on purpose: the
     * call never left, so there is nothing to record, and a module that stores
     * what getFriendlyMessage() hands it would otherwise store one row per
     * paused call.
     *
     * @param  string $installId
     * @param  string $url
     * @param  string $method
     * @return void
     * @throws Mailchimp_Error
     */
    public function assertOpen($installId, $url, $method)
    {
        $status = $this->get($installId);
        if ($status === null || !$status['blocking']) {
            return;
        }

        throw new Mailchimp_Error($url, $method, '', 'Mailchimp sync paused', self::message($status));
    }

    /**
     * The service status stored for this installation, or null for none.
     *
     * Returned while it only warns, too (blocks_from still ahead): the host
     * shows the date, and `blocking` says whether calls are paused yet.
     *
     * @param  string   $installId
     * @param  int|null $now
     * @return array|null code, reason, ref, iat, exp, blocks_from, blocking, since
     */
    public function get($installId, $now = null)
    {
        if (!self::supported() || !$installId) {
            return null;
        }
        $now = ($now === null) ? time() : (int)$now;

        try {
            $state = $this->state($installId, $now);
            if (!isset($state['control']) || !is_array($state['control'])) {
                return null;
            }
            $claim = $this->verify($state['control'], $installId, $now);
            if ($claim === null || $claim['kind'] !== self::KIND_PAUSE) {
                return null;
            }
        } catch (Exception $e) {
            return null;
        } catch (Throwable $t) {
            return null;
        }

        $blocksFrom = isset($claim['blocks_from']) ? (int)$claim['blocks_from'] : null;

        return array(
            'code'        => $claim['code'],
            'reason'      => $claim['reason'],
            'ref'         => isset($claim['ref']) ? $claim['ref'] : null,
            'iat'         => $claim['iat'],
            'exp'         => $claim['exp'],
            'blocks_from' => $blocksFrom,
            'blocking'    => ($blocksFrom === null || $now >= $blocksFrom),
            'since'       => isset($state['since']) ? (int)$state['since'] : $claim['iat'],
        );
    }

    /**
     * The service status report the QoS service asks every client for: what
     * this installation has stored right now, as four flat fields.
     *
     * @param  array|null $status as returned by get()
     * @return array svc_state, svc_ref, svc_code, svc_since
     */
    public static function report($status)
    {
        if (!is_array($status)) {
            return array(
                'svc_state' => self::STATE_NONE,
                'svc_ref'   => null,
                'svc_code'  => null,
                'svc_since' => null,
            );
        }

        return array(
            'svc_state' => !empty($status['blocking']) ? self::STATE_PAUSED : self::STATE_WARNING,
            'svc_ref'   => isset($status['ref']) ? (string)$status['ref'] : null,
            'svc_code'  => isset($status['code']) ? (string)$status['code'] : null,
            'svc_since' => isset($status['since']) ? (int)$status['since'] : null,
        );
    }

    /**
     * The account id the stored claim carries, or null.
     *
     * A paused installation makes no calls, so it may never learn its account
     * id in this process. The service only answers a check with a resume when
     * the check carries every field its status matches on, so the hourly check
     * sends this one back rather than leaving the field empty.
     *
     * @param  string   $installId
     * @param  int|null $now
     * @return string|null
     */
    public function storedAccountId($installId, $now = null)
    {
        if (!self::supported() || !$installId) {
            return null;
        }
        try {
            $now   = ($now === null) ? time() : (int)$now;
            $state = $this->state($installId, $now);
            if (!isset($state['control']) || !is_array($state['control'])) {
                return null;
            }
            $claim = $this->verify($state['control'], $installId, $now);
        } catch (Exception $e) {
            return null;
        } catch (Throwable $t) {
            return null;
        }

        return ($claim !== null && isset($claim['account_id'])) ? (string)$claim['account_id'] : null;
    }

    /**
     * Apply one control from the QoS service to this installation.
     *
     * A pause is stored; a resume clears whatever pause is stored, whatever
     * its code or ref, and that exact pause is refused from then on. Anything
     * that does not verify is ignored.
     *
     * @param  mixed    $control array claim, sig, kid
     * @param  string   $installId
     * @param  int|null $now
     * @return bool whether it was applied
     */
    public function apply($control, $installId, $now = null)
    {
        if (!self::supported() || !$installId || !is_array($control)) {
            return false;
        }
        $now = ($now === null) ? time() : (int)$now;

        try {
            $claim = $this->verify($control, $installId, $now);
            if ($claim === null) {
                return false;
            }

            // Read again, whatever is cached: another process may have stored
            // a pause or a resume since, and saving from a stale copy would
            // undo it.
            $state  = $this->state($installId, $now, true);
            $hash   = hash('sha256', (string)$control['claim']);
            $nonces = isset($state['nonces']) && is_array($state['nonces']) ? $state['nonces'] : array();
            $seen   = isset($nonces[$claim['nonce']]) && is_array($nonces[$claim['nonce']])
                ? $nonces[$claim['nonce']] : null;

            // An identical resend is expected (the service repeats the claim
            // on every reply until it is lifted or expires). A different claim
            // carrying a nonce already used is a replay.
            if ($seen !== null && (!isset($seen['h']) || $seen['h'] !== $hash)) {
                return false;
            }
            // A pause lifted by a resume stays lifted. A copy of the service
            // that has not seen the resume yet may still send it; a new pause
            // carries a new nonce, so it still applies.
            if ($seen !== null && !empty($seen['r']) && $claim['kind'] === self::KIND_PAUSE) {
                return false;
            }

            if ($claim['kind'] === self::KIND_PAUSE) {
                $stored = isset($state['control']['claim']) ? (string)$state['control']['claim'] : null;
                if ($stored !== (string)$control['claim']) {
                    $state['since'] = $now;
                }
                $state['control'] = array(
                    'claim' => (string)$control['claim'],
                    'sig'   => (string)$control['sig'],
                    'kid'   => (string)$control['kid'],
                );
            } else {
                $lifted = self::storedNonce($state);
                if ($lifted !== null && isset($nonces[$lifted]) && is_array($nonces[$lifted])) {
                    $nonces[$lifted]['r'] = 1;
                }
                $state['control'] = null;
                $state['since']   = null;
            }
            $nonces[$claim['nonce']] = array('h' => $hash, 'exp' => $claim['exp']);

            $state['nonces'] = self::pruneNonces($nonces, $now);
            $this->save($installId, $state, $now);

            return true;
        } catch (Exception $e) {
            return false;
        } catch (Throwable $t) {
            return false;
        }
    }

    /**
     * Ask the QoS service for this installation's status, at most once an
     * hour per key, and again as soon as the extension or library version
     * changes.
     *
     * Always sent: install_id, account_id, dc, module_version, lib_version and
     * the service status report (svc_state, svc_ref, svc_code, svc_since).
     * Sent only when the caller passes them, which Mailchimp_Telemetry does
     * only while reports are on: domain, first_payment, member_since.
     *
     * The slot is taken before the request goes out, so processes that end at
     * the same moment do not all ask. When it cannot be taken (no writable
     * state file) nothing is asked: a check on every process would be worse
     * than none.
     *
     * @param  array    $identity install_id, account_id, dc, module_version, lib_version,
     *                            and optionally domain, first_payment, member_since
     * @param  bool     $cli
     * @param  int|null $now
     * @return void
     */
    public function check($identity, $cli, $now = null)
    {
        if (!self::supported() || !is_array($identity) || empty($identity['install_id'])) {
            return;
        }
        if (!function_exists('curl_init')) {
            return;
        }
        $now       = ($now === null) ? time() : (int)$now;
        $installId = (string)$identity['install_id'];

        try {
            $versions = (isset($identity['module_version']) ? (string)$identity['module_version'] : '')
                . '|' . (isset($identity['lib_version']) ? (string)$identity['lib_version'] : '');

            $state   = $this->state($installId, $now, true);
            $checked = isset($state['checked_at']) ? (int)$state['checked_at'] : 0;
            // A time ahead of the clock (a clock that jumped back, a bad file)
            // would otherwise stop the check for good.
            if ($checked > $now + self::MAX_FUTURE_IAT) {
                $checked = 0;
            }
            $sameVersions = isset($state['versions']) && $state['versions'] === $versions;
            if ($checked && $sameVersions && $now - $checked < self::CHECK_INTERVAL) {
                return;
            }
            $state['checked_at'] = $now;
            $state['versions']   = $versions;
            if (!$this->save($installId, $state, $now)) {
                return;
            }

            if (empty($identity['account_id'])) {
                $identity['account_id'] = $this->storedAccountId($installId, $now);
            }

            $body = array('install_id' => $installId);
            foreach (array('account_id', 'dc', 'module_version', 'lib_version') as $field) {
                if (isset($identity[$field]) && $identity[$field] !== '') {
                    $body[$field] = (string)$identity[$field];
                }
            }
            foreach (self::report($this->get($installId, $now)) as $field => $value) {
                $body[$field] = $value;
            }
            if (array_key_exists('domain', $identity)) {
                $domain = is_string($identity['domain']) ? substr($identity['domain'], 0, self::MAX_DOMAIN) : '';
                $body['domain'] = ($domain !== '') ? $domain : null;
            }
            foreach (array('first_payment', 'member_since') as $field) {
                if (array_key_exists($field, $identity)) {
                    $body[$field] = self::isoUtc($identity[$field]);
                }
            }

            $reply = $this->post($this->_checkUrl, json_encode($body), $cli);
            if ($reply === null || $reply['status'] !== 200) {
                // 204: nothing applies, keep what is stored until it expires.
                // 429 / 503 / anything else: try again next hour.
                return;
            }

            $data = json_decode($reply['body'], true);
            if (is_array($data) && isset($data['control'])) {
                $this->apply($data['control'], $installId, $now);
            }
        } catch (Exception $e) {
            // Silent: see the class comment.
        } catch (Throwable $t) {
            // Same, for errors outside the Exception hierarchy.
        }
    }

    /**
     * The merchant-facing sentence for a pause.
     *
     * @param  array $status as returned by get()
     * @return string
     */
    public static function message($status)
    {
        $code = isset($status['code']) ? $status['code'] : 'BLOCK';

        if ($code === 'DEPRECATED') {
            $what = 'This version is no longer supported.';
        } elseif ($code === 'UPGRADE') {
            $what = 'This version needs to be updated.';
        } elseif ($code === 'NEW_TOKEN') {
            $what = 'This Mailchimp account needs a new API key.';
        } else {
            $what = 'Your data and settings are kept.';
        }

        $ref = !empty($status['ref']) ? ' Reference: ' . $status['ref'] . '.' : '';

        return 'Mailchimp sync is paused by the service. ' . $what . ' Email ' . self::CONTACT . '.' . $ref;
    }

    /**
     * A date as UTC `YYYY-MM-DDTHH:MM:SSZ`, or null when it cannot be read.
     *
     * @param  mixed $value
     * @return string|null
     */
    public static function isoUtc($value)
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value)) {
            return $value;
        }
        $time = strtotime($value);

        return ($time === false || $time <= 0) ? null : gmdate('Y-m-d\TH:i:s\Z', $time);
    }

    /**
     * Verify one control for one installation. Null when it does not verify.
     *
     * The signature is checked over the exact claim bytes that arrived; the
     * claim is never re-serialised. A control's signature is checked once per
     * process; the binding and time rules are applied on every call.
     *
     * @param  array  $control
     * @param  string $installId
     * @param  int    $now
     * @return array|null the claim
     */
    private function verify($control, $installId, $now)
    {
        if (!isset($control['claim'], $control['sig'], $control['kid'])
            || !is_string($control['claim']) || !is_string($control['sig']) || !is_string($control['kid'])
        ) {
            return null;
        }

        $memo = hash('sha256', $control['kid'] . '.' . $control['claim'] . '.' . $control['sig']);
        if (!isset($this->_verified[$memo])) {
            if (count($this->_verified) >= self::MAX_NONCES) {
                $this->_verified = array();
            }
            $this->_verified[$memo] = $this->verifySignature($control);
        }
        $claim = $this->_verified[$memo];
        if ($claim === false) {
            return null;
        }

        if (!self::sameString($claim['install_id'], (string)$installId)) {
            return null;
        }
        if ($claim['exp'] <= $now
            || $claim['exp'] <= $claim['iat']
            || $claim['exp'] - $claim['iat'] > self::MAX_LIFETIME
            || $claim['iat'] > $now + self::MAX_FUTURE_IAT
        ) {
            return null;
        }

        return $claim;
    }

    /**
     * The signature and the claim's shape, which do not change with time.
     *
     * @param  array $control
     * @return array|false the claim
     */
    private function verifySignature($control)
    {
        if (!isset($this->_keys[$control['kid']])) {
            return false;
        }

        $key   = self::base64UrlDecode((string)$this->_keys[$control['kid']]);
        $bytes = self::base64UrlDecode($control['claim']);
        $sig   = self::base64UrlDecode($control['sig']);
        if ($key === null || strlen($key) !== 32 || $bytes === null || $sig === null || strlen($sig) !== 64) {
            return false;
        }
        if (!sodium_crypto_sign_verify_detached($sig, $bytes, $key)) {
            return false;
        }

        $claim = json_decode($bytes, true);
        if (!is_array($claim) || !self::validClaim($claim)) {
            return false;
        }

        return $claim;
    }

    /**
     * The shape of a claim, field by field.
     *
     * @param  array $claim
     * @return bool
     */
    private static function validClaim($claim)
    {
        if (!isset($claim['v']) || $claim['v'] !== self::CLAIM_VERSION) {
            return false;
        }
        $kind = isset($claim['kind']) ? $claim['kind'] : null;
        if ($kind !== self::KIND_PAUSE && $kind !== self::KIND_RESUME) {
            return false;
        }
        if (!isset($claim['install_id']) || !is_string($claim['install_id'])
            || !preg_match('/\A[0-9a-f]{32}\z/', $claim['install_id'])
        ) {
            return false;
        }

        $code = isset($claim['code']) ? $claim['code'] : null;
        if (!is_string($code)) {
            return false;
        }
        if ($kind === self::KIND_PAUSE && !in_array($code, self::$CODES, true)) {
            return false;
        }
        if ($kind === self::KIND_RESUME && !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $code)) {
            return false;
        }

        if (!isset($claim['reason']) || !in_array($claim['reason'], self::$REASONS, true)) {
            return false;
        }
        if (!isset($claim['nonce']) || !is_string($claim['nonce'])
            || !preg_match('/\A[A-Za-z0-9_-]{16,128}\z/', $claim['nonce'])
        ) {
            return false;
        }
        if (!isset($claim['iat'], $claim['exp']) || !is_int($claim['iat']) || !is_int($claim['exp'])) {
            return false;
        }
        if (isset($claim['ref'])
            && (!is_string($claim['ref']) || !preg_match('/\A[a-z0-9]{1,32}\z/', $claim['ref']))
        ) {
            return false;
        }
        if (isset($claim['blocks_from']) && (!is_int($claim['blocks_from']) || $claim['blocks_from'] <= 0)) {
            return false;
        }
        if (isset($claim['account_id']) && !is_string($claim['account_id'])) {
            return false;
        }

        return true;
    }

    /**
     * The nonce of the pause currently stored, read from its claim bytes. Its
     * signature was checked when it was stored; this only finds which entry a
     * resume marks as lifted.
     *
     * @param  array $state
     * @return string|null
     */
    private static function storedNonce($state)
    {
        if (!isset($state['control']['claim']) || !is_string($state['control']['claim'])) {
            return null;
        }
        $bytes = self::base64UrlDecode($state['control']['claim']);
        $claim = ($bytes === null) ? null : json_decode($bytes, true);

        return (is_array($claim) && isset($claim['nonce']) && is_string($claim['nonce'])) ? $claim['nonce'] : null;
    }

    /**
     * Strict base64url: the URL-safe alphabet only, padding optional.
     *
     * @param  string $value
     * @return string|null
     */
    public static function base64UrlDecode($value)
    {
        if (!is_string($value) || $value === '' || !preg_match('/\A[A-Za-z0-9_-]+={0,2}\z/', $value)) {
            return null;
        }
        $standard = strtr(rtrim($value, '='), '-_', '+/');
        $padding  = strlen($standard) % 4;
        if ($padding === 1) {
            return null;
        }
        if ($padding > 0) {
            $standard .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($standard, true);

        return ($decoded === false) ? null : $decoded;
    }

    /**
     * @param  string $a
     * @param  string $b
     * @return bool
     */
    private static function sameString($a, $b)
    {
        if (function_exists('hash_equals')) {
            return hash_equals($b, $a);
        }

        return $a === $b;
    }

    /**
     * @param  array $nonces nonce => array(h, exp, r?)
     * @param  int   $now
     * @return array
     */
    private static function pruneNonces($nonces, $now)
    {
        $kept = array();
        foreach ($nonces as $nonce => $entry) {
            if (is_array($entry) && isset($entry['exp']) && (int)$entry['exp'] > $now) {
                $kept[$nonce] = $entry;
            }
        }
        if (count($kept) > self::MAX_NONCES) {
            $kept = array_slice($kept, -self::MAX_NONCES, null, true);
        }

        return $kept;
    }

    /**
     * The stored state for one installation, read at most once a minute
     * unless $fresh asks for the file now.
     *
     * Every file check is silenced: on a host whose open_basedir leaves the
     * temp directory out, each one raises a warning, and a host that turns
     * warnings into exceptions would otherwise lose the state this process
     * keeps. A file that cannot be read leaves what this process keeps as it
     * is; a file owned by another user is not read at all.
     *
     * @param  string $installId
     * @param  int    $now
     * @param  bool   $fresh
     * @return array
     */
    private function state($installId, $now, $fresh = false)
    {
        $cached = isset($this->_cache[$installId]) ? $this->_cache[$installId] : null;
        if (!$fresh && $cached !== null && $now - $cached['at'] < self::REREAD_SECONDS) {
            return $cached['state'];
        }

        $state = null;
        $path  = $this->path($installId);
        if ($path !== null && @is_file($path) && @is_readable($path) && self::ownFile($path)
            && @filesize($path) <= 16384
        ) {
            $raw = @file_get_contents($path);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $state   = is_array($decoded) ? $decoded : array();
            }
        }
        if ($state === null) {
            $state = ($cached !== null) ? $cached['state'] : array();
        }

        $this->_cache[$installId] = array('at' => $now, 'state' => $state);

        return $state;
    }

    /**
     * Whether the file belongs to the user this process runs as. In a shared
     * temp directory another user could create it first; this user could
     * then never replace it, and must not trust what it says.
     *
     * @param  string $path
     * @return bool
     */
    private static function ownFile($path)
    {
        if (!function_exists('posix_geteuid') || !function_exists('fileowner')) {
            return true;
        }
        $owner = @fileowner($path);

        return $owner !== false && (int)$owner === (int)posix_geteuid();
    }

    /**
     * Write the state: to a temporary file, then renamed over the old one.
     *
     * The process keeps the new state even when the write fails.
     *
     * @param  string $installId
     * @param  array  $state
     * @param  int    $now
     * @return bool whether it reached the file
     */
    private function save($installId, $state, $now)
    {
        $this->_cache[$installId] = array('at' => $now, 'state' => $state);

        $path = $this->path($installId);
        if ($path === null) {
            return false;
        }
        $json = json_encode($state);
        if (!is_string($json)) {
            return false;
        }

        $tmp = $path . '.' . md5(uniqid('', true)) . '.tmp';
        if (@file_put_contents($tmp, $json) !== strlen($json)) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        return true;
    }

    /**
     * One file per key and per system user: the web server and cron may run
     * as different users, and neither should depend on a file the other owns.
     *
     * @param  string $installId
     * @return string|null
     */
    private function path($installId)
    {
        if ($this->_dir === null || !preg_match('/\A[0-9a-f]{32}\z/', (string)$installId)) {
            return null;
        }
        $user = function_exists('posix_geteuid') ? (string)posix_geteuid() : 'u';

        return $this->_dir . DIRECTORY_SEPARATOR . 'mc4magento-status-' . $user . '-' . $installId . '.json';
    }

    /**
     * POST one body and read the reply, inside the same fences as the
     * telemetry. A fresh handle: the library's own carries the Mailchimp key.
     *
     * @param  string $url
     * @param  string $body
     * @param  bool   $cli
     * @return array|null status, body
     */
    private function post($url, $body, $cli)
    {
        $ch = curl_init();
        if (!$ch) {
            return null;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        if (defined('CURLOPT_NOSIGNAL')) {
            curl_setopt($ch, CURLOPT_NOSIGNAL, true);
        }
        if (defined('CURLOPT_CONNECTTIMEOUT_MS')) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $cli ? self::CONNECT_CLI_MS : self::CONNECT_WEB_MS);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, $cli ? self::TOTAL_CLI_MS : self::TOTAL_WEB_MS);
        } else {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, $cli ? 3 : 1);
        }

        $reply  = curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }

        if ($errno !== 0 || !is_string($reply) || strlen($reply) > self::MAX_REPLY_BYTES) {
            return null;
        }

        return array('status' => $status, 'body' => $reply);
    }
}
